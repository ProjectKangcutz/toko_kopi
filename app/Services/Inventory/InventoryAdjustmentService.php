<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Security\AuditLogService;
use App\Services\Storefront\HomepageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryAdjustmentService
{
    public const REASONS = [
        'restock' => 'Penambahan Stok (Restock)',
        'opname' => 'Hasil Stok Opname',
        'correction' => 'Koreksi Pencatatan',
        'damaged' => 'Barang Rusak',
        'lost' => 'Barang Hilang / Selisih',
    ];

    public function adjustStock(User $admin, array $data, ?Request $request = null): StockAdjustment
    {
        if (! $admin->hasPermission('inventory.adjust')) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk menyesuaikan stok.');
        }

        $productId = (int) $data['product_id'];
        $variantId = ! empty($data['product_variant_id']) ? (int) $data['product_variant_id'] : null;
        $reason = trim((string) $data['reason']);
        $quantityChange = (int) $data['quantity_change'];
        $notes = trim((string) $data['notes']);

        if (! array_key_exists($reason, self::REASONS)) {
            throw new InvalidArgumentException('Alasan penyesuaian stok tidak valid.');
        }

        if ($quantityChange === 0) {
            throw new InvalidArgumentException('Jumlah perubahan stok tidak boleh 0.');
        }

        if ($notes === '') {
            throw new InvalidArgumentException('Catatan alasan penyesuaian stok wajib diisi.');
        }

        $adjustment = DB::transaction(function () use (
            $admin,
            $productId,
            $variantId,
            $reason,
            $quantityChange,
            $notes,
            $request
        ): StockAdjustment {
            /** @var Product $product */
            $product = Product::query()
                ->whereKey($productId)
                ->lockForUpdate()
                ->firstOrFail();

            $variant = null;

            if ($product->has_variants) {
                if ($variantId === null) {
                    throw new InvalidArgumentException('Produk ini menggunakan varian. Pilih varian yang ingin disesuaikan stoknya.');
                }

                /** @var ProductVariant $variant */
                $variant = ProductVariant::query()
                    ->whereKey($variantId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $stockBefore = (int) $variant->stock;
                $reserved = (int) $variant->reserved_stock;
                $stockAfter = $stockBefore + $quantityChange;

                if ($stockAfter < 0) {
                    throw new InvalidArgumentException('Penyesuaian ditolak: stok fisik varian tidak boleh menjadi negatif.');
                }

                if ($stockAfter < $reserved) {
                    throw new InvalidArgumentException(
                        "Penyesuaian ditolak: stok fisik ({$stockAfter}) tidak boleh lebih kecil dari stok yang sedang direservasi ({$reserved})."
                    );
                }

                $variant->stock = $stockAfter;
                $variant->save();
            } else {
                if ($variantId !== null) {
                    throw new InvalidArgumentException('Produk sederhana ini tidak memiliki varian.');
                }

                $stockBefore = (int) $product->stock;
                $reserved = (int) $product->reserved_stock;
                $stockAfter = $stockBefore + $quantityChange;

                if ($stockAfter < 0) {
                    throw new InvalidArgumentException('Penyesuaian ditolak: stok fisik produk tidak boleh menjadi negatif.');
                }

                if ($stockAfter < $reserved) {
                    throw new InvalidArgumentException(
                        "Penyesuaian ditolak: stok fisik ({$stockAfter}) tidak boleh lebih kecil dari stok yang sedang direservasi ({$reserved})."
                    );
                }

                $product->stock = $stockAfter;
                $product->save();
            }

            $now = now();
            $adjustmentNumber = $this->generateAdjustmentNumber($now);

            $adjustment = StockAdjustment::create([
                'adjustment_number' => $adjustmentNumber,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'reason' => $reason,
                'quantity_change' => $quantityChange,
                'notes' => $notes,
                'adjusted_by' => $admin->id,
                'adjusted_at' => $now,
            ]);

            $reasonLabel = self::REASONS[$reason];

            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'type' => 'adjustment',
                'quantity' => $quantityChange,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_type' => StockAdjustment::class,
                'reference_id' => $adjustment->id,
                'notes' => "[{$reasonLabel}] {$notes}",
                'created_by' => $admin->id,
                'created_at' => $now,
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                'inventory.adjusted',
                "Penyesuaian stok {$adjustmentNumber} ({$quantityChange}) pada {$product->name}".($variant ? " - {$variant->name}" : '').'.',
                $adjustment,
                [
                    'adjustment_number' => $adjustmentNumber,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'reason' => $reason,
                    'quantity_change' => $quantityChange,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                ],
                $request
            );

            return $adjustment->fresh(['product', 'variant', 'adjuster']);
        }, 3);

        HomepageService::clearCache();

        return $adjustment;
    }

    protected function generateAdjustmentNumber(\Carbon\CarbonInterface $now): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'ADJ-'.$now->format('YmdHis').'-'.strtoupper(Str::random(4));
            if (! StockAdjustment::query()->where('adjustment_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'ADJ-'.$now->format('YmdHis').'-'.strtoupper(Str::random(8));
    }
}
