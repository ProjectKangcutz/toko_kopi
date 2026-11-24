<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\ShipmentHistory;
use App\Models\ShippingCourier;
use App\Models\ShippingService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Security\AuditLogService;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OrderCreationService
{
    public function __construct(
        protected CartService $cartService,
        protected ShippingRateService $shippingRateService,
        protected StoreSettingService $settingService,
        protected InventoryReservationService $inventoryReservationService,
    ) {}

    public static function scopeIdempotencyKey(int $customerId, string $rawKey): string
    {
        return hash('sha256', 'customer:'.$customerId.'|key:'.trim($rawKey));
    }

    /**
     * Membuat pesanan dari keranjang aktif milik pelanggan secara atomik,
     * dengan validasi ongkos kirim di luar transaksi DB, penguncian deterministik,
     * reservasi stok, dan dukungan idempotency replay.
     *
     * @param  array{
     *     address_id: int|string,
     *     courier_code: string,
     *     service_code: string,
     *     idempotency_key: string,
     *     customer_notes?: ?string
     * }  $payload
     * @return array{order: Order, idempotent_replay: bool}
     */
    public function createFromCheckout(Customer $customer, array $payload): array
    {
        if ($customer->status !== 'active') {
            throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
        }

        $this->assertStoreOperational();

        $addressId = (int) $payload['address_id'];
        $courierCode = strtolower(trim((string) $payload['courier_code']));
        $serviceCode = strtoupper(trim((string) $payload['service_code']));
        $rawIdempotencyKey = trim((string) $payload['idempotency_key']);
        $scopedIdempotencyKey = self::scopeIdempotencyKey($customer->id, $rawIdempotencyKey);
        $customerNotes = $this->normalizeNotes($payload['customer_notes'] ?? null);

        // Pastikan alamat dapat dilihat/dimiliki oleh customer sebelum memproses lebih lanjut.
        $preAddress = CustomerAddress::withTrashed()->whereKey($addressId)->first();
        if (! $preAddress) {
            throw CheckoutOrderException::invalidAddress('Alamat pengiriman tidak ditemukan atau sudah dihapus.');
        }

        Gate::forUser($customer)->authorize('view', $preAddress);

        if ($preAddress->trashed()) {
            throw CheckoutOrderException::invalidAddress('Alamat pengiriman yang dipilih sudah dihapus.');
        }

        // Cek cepat idempotency sebelum melakukan request eksternal ke RajaOngkir.
        $existingBeforeQuote = Order::query()
            ->with(['items', 'address', 'shipment', 'stockReservations'])
            ->where('checkout_idempotency_key', $scopedIdempotencyKey)
            ->first();

        if ($existingBeforeQuote !== null) {
            $currentCartItems = $this->loadCurrentCustomerCartItems($customer->id);
            $this->assertIdempotentPayloadMatches(
                $existingBeforeQuote,
                $preAddress,
                $courierCode,
                $serviceCode,
                $customerNotes,
                $currentCartItems
            );

            return [
                'order' => $existingBeforeQuote,
                'idempotent_replay' => true,
            ];
        }

        // Validasi keranjang pra-transaksi sebelum memanggil API RajaOngkir.
        $preCart = $this->cartService->getCart($customer);
        if (((int) ($preCart['valid_quantity'] ?? 0)) <= 0 || $preCart['items']->isEmpty()) {
            throw CheckoutOrderException::emptyCart();
        }

        if (! empty($preCart['has_unavailable_items'])) {
            $warning = (string) ($preCart['warnings'][0] ?? 'Sebagian item di keranjang tidak lagi tersedia.');
            throw CheckoutOrderException::unavailableCartItem($warning);
        }

        $preItemValue = round((float) ($preCart['subtotal'] ?? 0), 2);

        // Panggil ShippingRateService DI LUAR transaksi DB agar tidak menahan row lock saat HTTP request.
        $selectedRate = $this->shippingRateService->resolveSelectedRate(
            $customer,
            $preAddress,
            $courierCode,
            $serviceCode
        );

        try {
            return DB::transaction(function () use (
                $customer,
                $addressId,
                $courierCode,
                $serviceCode,
                $scopedIdempotencyKey,
                $customerNotes,
                $preItemValue,
                $selectedRate
            ): array {
                // 1. Kunci baris Customer untuk menserialisasikan request paralel dari pelanggan yang sama.
                /** @var Customer $lockedCustomer */
                $lockedCustomer = Customer::query()
                    ->whereKey($customer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedCustomer->status !== 'active') {
                    throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
                }

                // 2. Kunci CustomerAddress milik pelanggan.
                /** @var ?CustomerAddress $lockedAddress */
                $lockedAddress = CustomerAddress::query()
                    ->ownedBy($lockedCustomer)
                    ->active()
                    ->whereKey($addressId)
                    ->lockForUpdate()
                    ->first();

                // 3. Cek ulang idempotency setelah memegang lock Customer agar race condition aman.
                $existingInLock = Order::query()
                    ->with(['items', 'address', 'shipment', 'stockReservations'])
                    ->where('checkout_idempotency_key', $scopedIdempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingInLock !== null) {
                    if (! $lockedAddress) {
                        throw CheckoutOrderException::idempotencyConflict();
                    }

                    $currentLockedCart = $this->findActiveCustomerCartLocked($lockedCustomer->id);
                    $currentLockedItems = $currentLockedCart
                        ? CartItem::query()->where('cart_id', $currentLockedCart->id)->orderBy('id')->lockForUpdate()->get()
                        : collect();

                    $this->assertIdempotentPayloadMatches(
                        $existingInLock,
                        $lockedAddress,
                        $courierCode,
                        $serviceCode,
                        $customerNotes,
                        $currentLockedItems
                    );

                    return [
                        'order' => $existingInLock,
                        'idempotent_replay' => true,
                    ];
                }

                $this->assertStoreOperational();

                if (! $lockedAddress) {
                    throw CheckoutOrderException::invalidAddress('Alamat pengiriman tidak ditemukan atau sudah dihapus.');
                }

                // 4. Kunci keranjang aktif beserta item-itemnya dalam urutan deterministik.
                $lockedCart = $this->findActiveCustomerCartLocked($lockedCustomer->id);
                if (! $lockedCart) {
                    throw CheckoutOrderException::emptyCart();
                }

                /** @var Collection<int, CartItem> $cartItems */
                $cartItems = CartItem::query()
                    ->where('cart_id', $lockedCart->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($cartItems->isEmpty()) {
                    throw CheckoutOrderException::emptyCart();
                }

                // 5. Kunci seluruh Product dan ProductVariant dengan urutan ID menaik (bebas deadlock).
                $productIds = $cartItems->pluck('product_id')->unique()->sort()->values()->all();
                $variantIds = $cartItems->pluck('product_variant_id')->filter()->unique()->sort()->values()->all();

                /** @var Collection<int, Product> $products */
                $products = Product::query()
                    ->with(['images'])
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                /** @var Collection<int, ProductVariant> $variants */
                $variants = $variantIds !== []
                    ? ProductVariant::query()
                        ->whereIn('id', $variantIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id')
                    : collect();

                // 6. Hitung ulang harga live, berat aktual, dan validasi stok di bawah lock.
                $now = now();
                $preparedItems = [];
                $subtotal = 0.0;
                $actualWeightGrams = 0;

                foreach ($cartItems as $cartItem) {
                    /** @var ?Product $product */
                    $product = $products->get((int) $cartItem->product_id);
                    if (! $product || $product->trashed()) {
                        throw CheckoutOrderException::unavailableCartItem('Salah satu produk di keranjang sudah dihapus.');
                    }

                    if (! $product->is_active) {
                        throw CheckoutOrderException::unavailableCartItem("Produk {$product->name} sedang tidak aktif.");
                    }

                    $qty = max(1, (int) $cartItem->quantity);
                    $variant = null;

                    if ($product->has_variants) {
                        $variantId = $cartItem->product_variant_id ? (int) $cartItem->product_variant_id : null;
                        $variant = $variantId ? $variants->get($variantId) : null;

                        if (! $variant || $variant->trashed() || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active) {
                            throw CheckoutOrderException::unavailableCartItem(
                                "Varian untuk produk {$product->name} tidak lagi tersedia."
                            );
                        }

                        $availableStock = max(0, (int) $variant->stock - (int) $variant->reserved_stock);
                        if ($availableStock < $qty) {
                            throw CheckoutOrderException::insufficientStock(
                                "Stok {$product->name} ({$variant->name}) tidak mencukupi (tersisa {$availableStock} unit)."
                            );
                        }

                        $unitPrice = $variant->effectivePrice($product, $now);
                        $regularPrice = (float) $variant->price;
                        $isPromo = $variant->hasActivePromo($product, $now);
                        $costPrice = (float) ($variant->cost_price !== null ? $variant->cost_price : $product->cost_price);
                        $weightGrams = max(0, (int) $variant->weight_grams);
                        $sku = $variant->sku ?: $product->sku;
                        $variantName = $variant->name;

                        $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
                        $imagePath = ! empty($variant->image_path) ? $variant->image_path : $primaryImage?->image_path;
                    } else {
                        if ($cartItem->product_variant_id !== null) {
                            throw CheckoutOrderException::unavailableCartItem(
                                "Konfigurasi varian untuk {$product->name} tidak lagi valid."
                            );
                        }

                        $availableStock = max(0, (int) $product->stock - (int) $product->reserved_stock);
                        if ($availableStock < $qty) {
                            throw CheckoutOrderException::insufficientStock(
                                "Stok {$product->name} tidak mencukupi (tersisa {$availableStock} unit)."
                            );
                        }

                        $unitPrice = $product->effectivePrice($now);
                        $regularPrice = (float) $product->price;
                        $isPromo = $product->hasActivePromo($now);
                        $costPrice = (float) $product->cost_price;
                        $weightGrams = max(0, (int) $product->weight_grams);
                        $sku = $product->sku;
                        $variantName = null;

                        $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
                        $imagePath = $primaryImage?->image_path;
                    }

                    $lineTotal = round($unitPrice * $qty, 2);
                    $lineWeight = $weightGrams * $qty;

                    $subtotal += $lineTotal;
                    $actualWeightGrams += $lineWeight;

                    $preparedItems[] = [
                        'product' => $product,
                        'variant' => $variant,
                        'quantity' => $qty,
                        'product_name_snapshot' => (string) $product->name,
                        'variant_name_snapshot' => $variantName,
                        'product_image_snapshot' => $imagePath ? Str::limit((string) $imagePath, 500, '') : null,
                        'sku_snapshot' => Str::limit((string) $sku, 80, ''),
                        'regular_price_snapshot' => round($regularPrice, 2),
                        'unit_price_snapshot' => round($unitPrice, 2),
                        'is_promo_snapshot' => $isPromo,
                        'cost_price_snapshot' => round($costPrice, 2),
                        'weight_grams_snapshot' => $weightGrams,
                        'line_total' => $lineTotal,
                    ];
                }

                $subtotal = round($subtotal, 2);
                $billableWeightGrams = $this->shippingRateService->calculateBillableWeightGrams($actualWeightGrams);

                $minOrderAmount = max(0.0, (float) $this->settingService->get('store', 'min_order_amount', 0));
                if ($minOrderAmount > 0 && $subtotal < $minOrderAmount) {
                    throw CheckoutOrderException::belowMinimumOrder($minOrderAmount);
                }

                // 7. Re-verifikasi konsistensi seluruh parameter shipping quote di bawah lock.
                $this->assertLockedShippingQuoteMatches(
                    $lockedAddress,
                    $actualWeightGrams,
                    $billableWeightGrams,
                    $subtotal,
                    $preItemValue,
                    $courierCode,
                    $serviceCode,
                    $selectedRate
                );

                // 8. Hitung nominal final order di server.
                $discountAmount = 0.0;
                $taxableBase = max(0.0, $subtotal - $discountAmount);
                $taxPercentage = max(0.0, (float) $this->settingService->get('store', 'tax_percentage', 0));
                $taxAmount = $taxPercentage > 0 ? round($taxableBase * ($taxPercentage / 100), 2) : 0.0;
                $shippingCost = round((float) $selectedRate['cost'], 2);
                $paymentFee = 0.0;
                $grandTotal = round($taxableBase + $taxAmount + $shippingCost + $paymentFee, 2);

                $expiryMinutes = max(15, (int) $this->settingService->get('checkout', 'payment_expiry_minutes', 1440));
                $paymentDueAt = $now->copy()->addMinutes($expiryMinutes);

                // 9. Buat Order dan seluruh snapshot turunannya secara atomik.
                $order = Order::create([
                    'order_number' => $this->generateUniqueOrderNumber(),
                    'checkout_idempotency_key' => $scopedIdempotencyKey,
                    'customer_id' => $lockedCustomer->id,
                    'customer_name_snapshot' => (string) $lockedCustomer->name,
                    'customer_email_snapshot' => (string) $lockedCustomer->email,
                    'customer_phone_snapshot' => Str::limit((string) ($lockedCustomer->phone ?: $lockedAddress->recipient_phone), 30, ''),
                    'is_guest' => false,
                    'order_status' => 'pending',
                    'payment_status' => 'unpaid',
                    'shipment_status' => 'unfulfilled',
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'coupon_code_snapshot' => null,
                    'shipping_cost' => $shippingCost,
                    'tax_amount' => $taxAmount,
                    'payment_fee' => $paymentFee,
                    'grand_total' => $grandTotal,
                    'total_weight_grams' => $actualWeightGrams,
                    'customer_notes' => $customerNotes,
                    'payment_due_at' => $paymentDueAt,
                ]);

                foreach ($preparedItems as $itemData) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $itemData['product']->id,
                        'product_variant_id' => $itemData['variant']?->id,
                        'product_name_snapshot' => $itemData['product_name_snapshot'],
                        'variant_name_snapshot' => $itemData['variant_name_snapshot'],
                        'product_image_snapshot' => $itemData['product_image_snapshot'],
                        'sku_snapshot' => $itemData['sku_snapshot'],
                        'regular_price_snapshot' => $itemData['regular_price_snapshot'],
                        'unit_price_snapshot' => $itemData['unit_price_snapshot'],
                        'is_promo_snapshot' => $itemData['is_promo_snapshot'],
                        'cost_price_snapshot' => $itemData['cost_price_snapshot'],
                        'weight_grams_snapshot' => $itemData['weight_grams_snapshot'],
                        'quantity' => $itemData['quantity'],
                        'line_total' => $itemData['line_total'],
                    ]);
                }

                OrderAddress::create([
                    'order_id' => $order->id,
                    'recipient_name' => (string) $lockedAddress->recipient_name,
                    'recipient_phone' => (string) $lockedAddress->recipient_phone,
                    'address_line' => (string) $lockedAddress->address_line,
                    'province_id' => (string) $lockedAddress->province_id,
                    'province_name' => (string) $lockedAddress->province_name,
                    'city_id' => (string) $lockedAddress->city_id,
                    'city_name' => (string) $lockedAddress->city_name,
                    'district_id' => $lockedAddress->district_id,
                    'district_name' => $lockedAddress->district_name,
                    'subdistrict_id' => $lockedAddress->subdistrict_id,
                    'subdistrict_name' => $lockedAddress->subdistrict_name,
                    'postal_code' => (string) $lockedAddress->postal_code,
                ]);

                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'status_type' => 'order',
                    'from_status' => null,
                    'to_status' => 'pending',
                    'notes' => 'Pesanan berhasil dibuat melalui checkout pelanggan.',
                    'actor_type' => 'customer',
                    'actor_id' => $lockedCustomer->id,
                    'created_at' => $now,
                ]);

                $shipment = Shipment::create([
                    'order_id' => $order->id,
                    'courier_code' => Str::limit((string) $selectedRate['courier_code'], 30, ''),
                    'courier_name' => Str::limit((string) $selectedRate['courier_name'], 100, ''),
                    'service_code' => Str::limit((string) $selectedRate['service_code'], 40, ''),
                    'service_name' => Str::limit((string) $selectedRate['service_name'], 120, ''),
                    'etd' => $selectedRate['etd'] !== null && trim((string) $selectedRate['etd']) !== ''
                        ? Str::limit(trim((string) $selectedRate['etd']), 40, '')
                        : null,
                    'shipping_cost' => $shippingCost,
                    'weight_grams' => $billableWeightGrams,
                    'status' => 'unfulfilled',
                ]);

                ShipmentHistory::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => null,
                    'to_status' => 'unfulfilled',
                    'notes' => 'Pengiriman dibuat saat checkout.',
                    'updated_by' => null,
                    'created_at' => $now,
                ]);

                // 10. Reservasi stok atomik jika fitur reservasi stok aktif.
                $stockReservationEnabled = (bool) $this->settingService->get('checkout', 'stock_reservation_enabled', true);
                if ($stockReservationEnabled) {
                    $this->inventoryReservationService->reservePreparedItems(
                        $order,
                        $preparedItems,
                        $paymentDueAt,
                        $now
                    );
                }

                // 11. Kosongkan keranjang pelanggan dan catat audit log.
                CartItem::query()->where('cart_id', $lockedCart->id)->delete();
                $lockedCart->coupon_code = null;
                $lockedCart->save();

                StorefrontLayoutService::forgetRequestCartCount();

                AuditLogService::record(
                    'customer',
                    $lockedCustomer->id,
                    'order.created',
                    "Membuat pesanan {$order->order_number}",
                    $order,
                    [
                        'order_number' => $order->order_number,
                        'grand_total' => (float) $order->grand_total,
                        'total_weight_grams' => $actualWeightGrams,
                        'billable_weight_grams' => $billableWeightGrams,
                        'courier_code' => $selectedRate['courier_code'],
                        'service_code' => $selectedRate['service_code'],
                    ]
                );

                return [
                    'order' => $order->fresh(['items', 'address', 'shipment', 'stockReservations']),
                    'idempotent_replay' => false,
                ];
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Perlindungan terakhir tingkat database untuk race condition idempotency_key.
            $existingOrder = Order::query()
                ->with(['items', 'address', 'shipment', 'stockReservations'])
                ->where('checkout_idempotency_key', $scopedIdempotencyKey)
                ->first();

            if ($existingOrder === null) {
                throw $e;
            }

            $freshAddress = CustomerAddress::query()
                ->ownedBy($customer)
                ->active()
                ->whereKey($addressId)
                ->first();

            if (! $freshAddress) {
                throw CheckoutOrderException::idempotencyConflict();
            }

            $currentCartItems = $this->loadCurrentCustomerCartItems($customer->id);
            $this->assertIdempotentPayloadMatches(
                $existingOrder,
                $freshAddress,
                $courierCode,
                $serviceCode,
                $customerNotes,
                $currentCartItems
            );

            return [
                'order' => $existingOrder,
                'idempotent_replay' => true,
            ];
        }
    }

    protected function assertStoreOperational(): void
    {
        $storeStatus = strtolower(trim((string) $this->settingService->get('store', 'store_status', 'open')));
        $maintenanceMode = (bool) $this->settingService->get('store', 'maintenance_mode', false);

        if ($maintenanceMode || $storeStatus !== 'open') {
            throw CheckoutOrderException::storeClosed();
        }
    }

    protected function findActiveCustomerCartLocked(int $customerId): ?Cart
    {
        $carts = Cart::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($carts as $cart) {
            if ($cart->expires_at === null || ! $cart->expires_at->isPast()) {
                return $cart;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, CartItem>
     */
    protected function loadCurrentCustomerCartItems(int $customerId): Collection
    {
        $carts = Cart::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->get();

        $activeCart = null;
        foreach ($carts as $cart) {
            if ($cart->expires_at === null || ! $cart->expires_at->isPast()) {
                $activeCart = $cart;
                break;
            }
        }

        if (! $activeCart) {
            return collect();
        }

        return CartItem::query()
            ->where('cart_id', $activeCart->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $selectedRate
     */
    protected function assertLockedShippingQuoteMatches(
        CustomerAddress $lockedAddress,
        int $actualWeightGrams,
        int $billableWeightGrams,
        float $lockedSubtotal,
        float $preSubtotal,
        string $courierCode,
        string $serviceCode,
        array $selectedRate
    ): void {
        if (
            strtolower(trim((string) $lockedAddress->province_id)) === 'manual'
            || strtolower(trim((string) $lockedAddress->city_id)) === 'manual'
            || trim((string) ($lockedAddress->subdistrict_id ?? '')) === ''
        ) {
            throw CheckoutOrderException::quoteMismatch();
        }

        $currentOriginId = trim((string) ($this->settingService->rajaOngkirConfig()['origin_id'] ?? ''));
        if ($currentOriginId === '' || $currentOriginId !== (string) $selectedRate['origin_id']) {
            throw CheckoutOrderException::quoteMismatch();
        }

        if (trim((string) $lockedAddress->subdistrict_id) !== (string) $selectedRate['destination_id']) {
            throw CheckoutOrderException::quoteMismatch();
        }

        if ($actualWeightGrams !== (int) $selectedRate['actual_weight_grams']) {
            throw CheckoutOrderException::quoteMismatch();
        }

        if ($billableWeightGrams !== (int) $selectedRate['billable_weight_grams']) {
            throw CheckoutOrderException::quoteMismatch();
        }

        if (abs($lockedSubtotal - $preSubtotal) >= 0.01) {
            throw CheckoutOrderException::quoteMismatch();
        }

        if (
            strtolower((string) $selectedRate['courier_code']) !== $courierCode
            || strtoupper((string) $selectedRate['service_code']) !== $serviceCode
        ) {
            throw CheckoutOrderException::quoteMismatch();
        }

        /** @var ?ShippingCourier $activeCourier */
        $activeCourier = ShippingCourier::query()
            ->where('code', $courierCode)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $activeCourier) {
            throw CheckoutOrderException::quoteMismatch();
        }

        /** @var ?ShippingService $matchedService */
        $matchedService = ShippingService::query()
            ->where('courier_id', $activeCourier->id)
            ->where('service_code', $selectedRate['service_code'])
            ->lockForUpdate()
            ->first();

        if ($matchedService !== null && ! $matchedService->is_active) {
            throw CheckoutOrderException::quoteMismatch();
        }
    }

    /**
     * Memverifikasi kecocokan payload replay idempoten tanpa kolom checkout_payload_hash terpisah.
     *
     * @param  Collection<int, CartItem>  $currentCartItems
     */
    protected function assertIdempotentPayloadMatches(
        Order $existingOrder,
        CustomerAddress $address,
        string $courierCode,
        string $serviceCode,
        ?string $customerNotes,
        Collection $currentCartItems
    ): void {
        $orderAddress = $existingOrder->address;
        $shipment = $existingOrder->shipment;

        if (! $orderAddress || ! $shipment) {
            throw CheckoutOrderException::idempotencyConflict();
        }

        $addressMatches = (string) $orderAddress->recipient_name === (string) $address->recipient_name
            && (string) $orderAddress->recipient_phone === (string) $address->recipient_phone
            && (string) $orderAddress->address_line === (string) $address->address_line
            && (string) $orderAddress->province_id === (string) $address->province_id
            && (string) $orderAddress->city_id === (string) $address->city_id
            && (string) ($orderAddress->district_id ?? '') === (string) ($address->district_id ?? '')
            && (string) ($orderAddress->subdistrict_id ?? '') === (string) ($address->subdistrict_id ?? '')
            && (string) $orderAddress->postal_code === (string) $address->postal_code;

        $shippingMatches = strtolower((string) $shipment->courier_code) === $courierCode
            && strtoupper((string) $shipment->service_code) === $serviceCode;

        $notesMatches = $this->normalizeNotes($existingOrder->customer_notes) === $customerNotes;

        if (! $addressMatches || ! $shippingMatches || ! $notesMatches) {
            throw CheckoutOrderException::idempotencyConflict();
        }

        // Jika keranjang sudah terisi kembali, verifikasi komposisi itemnya identik dengan order_items.
        if ($currentCartItems->isNotEmpty()) {
            $cartSignature = $currentCartItems
                ->map(static fn (CartItem $item): string => implode(':', [
                    (int) $item->product_id,
                    (int) ($item->product_variant_id ?? 0),
                    (int) $item->quantity,
                ]))
                ->sort()
                ->values()
                ->all();

            $orderItemSignature = $existingOrder->items
                ->map(static fn (OrderItem $item): string => implode(':', [
                    (int) ($item->product_id ?? 0),
                    (int) ($item->product_variant_id ?? 0),
                    (int) $item->quantity,
                ]))
                ->sort()
                ->values()
                ->all();

            if ($cartSignature !== $orderItemSignature) {
                throw CheckoutOrderException::idempotencyConflict();
            }
        }
    }

    protected function normalizeNotes(mixed $notes): ?string
    {
        if (! is_string($notes)) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function generateUniqueOrderNumber(): string
    {
        $rawPrefix = trim((string) $this->settingService->get('general', 'order_number_prefix', 'PLN-'));
        $prefix = $rawPrefix !== '' ? Str::limit($rawPrefix, 12, '') : 'PLN-';
        $datePart = now()->format('Ymd');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $randomPart = strtoupper(Str::random(6));
            $candidate = Str::limit("{$prefix}{$datePart}-{$randomPart}", 50, '');

            if (! Order::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return Str::limit("{$prefix}{$datePart}-".strtoupper(Str::random(10)), 50, '');
    }
}
