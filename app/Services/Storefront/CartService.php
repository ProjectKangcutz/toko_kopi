<?php

namespace App\Services\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CartService
{
    public const GUEST_SESSION_KEY = 'storefront_guest_cart';

    public const CUSTOMER_CART_TTL_DAYS = 30;

    public const MAX_ITEM_QUANTITY = 9999;

    public static function buildItemKey(int $productId, ?int $variantId = null): string
    {
        $normalizedVariantId = ($variantId !== null && $variantId > 0) ? $variantId : 0;

        return $productId.'-'.$normalizedVariantId;
    }

    public function currentCustomer(): ?Customer
    {
        $user = Auth::guard('customer')->user();

        if ($user instanceof Customer && $user->status === 'active') {
            return $user;
        }

        return null;
    }

    protected function resolveCustomerIdentity(?Customer $customer = null): ?Customer
    {
        $actor = $customer ?? Auth::guard('customer')->user();

        return $actor instanceof Customer ? $actor : null;
    }

    protected function resolveActiveCustomerOrFailIfInactive(?Customer $customer = null): ?Customer
    {
        $actor = $this->resolveCustomerIdentity($customer);

        if ($actor === null) {
            return null;
        }

        if ($actor->status !== 'active') {
            throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
        }

        return $actor;
    }

