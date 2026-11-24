<?php

namespace App\Services\Inventory;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\Checkout\CheckoutOrderException;
use App\Services\Security\AuditLogService;
use App\Services\Storefront\HomepageService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryReservationService
{
    /**
     * Mereservasikan stok secara atomik di dalam transaksi DB untuk daftar item pesanan yang sudah disiapkan.
     * Pemanggil wajib telah mengunci baris Product dan ProductVariant dengan urutan ID menaik (lockForUpdate).
     *
     * @param  array<int, array{product: Product, variant: ?ProductVariant, quantity: int}>  $preparedItems
     */
    public function reservePreparedItems(
        Order $order,
        array $preparedItems,
        CarbonInterface $expiresAt,
        ?CarbonInterface $timestamp = null
    ): int {
        if ($preparedItems === []) {
            return 0;
        }

        $now = $timestamp ?? now();
        $reservedRecords = 0;

        foreach ($preparedItems as $item) {
            /** @var Product $product */
            $product = $item['product'];
            /** @var ?ProductVariant $variant */
            $variant = $item['variant'];
            $quantity = (int) $item['quantity'];

            if ($quantity <= 0) {
                throw new InvalidArgumentException('Kuantitas reservasi stok harus lebih dari 0.');
            }

            if ($product->has_variants) {
                if (! $variant || (int) $variant->product_id !== (int) $product->id || ! $variant->is_active) {
                    throw CheckoutOrderException::unavailableCartItem(
                        "Varian untuk produk {$product->name} tidak lagi tersedia."
                    );
                }

                $availableBefore = (int) $variant->stock - (int) $variant->reserved_stock;
                if ($availableBefore < $quantity) {
                    $safeAvailable = max(0, $availableBefore);
                    throw CheckoutOrderException::insufficientStock(
                        "Stok {$product->name} ({$variant->name}) tidak mencukupi (tersisa {$safeAvailable} unit)."
                    );
                }

                $affected = DB::table('product_variants')
                    ->where('id', $variant->id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereRaw('(stock - reserved_stock) >= ?', [$quantity])
                    ->update([
                        'reserved_stock' => DB::raw('reserved_stock + '.$quantity),
                        'updated_at' => $now,
                    ]);

                if ($affected !== 1) {
                    throw CheckoutOrderException::insufficientStock(
                        "Stok {$product->name} ({$variant->name}) baru saja berubah dan tidak mencukupi."
                    );
                }

                $variant->reserved_stock = (int) $variant->reserved_stock + $quantity;
                $availableAfter = max(0, $availableBefore - $quantity);
            } else {
                if ($variant !== null) {
                    throw CheckoutOrderException::unavailableCartItem(
                        "Konfigurasi varian produk {$product->name} tidak lagi valid."
                    );
                }

                $availableBefore = (int) $product->stock - (int) $product->reserved_stock;
                if ($availableBefore < $quantity) {
                    $safeAvailable = max(0, $availableBefore);
                    throw CheckoutOrderException::insufficientStock(
                        "Stok {$product->name} tidak mencukupi (tersisa {$safeAvailable} unit)."
                    );
                }

                $affected = DB::table('products')
                    ->where('id', $product->id)
                    ->where('has_variants', false)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereRaw('(stock - reserved_stock) >= ?', [$quantity])
                    ->update([
                        'reserved_stock' => DB::raw('reserved_stock + '.$quantity),
                        'updated_at' => $now,
                    ]);

                if ($affected !== 1) {
                    throw CheckoutOrderException::insufficientStock(
                        "Stok {$product->name} baru saja berubah dan tidak mencukupi."
                    );
                }

                $product->reserved_stock = (int) $product->reserved_stock + $quantity;
                $availableAfter = max(0, $availableBefore - $quantity);
            }

            StockReservation::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);

            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'type' => 'reservation',
                'quantity' => -$quantity,
                'stock_before' => max(0, $availableBefore),
                'stock_after' => $availableAfter,
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'notes' => "Reservasi stok untuk pesanan {$order->order_number}",
                'created_by' => null,
                'created_at' => $now,
            ]);

            $reservedRecords++;
        }

        HomepageService::clearCache();

        return $reservedRecords;
    }

    /**
     * Mereservasikan stok dari item order yang sudah tersimpan (berguna untuk pemanggilan layanan mandiri).
     */
    public function reserveForOrder(Order $order, CarbonInterface $expiresAt): int
    {
        return DB::transaction(function () use ($order, $expiresAt): int {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyActive = StockReservation::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($alreadyActive) {
                return 0;
            }

            $items = $lockedOrder->items()->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                return 0;
            }

            $productIds = $items->pluck('product_id')->filter()->unique()->sort()->values()->all();
            $variantIds = $items->pluck('product_variant_id')->filter()->unique()->sort()->values()->all();

            /** @var Collection<int, Product> $products */
            $products = Product::query()
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

            $prepared = [];
            foreach ($items as $item) {
                $product = $item->product_id ? $products->get((int) $item->product_id) : null;
                if (! $product || ! $product->is_active) {
                    throw CheckoutOrderException::unavailableCartItem('Produk pada pesanan tidak lagi aktif.');
                }

                $variant = $item->product_variant_id ? $variants->get((int) $item->product_variant_id) : null;
                $prepared[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => (int) $item->quantity,
                ];
            }

            return $this->reservePreparedItems($lockedOrder, $prepared, $expiresAt);
        }, 3);
    }

    /**
     * Melepaskan seluruh reservasi stok aktif milik sebuah order secara idempoten.
     */
    public function releaseForOrder(
        Order $order,
        string $targetStatus = 'released',
        ?string $notes = null,
        ?int $actorUserId = null
    ): int {
        if (! in_array($targetStatus, ['released', 'expired'], true)) {
            throw new InvalidArgumentException("Status pelepasan reservasi tidak valid: {$targetStatus}");
        }

        return DB::transaction(function () use ($order, $targetStatus, $notes, $actorUserId): int {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeReservations = StockReservation::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($activeReservations->isEmpty()) {
                return 0;
            }

            $productIds = $activeReservations->pluck('product_id')->unique()->sort()->values()->all();
            $variantIds = $activeReservations->pluck('product_variant_id')->filter()->unique()->sort()->values()->all();

            /** @var Collection<int, Product> $products */
            $products = Product::query()
                ->withTrashed()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Collection<int, ProductVariant> $variants */
            $variants = $variantIds !== []
                ? ProductVariant::query()
                    ->withTrashed()
                    ->whereIn('id', $variantIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id')
                : collect();

            $now = now();
            $releasedCount = 0;

            foreach ($activeReservations as $reservation) {
                $qty = (int) $reservation->quantity;

                if ($reservation->product_variant_id !== null) {
                    /** @var ?ProductVariant $variant */
                    $variant = $variants->get((int) $reservation->product_variant_id);
                    if ($variant) {
                        $currentStock = (int) $variant->stock;
                        $currentReserved = (int) $variant->reserved_stock;
                        $availableBefore = max(0, $currentStock - $currentReserved);
                        $newReserved = max(0, $currentReserved - $qty);
                        $actualReleased = max(0, $currentReserved - $newReserved);

                        DB::table('product_variants')
                            ->where('id', $variant->id)
                            ->update([
                                'reserved_stock' => DB::raw('GREATEST(0, CAST(reserved_stock AS SIGNED) - '.$qty.')'),
                                'updated_at' => $now,
                            ]);

                        $variant->reserved_stock = $newReserved;
                        $availableAfter = max(0, $currentStock - $newReserved);

                        StockMovement::create([
                            'product_id' => $reservation->product_id,
                            'product_variant_id' => $variant->id,
                            'type' => 'release',
                            'quantity' => $actualReleased,
                            'stock_before' => $availableBefore,
                            'stock_after' => $availableAfter,
                            'reference_type' => Order::class,
                            'reference_id' => $lockedOrder->id,
                            'notes' => $notes ?? "Pelepasan reservasi stok pesanan {$lockedOrder->order_number}",
                            'created_by' => $actorUserId,
                            'created_at' => $now,
                        ]);
                    }
                } else {
                    /** @var ?Product $product */
                    $product = $products->get((int) $reservation->product_id);
                    if ($product) {
                        $currentStock = (int) $product->stock;
                        $currentReserved = (int) $product->reserved_stock;
                        $availableBefore = max(0, $currentStock - $currentReserved);
                        $newReserved = max(0, $currentReserved - $qty);
                        $actualReleased = max(0, $currentReserved - $newReserved);

                        DB::table('products')
                            ->where('id', $product->id)
                            ->update([
                                'reserved_stock' => DB::raw('GREATEST(0, CAST(reserved_stock AS SIGNED) - '.$qty.')'),
                                'updated_at' => $now,
                            ]);

                        $product->reserved_stock = $newReserved;
                        $availableAfter = max(0, $currentStock - $newReserved);

                        StockMovement::create([
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'type' => 'release',
                            'quantity' => $actualReleased,
                            'stock_before' => $availableBefore,
                            'stock_after' => $availableAfter,
                            'reference_type' => Order::class,
                            'reference_id' => $lockedOrder->id,
                            'notes' => $notes ?? "Pelepasan reservasi stok pesanan {$lockedOrder->order_number}",
                            'created_by' => $actorUserId,
                            'created_at' => $now,
                        ]);
                    }
                }

                $reservation->status = $targetStatus;
                $reservation->released_at = $now;
                $reservation->save();

                $releasedCount++;
            }

            HomepageService::clearCache();

            return $releasedCount;
        }, 3);
    }

    /**
     * Melepaskan reservasi aktif yang sudah melewati batas expires_at dan menutup order
     * yang melewati payment_due_at secara aman & idempoten, sambil melindungi pesanan
     * yang sudah lunas/settlement atau masih memiliki percobaan pembayaran aktif.
     */
    public function releaseExpiredReservations(int $limit = 100): int
    {
        $batchLimit = max(1, $limit);
        $now = now();

        $expiredReservationOrderIds = StockReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', $now)
            ->select('order_id')
            ->distinct()
            ->limit($batchLimit)
            ->pluck('order_id');

        $expiredDueOrderIds = Order::query()
            ->whereIn('order_status', ['pending', 'awaiting_payment'])
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', $now)
            ->limit($batchLimit)
            ->pluck('id');

        $candidateOrderIds = $expiredReservationOrderIds
            ->merge($expiredDueOrderIds)
            ->unique()
            ->take($batchLimit)
            ->values();

        $totalReleased = 0;

        foreach ($candidateOrderIds as $orderId) {
            $totalReleased += DB::transaction(function () use ($orderId): int {
                /** @var ?Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOrder) {
                    return 0;
                }

                // 1. Lindungi order yang sudah lunas, diproses, dikirim, selesai, dibatalkan, atau direfund.
                if (
                    in_array($lockedOrder->payment_status, ['paid', 'refunded', 'partially_refunded'], true)
                    || in_array($lockedOrder->order_status, ['paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'], true)
                    || $lockedOrder->paid_at !== null
                ) {
                    return 0;
                }

                // 2. Kunci pembayaran terkait untuk memastikan tidak ada settlement atau percobaan aktif.
                /** @var Collection<int, Payment> $payments */
                $payments = Payment::query()
                    ->where('order_id', $lockedOrder->id)
                    ->lockForUpdate()
                    ->get();

                $hasPaidOrActivePayment = $payments->contains(function (Payment $payment): bool {
                    $fraudStatus = strtolower(trim((string) $payment->fraud_status));
                    if (
                        in_array($payment->status, ['settlement', 'refund', 'partial_refund'], true)
                        || ($payment->status === 'capture' && $fraudStatus === 'accept')
                    ) {
                        return true;
                    }

                    // Pertahankan perlindungan jika masih ada percobaan pembayaran pending/challenge aktif.
                    if ($payment->status === 'pending') {
                        return $payment->expired_at === null || $payment->expired_at->isFuture();
                    }

                    return $payment->status === 'capture' && $fraudStatus !== 'deny';
                });

                if ($hasPaidOrActivePayment) {
                    return 0;
                }

                $released = $this->releaseForOrder(
                    $lockedOrder,
                    'expired',
                    "Pelepasan otomatis reservasi stok kedaluwarsa untuk pesanan {$lockedOrder->order_number}"
                );

                // 3. Jika batas waktu pembayaran pesanan sendiri sudah berakhir, tandai pesanan kedaluwarsa & dibatalkan.
                if (
                    $lockedOrder->payment_due_at !== null
                    && $lockedOrder->payment_due_at->isPast()
                    && in_array($lockedOrder->order_status, ['pending', 'awaiting_payment'], true)
                ) {
                    $timestamp = now();
                    $fromOrderStatus = $lockedOrder->order_status;
                    $fromPaymentStatus = $lockedOrder->payment_status;

                    $lockedOrder->order_status = 'cancelled';
                    if (in_array($lockedOrder->payment_status, ['unpaid', 'pending', 'failed'], true)) {
                        $lockedOrder->payment_status = 'expired';
                    }
                    $lockedOrder->cancelled_at = $lockedOrder->cancelled_at ?? $timestamp;
                    $lockedOrder->cancellation_reason = $lockedOrder->cancellation_reason
                        ?: 'Batas waktu pembayaran berakhir (kedaluwarsa otomatis).';
                    $lockedOrder->save();

                    OrderStatusHistory::create([
                        'order_id' => $lockedOrder->id,
                        'status_type' => 'order',
                        'from_status' => $fromOrderStatus,
                        'to_status' => 'cancelled',
                        'notes' => 'Pesanan ditutup otomatis karena melewati batas waktu pembayaran.',
                        'actor_type' => 'system',
                        'actor_id' => null,
                        'created_at' => $timestamp,
                    ]);

                    if ($fromPaymentStatus !== $lockedOrder->payment_status) {
                        OrderStatusHistory::create([
                            'order_id' => $lockedOrder->id,
                            'status_type' => 'payment',
                            'from_status' => $fromPaymentStatus,
                            'to_status' => $lockedOrder->payment_status,
                            'notes' => 'Status pembayaran kedaluwarsa karena melewati payment_due_at.',
                            'actor_type' => 'system',
                            'actor_id' => null,
                            'created_at' => $timestamp,
                        ]);
                    }

                    AuditLogService::record(
                        'system',
                        null,
                        'order.expired',
                        "Pesanan {$lockedOrder->order_number} kedaluwarsa otomatis.",
                        $lockedOrder,
                        [
                            'order_id' => $lockedOrder->id,
                            'order_number' => $lockedOrder->order_number,
                            'released_reservations' => $released,
                        ]
                    );
                }

                return $released;
            }, 3);
        }

        return $totalReleased;
    }

    /**
     * Mengonfirmasi pemotongan stok fisik ketika pembayaran berhasil (fondasi Tahap 6E).
     */
    public function commitForOrder(Order $order, ?int $actorUserId = null): int
    {
        return DB::transaction(function () use ($order, $actorUserId): int {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeReservations = StockReservation::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($activeReservations->isEmpty()) {
                return 0;
            }

            $productIds = $activeReservations->pluck('product_id')->unique()->sort()->values()->all();
            $variantIds = $activeReservations->pluck('product_variant_id')->filter()->unique()->sort()->values()->all();

            /** @var Collection<int, Product> $products */
            $products = Product::query()
                ->withTrashed()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Collection<int, ProductVariant> $variants */
            $variants = $variantIds !== []
                ? ProductVariant::query()
                    ->withTrashed()
                    ->whereIn('id', $variantIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id')
                : collect();

            $now = now();
            $committedCount = 0;

            foreach ($activeReservations as $reservation) {
                $qty = (int) $reservation->quantity;

                if ($reservation->product_variant_id !== null) {
                    /** @var ?ProductVariant $variant */
                    $variant = $variants->get((int) $reservation->product_variant_id);
                    if ($variant) {
                        $stockBefore = (int) $variant->stock;
                        $stockAfter = max(0, $stockBefore - $qty);
                        $newReserved = max(0, (int) $variant->reserved_stock - $qty);

                        DB::table('product_variants')
                            ->where('id', $variant->id)
                            ->update([
                                'stock' => DB::raw('GREATEST(0, CAST(stock AS SIGNED) - '.$qty.')'),
                                'reserved_stock' => DB::raw('GREATEST(0, CAST(reserved_stock AS SIGNED) - '.$qty.')'),
                                'updated_at' => $now,
                            ]);

                        $variant->stock = $stockAfter;
                        $variant->reserved_stock = $newReserved;

                        StockMovement::create([
                            'product_id' => $reservation->product_id,
                            'product_variant_id' => $variant->id,
                            'type' => 'out',
                            'quantity' => -$qty,
                            'stock_before' => $stockBefore,
                            'stock_after' => $stockAfter,
                            'reference_type' => Order::class,
                            'reference_id' => $lockedOrder->id,
                            'notes' => "Pengurangan stok terjual untuk pesanan {$lockedOrder->order_number}",
                            'created_by' => $actorUserId,
                            'created_at' => $now,
                        ]);
                    }
                } else {
                    /** @var ?Product $product */
                    $product = $products->get((int) $reservation->product_id);
                    if ($product) {
                        $stockBefore = (int) $product->stock;
                        $stockAfter = max(0, $stockBefore - $qty);
                        $newReserved = max(0, (int) $product->reserved_stock - $qty);

                        DB::table('products')
                            ->where('id', $product->id)
                            ->update([
                                'stock' => DB::raw('GREATEST(0, CAST(stock AS SIGNED) - '.$qty.')'),
                                'reserved_stock' => DB::raw('GREATEST(0, CAST(reserved_stock AS SIGNED) - '.$qty.')'),
                                'updated_at' => $now,
                            ]);

                        $product->stock = $stockAfter;
                        $product->reserved_stock = $newReserved;

                        StockMovement::create([
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'type' => 'out',
                            'quantity' => -$qty,
                            'stock_before' => $stockBefore,
                            'stock_after' => $stockAfter,
                            'reference_type' => Order::class,
                            'reference_id' => $lockedOrder->id,
                            'notes' => "Pengurangan stok terjual untuk pesanan {$lockedOrder->order_number}",
                            'created_by' => $actorUserId,
                            'created_at' => $now,
                        ]);
                    }
                }

                $reservation->status = 'committed';
                $reservation->committed_at = $now;
                $reservation->save();

                $committedCount++;
            }

            HomepageService::clearCache();

            return $committedCount;
        }, 3);
    }
}
