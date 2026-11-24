<?php

namespace App\Services\Admin;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Security\AuditLogService;
use App\Services\Storefront\HomepageService;
use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CatalogManagementService
{
    public function createCategory(User $admin, array $data, ?UploadedFile $image = null, ?Request $request = null): Category
    {
        $storedImagePath = null;

        try {
            if ($image !== null) {
                $storedImagePath = $image->store('categories', 'public');
            }

            return DB::transaction(function () use ($admin, $data, $storedImagePath, $request): Category {
                $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
                $slug = $this->resolveUniqueCategorySlug($data['slug'] ?? null, $data['name']);

                $category = Category::create([
                    'parent_id' => $parentId,
                    'name' => trim((string) $data['name']),
                    'slug' => $slug,
                    'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                        ? trim((string) $data['description'])
                        : null,
                    'image_path' => $storedImagePath,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ]);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'category.created',
                    "Menambahkan kategori {$category->name}.",
                    $category,
                    ['category_id' => $category->id, 'slug' => $category->slug],
                    $request
                );

                return $category;
            });
        } catch (\Throwable $e) {
            if ($storedImagePath !== null) {
                Storage::disk('public')->delete($storedImagePath);
            }
            throw $e;
        }
    }

    public function updateCategory(
        User $admin,
        Category $category,
        array $data,
        ?UploadedFile $image = null,
        bool $removeImage = false,
        ?Request $request = null
    ): Category {
        $newImagePath = null;
        $oldImageToDelete = null;

        try {
            if ($image !== null) {
                $newImagePath = $image->store('categories', 'public');
            }

            $updated = DB::transaction(function () use (
                $admin,
                $category,
                $data,
                $newImagePath,
                $removeImage,
                $request,
                &$oldImageToDelete
            ): Category {
                /** @var Category $locked */
                $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

                $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
                if ($parentId !== null && $this->wouldCreateCategoryCycle($locked->id, $parentId)) {
                    throw new InvalidArgumentException('Induk kategori tidak valid karena membentuk relasi melingkar.');
                }

                $slug = $this->resolveUniqueCategorySlug($data['slug'] ?? null, $data['name'], $locked->id);

                $currentImage = $locked->image_path;
                $targetImage = $currentImage;

                if ($newImagePath !== null) {
                    $targetImage = $newImagePath;
                    if ($currentImage && $this->isManagedPublicStoragePath($currentImage, 'categories/')) {
                        $oldImageToDelete = $currentImage;
                    }
                } elseif ($removeImage) {
                    $targetImage = null;
                    if ($currentImage && $this->isManagedPublicStoragePath($currentImage, 'categories/')) {
                        $oldImageToDelete = $currentImage;
                    }
                }

                $locked->update([
                    'parent_id' => $parentId,
                    'name' => trim((string) $data['name']),
                    'slug' => $slug,
                    'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                        ? trim((string) $data['description'])
                        : null,
                    'image_path' => $targetImage,
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'is_active' => (bool) ($data['is_active'] ?? false),
                ]);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'category.updated',
                    "Memperbarui kategori {$locked->name}.",
                    $locked,
                    ['category_id' => $locked->id, 'is_active' => $locked->is_active],
                    $request
                );

                return $locked->fresh();
            });

            if ($oldImageToDelete !== null) {
                Storage::disk('public')->delete($oldImageToDelete);
            }

            $this->flushStorefrontCaches();

            return $updated;
        } catch (\Throwable $e) {
            if ($newImagePath !== null) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $e;
        }
    }

    public function deleteCategory(User $admin, Category $category, ?Request $request = null): void
    {
        $imageToDelete = null;

        DB::transaction(function () use ($admin, $category, $request, &$imageToDelete): void {
            /** @var Category $locked */
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            if ($locked->children()->exists()) {
                throw new RuntimeException('Kategori masih memiliki subkategori aktif. Pindahkan atau hapus subkategori terlebih dahulu.');
            }

            $hasProducts = $locked->products()->withTrashed()->exists();

            if ($hasProducts) {
                $locked->is_active = false;
                $locked->save();
                $locked->delete();
            } else {
                if ($locked->image_path && $this->isManagedPublicStoragePath($locked->image_path, 'categories/')) {
                    $imageToDelete = $locked->image_path;
                }
                $locked->forceDelete();
            }

            AuditLogService::record(
                'user',
                $admin->id,
                'category.deleted',
                "Menghapus/mengarsipkan kategori {$locked->name}.",
                $locked,
                ['category_id' => $locked->id, 'soft_deleted' => $hasProducts],
                $request
            );
        });

        if ($imageToDelete !== null) {
            Storage::disk('public')->delete($imageToDelete);
        }

        $this->flushStorefrontCaches();
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function createProduct(User $admin, array $data, array $images = [], ?Request $request = null): Product
    {
        $storedPaths = [];

        try {
            $product = DB::transaction(function () use ($admin, $data, $images, $request, &$storedPaths): Product {
                $hasVariants = (bool) ($data['has_variants'] ?? false);
                $initialStock = $hasVariants ? 0 : max(0, (int) ($data['initial_stock'] ?? 0));
                $slug = $this->resolveUniqueProductSlug($data['slug'] ?? null, $data['name']);

                $product = Product::create([
                    'sku' => strtoupper(trim((string) $data['sku'])),
                    'name' => trim((string) $data['name']),
                    'slug' => $slug,
                    'short_description' => isset($data['short_description']) && trim((string) $data['short_description']) !== ''
                        ? trim((string) $data['short_description'])
                        : null,
                    'description' => trim((string) $data['description']),
                    'specifications' => isset($data['specifications']) && trim((string) $data['specifications']) !== ''
                        ? trim((string) $data['specifications'])
                        : null,
                    'cost_price' => (float) ($data['cost_price'] ?? 0),
                    'price' => (float) $data['price'],
                    'promo_price' => isset($data['promo_price']) && $data['promo_price'] !== '' ? (float) $data['promo_price'] : null,
                    'promo_start_at' => $data['promo_start_at'] ?? null,
                    'promo_end_at' => $data['promo_end_at'] ?? null,
                    'weight_grams' => (int) $data['weight_grams'],
                    'stock' => $initialStock,
                    'reserved_stock' => 0,
                    'min_stock' => (int) ($data['min_stock'] ?? 5),
                    'has_variants' => $hasVariants,
                    'is_featured' => (bool) ($data['is_featured'] ?? false),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ]);

                $this->syncProductCategories(
                    $product,
                    (int) $data['primary_category_id'],
                    $data['category_ids'] ?? []
                );

                if (! $hasVariants && $initialStock > 0) {
                    StockMovement::create([
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'type' => 'in',
                        'quantity' => $initialStock,
                        'stock_before' => 0,
                        'stock_after' => $initialStock,
                        'reference_type' => Product::class,
                        'reference_id' => $product->id,
                        'notes' => 'Stok awal saat pembuatan produk.',
                        'created_by' => $admin->id,
                        'created_at' => now(),
                    ]);
                }

                if ($hasVariants && ! empty($data['variants']) && is_array($data['variants'])) {
                    foreach ($data['variants'] as $variantInput) {
                        $this->createVariantRecord($admin, $product, $variantInput);
                    }
                }

                foreach ($images as $index => $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }
                    $path = $file->store("products/{$product->id}", 'public');
                    $storedPaths[] = $path;

                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => $path,
                        'alt_text' => $product->name,
                        'sort_order' => $index,
                        'is_primary' => $index === 0,
                    ]);
                }

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'product.created',
                    "Menambahkan produk {$product->name} ({$product->sku}).",
                    $product,
                    ['product_id' => $product->id, 'sku' => $product->sku, 'has_variants' => $hasVariants],
                    $request
                );

                return $product->fresh(['categories', 'images', 'variants']);
            });

            $this->flushStorefrontCaches();

            return $product;
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
    }

    public function updateProduct(User $admin, Product $product, array $data, ?Request $request = null): Product
    {
        $updated = DB::transaction(function () use ($admin, $product, $data, $request): Product {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $hasVariants = (bool) ($data['has_variants'] ?? $locked->has_variants);
            if ($locked->has_variants && ! $hasVariants && $locked->variants()->exists()) {
                throw new RuntimeException('Produk masih memiliki data varian sehingga mode varian tidak dapat dimatikan.');
            }

            $slug = $this->resolveUniqueProductSlug($data['slug'] ?? null, $data['name'], $locked->id);

            $locked->update([
                'sku' => strtoupper(trim((string) $data['sku'])),
                'name' => trim((string) $data['name']),
                'slug' => $slug,
                'short_description' => isset($data['short_description']) && trim((string) $data['short_description']) !== ''
                    ? trim((string) $data['short_description'])
                    : null,
                'description' => trim((string) $data['description']),
                'specifications' => isset($data['specifications']) && trim((string) $data['specifications']) !== ''
                    ? trim((string) $data['specifications'])
                    : null,
                'cost_price' => (float) ($data['cost_price'] ?? 0),
                'price' => (float) $data['price'],
                'promo_price' => isset($data['promo_price']) && $data['promo_price'] !== '' ? (float) $data['promo_price'] : null,
                'promo_start_at' => $data['promo_start_at'] ?? null,
                'promo_end_at' => $data['promo_end_at'] ?? null,
                'weight_grams' => (int) $data['weight_grams'],
                'min_stock' => (int) ($data['min_stock'] ?? 5),
                'has_variants' => $hasVariants,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);

            $this->syncProductCategories(
                $locked,
                (int) $data['primary_category_id'],
                $data['category_ids'] ?? []
            );

            AuditLogService::record(
                'user',
                $admin->id,
                'product.updated',
                "Memperbarui produk {$locked->name} ({$locked->sku}).",
                $locked,
                ['product_id' => $locked->id, 'is_active' => $locked->is_active],
                $request
            );

            return $locked->fresh(['categories', 'images', 'variants']);
        });

        $this->flushStorefrontCaches();

        return $updated;
    }

    /**
     * Menghapus produk secara aman:
     * - Menolak jika ada reservasi stok aktif.
     * - Jika produk punya riwayat transaksi / mutasi stok, hanya menonaktifkan dan melakukan soft delete (tidak pernah hapus permanen).
     * - Jika benar-benar belum pernah terlibat transaksi/stok, hapus item keranjang terkait dan hapus permanen beserta file gambar lokalnya.
     *
     * @return 'soft_deleted'|'force_deleted'
     */
    public function deleteProduct(User $admin, Product $product, ?Request $request = null): string
    {
        $filesToDelete = [];

        $outcome = DB::transaction(function () use ($admin, $product, $request, &$filesToDelete): string {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $hasActiveReservations = $locked->reserved_stock > 0
                || $locked->variants()->where('reserved_stock', '>', 0)->exists()
                || StockReservation::query()->where('product_id', $locked->id)->where('status', 'active')->exists();

            if ($hasActiveReservations) {
                throw new RuntimeException('Produk masih memiliki reservasi stok aktif pada pesanan yang menunggu pembayaran.');
            }

            $hasHistory = OrderItem::query()->where('product_id', $locked->id)->exists()
                || StockReservation::query()->where('product_id', $locked->id)->exists()
                || StockMovement::query()->where('product_id', $locked->id)->exists()
                || StockAdjustment::query()->where('product_id', $locked->id)->exists();

            CartItem::query()->where('product_id', $locked->id)->delete();

            if ($hasHistory) {
                $locked->is_active = false;
                $locked->save();
                $locked->variants()->update(['is_active' => false]);
                $locked->variants()->delete();
                $locked->delete();

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'product.deactivated_soft_deleted',
                    "Menonaktifkan & mengarsipkan produk {$locked->name} (memiliki riwayat transaksi/stok).",
                    $locked,
                    ['product_id' => $locked->id, 'sku' => $locked->sku, 'mode' => 'soft_deleted'],
                    $request
                );

                return 'soft_deleted';
            }

            foreach ($locked->images as $img) {
                if ($this->isManagedPublicStoragePath($img->image_path, 'products/')) {
                    $filesToDelete[] = $img->image_path;
                }
                if ($img->webp_path && $this->isManagedPublicStoragePath($img->webp_path, 'products/')) {
                    $filesToDelete[] = $img->webp_path;
                }
            }

            foreach ($locked->variants()->withTrashed()->get() as $variant) {
                if ($variant->image_path && $this->isManagedPublicStoragePath($variant->image_path, 'products/')) {
                    $filesToDelete[] = $variant->image_path;
                }
                $variant->forceDelete();
            }

            $productId = $locked->id;
            $productSku = $locked->sku;
            $productName = $locked->name;

            $locked->images()->delete();
            $locked->categories()->detach();
            $locked->forceDelete();

            AuditLogService::record(
                'user',
                $admin->id,
                'product.deleted_permanently',
                "Menghapus permanen produk {$productName} ({$productSku}) tanpa riwayat transaksi.",
                null,
                ['product_id' => $productId, 'sku' => $productSku, 'mode' => 'force_deleted'],
                $request
            );

            return 'force_deleted';
        });

        foreach (array_unique($filesToDelete) as $path) {
            Storage::disk('public')->delete($path);
        }

        $this->flushStorefrontCaches();

        return $outcome;
    }

    /**
     * @param  array<int, UploadedFile>  $images
     * @return array<int, ProductImage>
     */
    public function addProductImages(User $admin, Product $product, array $images, ?Request $request = null): array
    {
        $storedPaths = [];

        try {
            $created = DB::transaction(function () use ($admin, $product, $images, $request, &$storedPaths): array {
                /** @var Product $locked */
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

                $hasPrimary = $locked->images()->where('is_primary', true)->exists();
                $nextOrder = ((int) $locked->images()->max('sort_order')) + 1;
                $createdImages = [];

                foreach ($images as $idx => $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }
                    $path = $file->store("products/{$locked->id}", 'public');
                    $storedPaths[] = $path;

                    $isPrimary = ! $hasPrimary && $idx === 0;
                    if ($isPrimary) {
                        $hasPrimary = true;
                    }

                    $createdImages[] = ProductImage::create([
                        'product_id' => $locked->id,
                        'image_path' => $path,
                        'alt_text' => $locked->name,
                        'sort_order' => $nextOrder + $idx,
                        'is_primary' => $isPrimary,
                    ]);
                }

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'product.images_uploaded',
                    "Menambahkan ".count($createdImages)." gambar pada produk {$locked->name}.",
                    $locked,
                    ['product_id' => $locked->id, 'count' => count($createdImages)],
                    $request
                );

                return $createdImages;
            });

            $this->flushStorefrontCaches();

            return $created;
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
    }

    public function setPrimaryProductImage(User $admin, Product $product, ProductImage $image, ?Request $request = null): void
    {
        DB::transaction(function () use ($admin, $product, $image, $request): void {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ((int) $image->product_id !== (int) $locked->id) {
                throw new InvalidArgumentException('Gambar bukan milik produk ini.');
            }

            $locked->images()->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);

            AuditLogService::record(
                'user',
                $admin->id,
                'product.primary_image_changed',
                "Mengubah gambar utama produk {$locked->name}.",
                $locked,
                ['product_id' => $locked->id, 'image_id' => $image->id],
                $request
            );
        });

        $this->flushStorefrontCaches();
    }

    public function deleteProductImage(User $admin, Product $product, ProductImage $image, ?Request $request = null): void
    {
        $pathsToDelete = [];

        DB::transaction(function () use ($admin, $product, $image, $request, &$pathsToDelete): void {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ((int) $image->product_id !== (int) $locked->id) {
                throw new InvalidArgumentException('Gambar bukan milik produk ini.');
            }

            $wasPrimary = (bool) $image->is_primary;

            if ($this->isManagedPublicStoragePath($image->image_path, 'products/')) {
                $pathsToDelete[] = $image->image_path;
            }
            if ($image->webp_path && $this->isManagedPublicStoragePath($image->webp_path, 'products/')) {
                $pathsToDelete[] = $image->webp_path;
            }

            $image->delete();

            if ($wasPrimary) {
                $nextPrimary = $locked->images()->orderBy('sort_order')->orderBy('id')->first();
                if ($nextPrimary) {
                    $nextPrimary->update(['is_primary' => true]);
                }
            }

            AuditLogService::record(
                'user',
                $admin->id,
                'product.image_deleted',
                "Menghapus gambar produk {$locked->name}.",
                $locked,
                ['product_id' => $locked->id, 'image_id' => $image->id],
                $request
            );
        });

        foreach ($pathsToDelete as $path) {
            Storage::disk('public')->delete($path);
        }

        $this->flushStorefrontCaches();
    }

    public function createVariant(User $admin, Product $product, array $data, ?Request $request = null): ProductVariant
    {
        $variant = DB::transaction(function () use ($admin, $product, $data, $request): ProductVariant {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->has_variants) {
                $locked->has_variants = true;
                $locked->save();
            }

            $variant = $this->createVariantRecord($admin, $locked, $data);

            AuditLogService::record(
                'user',
                $admin->id,
                'product.variant_created',
                "Menambahkan varian {$variant->name} pada produk {$locked->name}.",
                $variant,
                ['product_id' => $locked->id, 'variant_id' => $variant->id, 'sku' => $variant->sku],
                $request
            );

            return $variant;
        });

        $this->flushStorefrontCaches();

        return $variant;
    }

    public function updateVariant(User $admin, Product $product, ProductVariant $variant, array $data, ?Request $request = null): ProductVariant
    {
        $updated = DB::transaction(function () use ($admin, $product, $variant, $data, $request): ProductVariant {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            /** @var ProductVariant $lockedVariant */
            $lockedVariant = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedVariant->product_id !== (int) $lockedProduct->id) {
                throw new InvalidArgumentException('Varian bukan milik produk ini.');
            }

            $lockedVariant->update([
                'sku' => strtoupper(trim((string) $data['sku'])),
                'name' => trim((string) $data['name']),
                'cost_price' => isset($data['cost_price']) && $data['cost_price'] !== '' ? (float) $data['cost_price'] : null,
                'price' => (float) $data['price'],
                'promo_price' => isset($data['promo_price']) && $data['promo_price'] !== '' ? (float) $data['promo_price'] : null,
                'weight_grams' => (int) $data['weight_grams'],
                'min_stock' => (int) ($data['min_stock'] ?? 5),
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                'product.variant_updated',
                "Memperbarui varian {$lockedVariant->name} ({$lockedVariant->sku}).",
                $lockedVariant,
                ['product_id' => $lockedProduct->id, 'variant_id' => $lockedVariant->id],
                $request
            );

            return $lockedVariant->fresh();
        });

        $this->flushStorefrontCaches();

        return $updated;
    }

    public function deleteVariant(User $admin, Product $product, ProductVariant $variant, ?Request $request = null): string
    {
        $outcome = DB::transaction(function () use ($admin, $product, $variant, $request): string {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            /** @var ProductVariant $lockedVariant */
            $lockedVariant = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedVariant->product_id !== (int) $lockedProduct->id) {
                throw new InvalidArgumentException('Varian bukan milik produk ini.');
            }

            $hasActiveReservations = $lockedVariant->reserved_stock > 0
                || StockReservation::query()->where('product_variant_id', $lockedVariant->id)->where('status', 'active')->exists();

            if ($hasActiveReservations) {
                throw new RuntimeException('Varian masih memiliki reservasi stok aktif pada pesanan yang menunggu pembayaran.');
            }

            $hasHistory = OrderItem::query()->where('product_variant_id', $lockedVariant->id)->exists()
                || StockReservation::query()->where('product_variant_id', $lockedVariant->id)->exists()
                || StockMovement::query()->where('product_variant_id', $lockedVariant->id)->exists()
                || StockAdjustment::query()->where('product_variant_id', $lockedVariant->id)->exists();

            CartItem::query()->where('product_variant_id', $lockedVariant->id)->delete();

            if ($hasHistory) {
                $lockedVariant->is_active = false;
                $lockedVariant->save();
                $lockedVariant->delete();

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'product.variant_soft_deleted',
                    "Menonaktifkan & mengarsipkan varian {$lockedVariant->name} ({$lockedVariant->sku}).",
                    $lockedVariant,
                    ['product_id' => $lockedProduct->id, 'variant_id' => $lockedVariant->id],
                    $request
                );

                return 'soft_deleted';
            }

            $variantSku = $lockedVariant->sku;
            $variantName = $lockedVariant->name;
            $lockedVariant->forceDelete();

            AuditLogService::record(
                'user',
                $admin->id,
                'product.variant_deleted',
                "Menghapus varian {$variantName} ({$variantSku}).",
                $lockedProduct,
                ['product_id' => $lockedProduct->id, 'sku' => $variantSku],
                $request
            );

            return 'force_deleted';
        });

        $this->flushStorefrontCaches();

        return $outcome;
    }

    protected function createVariantRecord(User $admin, Product $product, array $data): ProductVariant
    {
        $initialStock = max(0, (int) ($data['initial_stock'] ?? 0));

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => strtoupper(trim((string) $data['sku'])),
            'name' => trim((string) $data['name']),
            'cost_price' => isset($data['cost_price']) && $data['cost_price'] !== '' ? (float) $data['cost_price'] : null,
            'price' => (float) $data['price'],
            'promo_price' => isset($data['promo_price']) && $data['promo_price'] !== '' ? (float) $data['promo_price'] : null,
            'weight_grams' => (int) ($data['weight_grams'] ?? $product->weight_grams),
            'stock' => $initialStock,
            'reserved_stock' => 0,
            'min_stock' => (int) ($data['min_stock'] ?? 5),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($initialStock > 0) {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'in',
                'quantity' => $initialStock,
                'stock_before' => 0,
                'stock_after' => $initialStock,
                'reference_type' => ProductVariant::class,
                'reference_id' => $variant->id,
                'notes' => "Stok awal varian {$variant->name}.",
                'created_by' => $admin->id,
                'created_at' => now(),
            ]);
        }

        return $variant;
    }

    /**
     * @param  array<int, int|string>  $additionalCategoryIds
     */
    protected function syncProductCategories(Product $product, int $primaryCategoryId, array $additionalCategoryIds = []): void
    {
        $ids = collect($additionalCategoryIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->push($primaryCategoryId)
            ->unique()
            ->values();

        $syncPayload = [];
        foreach ($ids as $catId) {
            $syncPayload[$catId] = ['is_primary' => $catId === $primaryCategoryId];
        }

        $product->categories()->sync($syncPayload);
    }

    protected function resolveUniqueCategorySlug(?string $rawSlug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim((string) ($rawSlug ?: $name)));
        if ($base === '') {
            $base = 'kategori';
        }

        $candidate = $base;
        $counter = 2;

        while (
            Category::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    protected function resolveUniqueProductSlug(?string $rawSlug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim((string) ($rawSlug ?: $name)));
        if ($base === '') {
            $base = 'produk';
        }

        $candidate = $base;
        $counter = 2;

        while (
            Product::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }

    protected function wouldCreateCategoryCycle(int $categoryId, int $proposedParentId): bool
    {
        if ($categoryId === $proposedParentId) {
            return true;
        }

        $visited = [$categoryId => true];
        $currentId = $proposedParentId;

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;
            $parent = Category::withTrashed()->find($currentId, ['id', 'parent_id']);
            $currentId = $parent?->parent_id ? (int) $parent->parent_id : null;
        }

        return false;
    }

    protected function isManagedPublicStoragePath(string $path, string $expectedPrefix): bool
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');

        return $normalized !== ''
            && str_starts_with($normalized, $expectedPrefix)
            && ! str_contains($normalized, '..')
            && ! str_starts_with($normalized, 'http://')
            && ! str_starts_with($normalized, 'https://');
    }

    protected function flushStorefrontCaches(): void
    {
        HomepageService::clearCache();
        Cache::forget(StorefrontLayoutService::NAV_CACHE_KEY);
    }
}