    /**
     * Membaca dan menormalisasi isi guest cart dari Laravel session secara aman.
     *
     * @return array<string, array{product_id: int, product_variant_id: ?int, quantity: int}>
     */
    public function readGuestSessionItems(): array
    {
        try {
            if (! app()->bound('session')) {
                return [];
            }

            $raw = session()->get(self::GUEST_SESSION_KEY, []);

            return $this->normalizeGuestSessionItems($raw);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, array{product_id: int, product_variant_id: ?int, quantity: int}>
     */
    public function normalizeGuestSessionItems(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $productId = filter_var($entry['product_id'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId <= 0) {
                continue;
            }

            $rawVariant = $entry['product_variant_id'] ?? null;
            $variantId = null;

            if ($rawVariant !== null && $rawVariant !== '' && $rawVariant !== 0 && $rawVariant !== '0') {
                $validatedVariant = filter_var($rawVariant, FILTER_VALIDATE_INT);
                if ($validatedVariant === false || $validatedVariant <= 0) {
                    continue;
                }
                $variantId = (int) $validatedVariant;
            }

            $quantity = filter_var($entry['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity <= 0) {
                continue;
            }

            $key = self::buildItemKey((int) $productId, $variantId);

            if (isset($normalized[$key])) {
                $normalized[$key]['quantity'] = min(
                    self::MAX_ITEM_QUANTITY,
                    $normalized[$key]['quantity'] + (int) $quantity
                );
            } else {
                $normalized[$key] = [
                    'product_id' => (int) $productId,
                    'product_variant_id' => $variantId,
                    'quantity' => min(self::MAX_ITEM_QUANTITY, (int) $quantity),
                ];
            }
        }

        return $normalized;
    }

    public function getCart(?Customer $customer = null): array
    {
        $actor = $this->resolveCustomerIdentity($customer);

        if ($actor !== null) {
            if ($actor->status !== 'active') {
                return $this->emptyCartResponse('customer', null);
            }

            return $this->buildCustomerCartData($actor);
        }

        return $this->buildGuestCartData();
    }

    public function addItem(int $productId, ?int $variantId = null, int $quantity = 1, ?Customer $customer = null): array
    {
        $this->assertValidQuantity($quantity);
        $normalizedVariantId = ($variantId !== null && $variantId > 0) ? $variantId : null;
        $actor = $this->resolveActiveCustomerOrFailIfInactive($customer);

        if ($actor !== null) {
            return DB::transaction(function () use ($actor, $productId, $normalizedVariantId, $quantity) {
                ['product' => $product, 'variant' => $variant, 'available_stock' => $availableStock] = $this->validateProductAndVariant(
                    $productId,
                    $normalizedVariantId,
                    lockRows: true
                );

                $cart = $this->getOrCreateCustomerCartLocked($actor->id);
                $existingItem = $this->findCustomerCartItemLocked($cart->id, $product->id, $variant?->id);

                $existingQuantity = $existingItem ? (int) $existingItem->quantity : 0;
                $newQuantity = $existingQuantity + $quantity;

                if ($newQuantity > $availableStock) {
                    $message = $existingQuantity > 0
                        ? "Stok tersedia hanya {$availableStock} unit (sudah ada {$existingQuantity} di keranjang Anda)."
                        : "Stok tersedia hanya {$availableStock} unit.";

                    throw ValidationException::withMessages(['quantity' => $message]);
                }

                if ($existingItem) {
                    $existingItem->quantity = $newQuantity;
                    $existingItem->save();
                    $cartItem = $existingItem;
                } else {
                    $cartItem = CartItem::create([
                        'cart_id' => $cart->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'quantity' => $newQuantity,
                    ]);
                }

                $cart->expires_at = now()->addDays(self::CUSTOMER_CART_TTL_DAYS);
                $cart->save();

                StorefrontLayoutService::forgetRequestCartCount();

                return $this->formatMutationResult($cartItem->id, $product, $variant, $newQuantity, $actor);
            });
        }

        ['product' => $product, 'variant' => $variant, 'available_stock' => $availableStock] = $this->validateProductAndVariant(
            $productId,
            $normalizedVariantId,
            lockRows: false
        );

        $items = $this->readGuestSessionItems();
        $key = self::buildItemKey($product->id, $variant?->id);
        $existingQuantity = (int) ($items[$key]['quantity'] ?? 0);
        $newQuantity = $existingQuantity + $quantity;

        if ($newQuantity > $availableStock) {
            $message = $existingQuantity > 0
                ? "Stok tersedia hanya {$availableStock} unit (sudah ada {$existingQuantity} di keranjang Anda)."
                : "Stok tersedia hanya {$availableStock} unit.";

            throw ValidationException::withMessages(['quantity' => $message]);
        }

        $items[$key] = [
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $newQuantity,
        ];

        $this->writeGuestSessionItems($items);
        StorefrontLayoutService::forgetRequestCartCount();

        return $this->formatMutationResult($key, $product, $variant, $newQuantity, null);
    }

    public function updateItem(int|string $itemIdentifier, int $quantity, ?Customer $customer = null): array
    {
        $this->assertValidQuantity($quantity);
        $actor = $this->resolveActiveCustomerOrFailIfInactive($customer);

        if ($actor !== null) {
            $itemId = filter_var($itemIdentifier, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId <= 0) {
                throw ValidationException::withMessages(['item' => 'Item keranjang tidak ditemukan.']);
            }

            return DB::transaction(function () use ($actor, $itemId, $quantity) {
                $cartItem = CartItem::query()
                    ->with('cart')
                    ->whereKey((int) $itemId)
                    ->lockForUpdate()
                    ->first();

                if (! $cartItem) {
                    throw ValidationException::withMessages(['item' => 'Item keranjang tidak ditemukan.']);
                }

                if (! $cartItem->cart || $cartItem->cart->customer_id === null || (int) $cartItem->cart->customer_id !== (int) $actor->id) {
                    throw new AuthorizationException('Anda tidak memiliki izin mengakses item keranjang ini.');
                }

                if ($this->isCartExpired($cartItem->cart)) {
                    throw ValidationException::withMessages(['item' => 'Keranjang belanja Anda telah kedaluwarsa.']);
                }

                ['product' => $product, 'variant' => $variant, 'available_stock' => $availableStock] = $this->validateProductAndVariant(
                    (int) $cartItem->product_id,
                    $cartItem->product_variant_id ? (int) $cartItem->product_variant_id : null,
                    lockRows: true
                );

                if ($quantity > $availableStock) {
                    throw ValidationException::withMessages([
                        'quantity' => "Stok tersedia hanya {$availableStock} unit.",
                    ]);
                }

                $cartItem->quantity = $quantity;
                $cartItem->save();

                $cartItem->cart->expires_at = now()->addDays(self::CUSTOMER_CART_TTL_DAYS);
                $cartItem->cart->save();

                StorefrontLayoutService::forgetRequestCartCount();

                return $this->formatMutationResult($cartItem->id, $product, $variant, $quantity, $actor);
            });
        }

        $items = $this->readGuestSessionItems();
        $key = (string) $itemIdentifier;

        if (! isset($items[$key])) {
            throw ValidationException::withMessages(['item' => 'Item keranjang tidak ditemukan.']);
        }

        $entry = $items[$key];

        ['product' => $product, 'variant' => $variant, 'available_stock' => $availableStock] = $this->validateProductAndVariant(
            $entry['product_id'],
            $entry['product_variant_id'],
            lockRows: false
        );

        if ($quantity > $availableStock) {
            throw ValidationException::withMessages([
                'quantity' => "Stok tersedia hanya {$availableStock} unit.",
            ]);
        }

        $items[$key]['quantity'] = $quantity;
        $this->writeGuestSessionItems($items);
        StorefrontLayoutService::forgetRequestCartCount();

        return $this->formatMutationResult($key, $product, $variant, $quantity, null);
    }

    public function removeItem(int|string $itemIdentifier, ?Customer $customer = null): bool
    {
        $actor = $this->resolveActiveCustomerOrFailIfInactive($customer);

        if ($actor !== null) {
            $itemId = filter_var($itemIdentifier, FILTER_VALIDATE_INT);
            if ($itemId === false || $itemId <= 0) {
                return false;
            }

            return DB::transaction(function () use ($actor, $itemId) {
                $cartItem = CartItem::query()
                    ->with('cart')
                    ->whereKey((int) $itemId)
                    ->lockForUpdate()
                    ->first();

                if (! $cartItem) {
                    return false;
                }

                if (! $cartItem->cart || $cartItem->cart->customer_id === null || (int) $cartItem->cart->customer_id !== (int) $actor->id) {
                    throw new AuthorizationException('Anda tidak memiliki izin menghapus item keranjang ini.');
                }

                $cart = $cartItem->cart;
                $cartItem->delete();

                $cart->expires_at = now()->addDays(self::CUSTOMER_CART_TTL_DAYS);
                $cart->save();

                StorefrontLayoutService::forgetRequestCartCount();

                return true;
            });
        }

        $items = $this->readGuestSessionItems();
        $key = (string) $itemIdentifier;

        if (! isset($items[$key])) {
            return false;
        }

        unset($items[$key]);
        $this->writeGuestSessionItems($items);
        StorefrontLayoutService::forgetRequestCartCount();

        return true;
    }

    public function clearCart(?Customer $customer = null): void
    {
        $actor = $this->resolveActiveCustomerOrFailIfInactive($customer);

        if ($actor !== null) {
            DB::transaction(function () use ($actor) {
                $cart = $this->findActiveCustomerCart($actor->id, forUpdate: true);
                if ($cart) {
                    CartItem::where('cart_id', $cart->id)->delete();
                    $cart->coupon_code = null;
                    $cart->save();
                }
            });

            StorefrontLayoutService::forgetRequestCartCount();

            return;
        }

        $this->clearGuestSession();
        StorefrontLayoutService::forgetRequestCartCount();
    }

    public function getHeaderItemCount(?Customer $customer = null): int
    {
        try {
            $actor = $this->resolveCustomerIdentity($customer);

            if ($actor !== null) {
                if ($actor->status !== 'active') {
                    return 0;
                }

                $cart = $this->findActiveCustomerCart($actor->id, forUpdate: false);
                if (! $cart) {
                    return 0;
                }

                $entries = CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->get(['product_id', 'product_variant_id', 'quantity'])
                    ->map(fn (CartItem $item) => [
                        'product_id' => (int) $item->product_id,
                        'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                        'quantity' => max(1, (int) $item->quantity),
                    ])
                    ->all();

                return $this->countValidEntries($entries);
            }

            $items = $this->readGuestSessionItems();
            if ($items === []) {
                return 0;
            }

            return $this->countValidEntries(array_values($items));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Menghitung valid_quantity secara identik dengan aturan ketersediaan pada halaman keranjang.
     *
     * @param  array<int, array{product_id: int, product_variant_id: ?int, quantity: int}>  $entries
     */
    protected function countValidEntries(array $entries): int
    {
        if ($entries === []) {
            return 0;
        }

        $productIds = collect($entries)->pluck('product_id')->unique()->values();
        $variantIds = collect($entries)->pluck('product_variant_id')->filter()->unique()->values();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'is_active', 'has_variants', 'stock', 'reserved_stock'])
            ->keyBy('id');

        $variants = $variantIds->isNotEmpty()
            ? ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->get(['id', 'product_id', 'name', 'is_active', 'stock', 'reserved_stock'])
                ->keyBy('id')
            : collect();

        $validTotal = 0;

        foreach ($entries as $entry) {
            /** @var ?Product $product */
            $product = $products->get($entry['product_id']);
            $variantId = $entry['product_variant_id'];
            /** @var ?ProductVariant $variant */
            $variant = $variantId ? $variants->get($variantId) : null;
            $quantity = max(1, (int) $entry['quantity']);

            if ($this->resolveUnavailableReason($product, $variant, $variantId, $quantity) === null) {
                $validTotal += $quantity;
            }
        }

        return $validTotal;
    }

    /**
     * Menggabungkan guest cart ke database customer cart secara idempoten dan aman.
     *
     * @return array{merged_count: int, skipped_count: int, adjusted_count: int, warnings: array<int, string>}
     */
    public function mergeGuestCartToCustomer(Customer $customer, ?array $guestSnapshot = null): array
    {
        if ($customer->status !== 'active') {
            throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
        }

        $guestItems = $guestSnapshot !== null
            ? $this->normalizeGuestSessionItems($guestSnapshot)
            : $this->readGuestSessionItems();

        if ($guestItems === []) {
            $this->clearGuestSession();
            StorefrontLayoutService::forgetRequestCartCount();

            return [
                'merged_count' => 0,
                'skipped_count' => 0,
                'adjusted_count' => 0,
                'warnings' => [],
            ];
        }

        $result = DB::transaction(function () use ($customer, $guestItems) {
            $cart = $this->getOrCreateCustomerCartLocked($customer->id);

            $productIds = collect($guestItems)->pluck('product_id')->unique()->values();
            $variantIds = collect($guestItems)->pluck('product_variant_id')->filter()->unique()->values();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variants = $variantIds->isNotEmpty()
                ? ProductVariant::query()
                    ->whereIn('id', $variantIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id')
                : collect();

            $mergedCount = 0;
            $skippedCount = 0;
            $adjustedCount = 0;
            $warnings = [];

            foreach ($guestItems as $entry) {
                /** @var ?Product $product */
                $product = $products->get($entry['product_id']);
                $productLabel = $product?->name ?? ('Produk #'.$entry['product_id']);

                if (! $product || ! $product->is_active) {
                    $skippedCount++;
                    $warnings[] = "{$productLabel} tidak lagi tersedia dan dilewati dari keranjang.";
                    continue;
                }

                $variant = null;
                if ($product->has_variants) {
                    $variantId = $entry['product_variant_id'];
                    $variant = $variantId ? $variants->get($variantId) : null;

                    if (! $variant || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active) {
                        $skippedCount++;
                        $warnings[] = "Varian {$product->name} tidak lagi tersedia dan dilewati dari keranjang.";
                        continue;
                    }

                    $availableStock = $variant->availableStock();
                } else {
                    if ($entry['product_variant_id'] !== null) {
                        $skippedCount++;
                        $warnings[] = "{$product->name} tidak lagi mendukung varian yang dipilih.";
                        continue;
                    }

                    $availableStock = max(0, (int) $product->stock - (int) $product->reserved_stock);
                }

                if ($availableStock <= 0) {
                    $skippedCount++;
                    $warnings[] = "Stok {$product->name} sedang habis sehingga tidak digabungkan.";
                    continue;
                }

                $existingItem = $this->findCustomerCartItemLocked($cart->id, $product->id, $variant?->id);
                $existingQty = $existingItem ? (int) $existingItem->quantity : 0;
                $requestedTotal = $existingQty + (int) $entry['quantity'];
                $finalQty = min($requestedTotal, $availableStock, self::MAX_ITEM_QUANTITY);

                if ($finalQty <= 0) {
                    $skippedCount++;
                    continue;
                }

                if ($finalQty < $requestedTotal) {
                    $adjustedCount++;
                    $warnings[] = "Jumlah {$product->name} disesuaikan ke stok tersedia ({$availableStock} unit).";
                }

                if ($existingItem) {
                    if ((int) $existingItem->quantity !== $finalQty) {
                        $existingItem->quantity = $finalQty;
                        $existingItem->save();
                    }
                } else {
                    CartItem::create([
                        'cart_id' => $cart->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'quantity' => $finalQty,
                    ]);
                }

                $mergedCount++;
            }

            $cart->expires_at = now()->addDays(self::CUSTOMER_CART_TTL_DAYS);
            $cart->save();

            return [
                'merged_count' => $mergedCount,
                'skipped_count' => $skippedCount,
                'adjusted_count' => $adjustedCount,
                'warnings' => $warnings,
            ];
        });

        // Guest cart hanya dihapus setelah transaksi merge DB berhasil commit.
        $this->clearGuestSession();
        StorefrontLayoutService::forgetRequestCartCount();

        return $result;
    }

    protected function assertValidQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Kuantitas minimal 1 unit.',
            ]);
        }

        if ($quantity > self::MAX_ITEM_QUANTITY) {
            throw ValidationException::withMessages([
                'quantity' => 'Kuantitas melebihi batas maksimal pembelian per item.',
            ]);
        }
    }

    /**
     * @return array{product: Product, variant: ?ProductVariant, available_stock: int}
     */
    protected function validateProductAndVariant(int $productId, ?int $variantId, bool $lockRows): array
    {
        $productQuery = Product::query()->whereKey($productId);
        if ($lockRows) {
            $productQuery->lockForUpdate();
        }
        $product = $productQuery->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk tidak ditemukan atau sudah dihapus.',
            ]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'Produk sedang tidak aktif dan tidak dapat dibeli.',
            ]);
        }

        $variant = null;

        if ($product->has_variants) {
            if ($variantId === null || $variantId <= 0) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Silakan pilih varian produk terlebih dahulu.',
                ]);
            }

            $variantQuery = ProductVariant::query()->whereKey($variantId);
            if ($lockRows) {
                $variantQuery->lockForUpdate();
            }
            $variant = $variantQuery->first();

            if (! $variant || (int) $variant->product_id !== (int) $product->id) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Varian tidak valid untuk produk yang dipilih.',
                ]);
            }

            if (! $variant->is_active) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Varian produk sedang tidak aktif.',
                ]);
            }

            $availableStock = $variant->availableStock();
        } else {
            if ($variantId !== null && $variantId > 0) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'Produk ini tidak memiliki varian.',
                ]);
            }

            $availableStock = max(0, (int) $product->stock - (int) $product->reserved_stock);
        }

        if ($availableStock <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Stok produk sedang habis.',
            ]);
        }

        return [
            'product' => $product,
            'variant' => $variant,
            'available_stock' => $availableStock,
        ];
    }

    protected function findActiveCustomerCart(int $customerId, bool $forUpdate = false): ?Cart
    {
        $query = Cart::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $carts = $query->get();

        foreach ($carts as $cart) {
            if (! $this->isCartExpired($cart)) {
                return $cart;
            }
        }

        return null;
    }

    protected function getOrCreateCustomerCartLocked(int $customerId): Cart
    {
        // Kunci baris customer untuk mencegah pembuatan cart ganda saat request bersamaan.
        Customer::query()->whereKey($customerId)->lockForUpdate()->first();

        $activeCart = $this->findActiveCustomerCart($customerId, forUpdate: true);
        if ($activeCart) {
            return $activeCart;
        }

        return Cart::create([
            'customer_id' => $customerId,
            'expires_at' => now()->addDays(self::CUSTOMER_CART_TTL_DAYS),
        ]);
    }

    protected function findCustomerCartItemLocked(int $cartId, int $productId, ?int $variantId): ?CartItem
    {
        $query = CartItem::query()
            ->where('cart_id', $cartId)
            ->where('product_id', $productId)
            ->lockForUpdate();

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            // MySQL UNIQUE(cart_id, product_id, product_variant_id) tidak mencegah duplikat NULL.
            $query->whereNull('product_variant_id');
        }

        return $query->first();
    }

    protected function isCartExpired(Cart $cart): bool
    {
        return $cart->expires_at !== null && $cart->expires_at->isPast();
    }

    protected function buildCustomerCartData(Customer $customer): array
    {
        $cart = $this->findActiveCustomerCart($customer->id, forUpdate: false);
        if (! $cart) {
            return $this->emptyCartResponse('customer', null);
        }

        $cartItems = CartItem::query()
            ->where('cart_id', $cart->id)
            ->orderBy('id')
            ->get();

        if ($cartItems->isEmpty()) {
            return $this->emptyCartResponse('customer', $cart->id);
        }

        $rawEntries = $cartItems->map(fn (CartItem $item) => [
            'identifier' => $item->id,
            'cart_item_id' => $item->id,
            'product_id' => (int) $item->product_id,
            'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
            'quantity' => (int) $item->quantity,
        ])->all();

        return $this->hydrateCartItems('customer', $cart->id, $rawEntries);
    }

    protected function buildGuestCartData(): array
    {
        $sessionItems = $this->readGuestSessionItems();
        if ($sessionItems === []) {
            return $this->emptyCartResponse('guest', null);
        }

        $rawEntries = [];
        foreach ($sessionItems as $key => $entry) {
            $rawEntries[] = [
                'identifier' => $key,
                'cart_item_id' => null,
                'product_id' => $entry['product_id'],
                'product_variant_id' => $entry['product_variant_id'],
                'quantity' => $entry['quantity'],
            ];
        }

        return $this->hydrateCartItems('guest', null, $rawEntries);
    }

    /**
     * Menghidrasi item keranjang langsung dari database (harga, promo, stok, dan berat selalu live).
     *
     * @param  array<int, array{identifier: int|string, cart_item_id: ?int, product_id: int, product_variant_id: ?int, quantity: int}>  $rawEntries
     */
    protected function hydrateCartItems(string $mode, ?int $cartId, array $rawEntries): array
    {
        $now = now();
        $productIds = collect($rawEntries)->pluck('product_id')->unique()->values();
        $variantIds = collect($rawEntries)->pluck('product_variant_id')->filter()->unique()->values();

        $products = Product::query()
            ->with([
                'images',
                'categories' => fn ($q) => $q->where('categories.is_active', true)->orderBy('categories.sort_order'),
            ])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $variants = $variantIds->isNotEmpty()
            ? ProductVariant::query()
                ->with(['attributeValues.attribute'])
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id')
            : collect();

        $hydratedItems = new Collection();
        $subtotal = 0.0;
        $totalQuantity = 0;
        $validQuantity = 0;
        $totalWeightGrams = 0;
        $warnings = [];
        $hasUnavailableItems = false;

        foreach ($rawEntries as $entry) {
            /** @var ?Product $product */
            $product = $products->get($entry['product_id']);
            $variantId = $entry['product_variant_id'];
            /** @var ?ProductVariant $variant */
            $variant = $variantId ? $variants->get($variantId) : null;
            $quantity = max(1, (int) $entry['quantity']);

            $unavailableReason = $this->resolveUnavailableReason($product, $variant, $variantId, $quantity);
            $isAvailable = $unavailableReason === null;

            if (! $isAvailable) {
                $hasUnavailableItems = true;
                $warnings[] = $unavailableReason;
            }

            $unitPrice = 0.0;
            $regularPrice = 0.0;
            $isPromo = false;
            $availableStock = 0;
            $weightGrams = 0;
            $imagePath = null;
            $variantLabel = null;
            $sku = $product?->sku;

            if ($product) {
                $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
                $imagePath = $primaryImage?->image_path;

                if ($product->has_variants && $variant && (int) $variant->product_id === (int) $product->id) {
                    $unitPrice = $variant->effectivePrice($product, $now);
                    $regularPrice = (float) $variant->price;
                    $isPromo = $variant->hasActivePromo($product, $now);
                    $availableStock = $variant->availableStock();
                    $weightGrams = max(0, (int) $variant->weight_grams);
                    $sku = $variant->sku ?: $product->sku;
                    $variantLabel = $variant->name;

                    if (! empty($variant->image_path)) {
                        $imagePath = $variant->image_path;
                    }
                } elseif (! $product->has_variants) {
                    $unitPrice = $product->effectivePrice($now);
                    $regularPrice = (float) $product->price;
                    $isPromo = $product->hasActivePromo($now);
                    $availableStock = max(0, (int) $product->stock - (int) $product->reserved_stock);
                    $weightGrams = max(0, (int) $product->weight_grams);
                }
            }

            $lineSubtotal = $isAvailable ? round($unitPrice * $quantity, 2) : 0.0;
            $lineWeight = $isAvailable ? ($weightGrams * $quantity) : 0;

            $totalQuantity += $quantity;
            if ($isAvailable) {
                $validQuantity += $quantity;
                $subtotal += $lineSubtotal;
                $totalWeightGrams += $lineWeight;
            }

            $hydratedItems->push([
                'identifier' => $entry['identifier'],
                'cart_item_id' => $entry['cart_item_id'],
                'key' => self::buildItemKey($entry['product_id'], $variantId),
                'product_id' => $entry['product_id'],
                'product_variant_id' => $variantId,
                'product' => $product,
                'variant' => $variant,
                'name' => $product?->name ?? 'Produk tidak tersedia',
                'slug' => $product?->slug,
                'sku' => $sku,
                'variant_name' => $variantLabel,
                'image_path' => $imagePath,
                'image_url' => HomepageService::mediaUrl($imagePath),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'regular_price' => $regularPrice,
                'is_promo' => $isPromo,
                'available_stock' => $availableStock,
                'weight_grams' => $weightGrams,
                'line_subtotal' => $lineSubtotal,
                'line_weight_grams' => $lineWeight,
                'is_available' => $isAvailable,
                'unavailable_reason' => $unavailableReason,
            ]);
        }

        return [
            'mode' => $mode,
            'cart_id' => $cartId,
            'items' => $hydratedItems,
            'total_quantity' => $totalQuantity,
            'valid_quantity' => $validQuantity,
            'subtotal' => round($subtotal, 2),
            'total_weight_grams' => $totalWeightGrams,
            'has_unavailable_items' => $hasUnavailableItems,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    protected function resolveUnavailableReason(
        ?Product $product,
        ?ProductVariant $variant,
        ?int $variantId,
        int $quantity
    ): ?string {
        if (! $product) {
            return 'Salah satu produk di keranjang sudah dihapus dan tidak tersedia.';
        }

        if (! $product->is_active) {
            return "Produk {$product->name} sedang tidak aktif.";
        }

        if ($product->has_variants) {
            if (! $variant || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active) {
                return "Varian untuk produk {$product->name} tidak lagi tersedia.";
            }

            $stock = $variant->availableStock();
            if ($stock <= 0) {
                return "Stok {$product->name} ({$variant->name}) sedang habis.";
            }

            if ($quantity > $stock) {
                return "Stok {$product->name} ({$variant->name}) tersisa {$stock} unit.";
            }

            return null;
        }

        if ($variantId !== null) {
            return "Konfigurasi varian untuk {$product->name} tidak lagi valid.";
        }

        $stock = max(0, (int) $product->stock - (int) $product->reserved_stock);
        if ($stock <= 0) {
            return "Stok {$product->name} sedang habis.";
        }

        if ($quantity > $stock) {
            return "Stok {$product->name} tersisa {$stock} unit.";
        }

        return null;
    }

    protected function emptyCartResponse(string $mode, ?int $cartId): array
    {
        return [
            'mode' => $mode,
            'cart_id' => $cartId,
            'items' => new Collection(),
            'total_quantity' => 0,
            'valid_quantity' => 0,
            'subtotal' => 0.0,
            'total_weight_grams' => 0,
            'has_unavailable_items' => false,
            'warnings' => [],
        ];
    }

    protected function formatMutationResult(
        int|string $identifier,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ?Customer $actor
    ): array {
        $now = now();
        $unitPrice = $variant
            ? $variant->effectivePrice($product, $now)
            : $product->effectivePrice($now);

        return [
            'identifier' => $identifier,
            'cart_item_id' => is_int($identifier) ? $identifier : null,
            'key' => self::buildItemKey($product->id, $variant?->id),
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_subtotal' => round($unitPrice * $quantity, 2),
            'total_quantity' => $this->getHeaderItemCount($actor),
        ];
    }

    /**
     * @param  array<string, array{product_id: int, product_variant_id: ?int, quantity: int}>  $items
     */
    protected function writeGuestSessionItems(array $items): void
    {
        if (! app()->bound('session')) {
            return;
        }

        if ($items === []) {
            session()->forget(self::GUEST_SESSION_KEY);

            return;
        }

        session()->put(self::GUEST_SESSION_KEY, $items);
    }

    protected function clearGuestSession(): void
    {
        try {
            if (app()->bound('session')) {
                session()->forget(self::GUEST_SESSION_KEY);
            }
        } catch (Throwable) {
            // Abaikan error session saat tidak tersedia di konteks non-session.
        }
    }
}
