<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Services\Setting\StoreSettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CatalogService
{
    public const PER_PAGE = 12;

    public function __construct(
        protected StoreSettingService $settingService,
    ) {
    }

    /**
     * Mengambil data halaman katalog seluruh produk (/produk).
     * Disiapkan agar mudah diperluas untuk pencarian, filter, dan sorting pada Tahap 6.
     */
    public function getCatalogData(Request $request): array
    {
        $products = $this->baseSellableProductQuery()
            ->latest('products.created_at')
            ->latest('products.id')
            ->paginate(self::PER_PAGE);

        $categories = $this->getRootActiveCategories();
        $storeName = $this->resolveStoreName();
        $currentPage = $products->currentPage();
        $pageSuffix = $currentPage > 1 ? ' (Halaman '.$currentPage.')' : '';

        $hasUnsupportedQueryParams = $this->hasNonPaginationQueryParams($request);
        $isOutOfRangePage = $products->total() > 0 && $products->isEmpty();
        $cleanCanonical = ($currentPage > 1 && ! $hasUnsupportedQueryParams && ! $isOutOfRangePage)
            ? route('storefront.catalog', ['page' => $currentPage])
            : route('storefront.catalog');

        $breadcrumbs = [
            ['label' => 'Beranda', 'url' => route('storefront.home')],
            ['label' => 'Katalog Produk', 'url' => route('storefront.catalog')],
        ];

        $metaDescription = $this->resolveDefaultCatalogDescription($storeName, $currentPage);

        return [
            'products' => $products,
            'categories' => $categories,
            'breadcrumbs' => $breadcrumbs,
            'breadcrumbSchema' => $this->buildBreadcrumbSchema($breadcrumbs),
            'seo' => [
                'title' => 'Katalog Produk'.$pageSuffix.' — '.$storeName,
                'description' => $metaDescription,
                'canonical' => $cleanCanonical,
                'robots' => ($hasUnsupportedQueryParams || $isOutOfRangePage) ? 'noindex,follow' : 'index,follow',
                'og_image' => null,
            ],
        ];
    }

    /**
     * Mengambil data halaman kategori (/kategori/{slug}), termasuk produk dari seluruh descendant aktif.
     */
    public function getCategoryData(string $slug, Request $request): array
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $activeCategories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'name', 'slug', 'image_path']);

        $categoryMap = $activeCategories->keyBy('id');
        $childrenByParent = $activeCategories->groupBy('parent_id');

        $descendantIds = $this->collectDescendantCategoryIds($category->id, $childrenByParent);

        $products = $this->baseSellableProductQuery()
            ->whereHas('categories', function (Builder $query) use ($descendantIds) {
                $query->whereIn('categories.id', $descendantIds);
            })
            ->latest('products.created_at')
            ->latest('products.id')
            ->paginate(self::PER_PAGE);

        $subcategories = $this->getDirectActiveSubcategoriesWithCounts($category->id, $activeCategories);
        $breadcrumbs = $this->buildCategoryBreadcrumbs($category, $categoryMap);
        $storeName = $this->resolveStoreName();
        $currentPage = $products->currentPage();
        $pageSuffix = $currentPage > 1 ? ' (Halaman '.$currentPage.')' : '';

        $hasUnsupportedQueryParams = $this->hasNonPaginationQueryParams($request);
        $isOutOfRangePage = $products->total() > 0 && $products->isEmpty();
        $baseCategoryUrl = route('storefront.category', $category->slug);
        $validatedCustomCanonical = $this->validateSameDomainCanonical($category->canonical_url);

        if ($hasUnsupportedQueryParams || $isOutOfRangePage) {
            $canonical = $validatedCustomCanonical ?? $baseCategoryUrl;
        } elseif ($currentPage > 1) {
            $canonical = route('storefront.category', ['slug' => $category->slug, 'page' => $currentPage]);
        } else {
            $canonical = $validatedCustomCanonical ?? $baseCategoryUrl;
        }

        $seoBaseTitle = trim((string) ($category->seo_title ?: $category->name));
        $seoDescription = trim((string) ($category->seo_description ?: ''));
        if ($seoDescription === '') {
            $plainDesc = trim(strip_tags((string) $category->description));
            $seoDescription = $plainDesc !== ''
                ? Str::limit($plainDesc, 155)
                : 'Belanja produk UMKM kategori '.$category->name.' berkualitas di '.$storeName.'.';
        }
        if ($currentPage > 1) {
            $seoDescription .= ' Halaman '.$currentPage.'.';
        }

        $categoryImage = $this->mediaUrl($category->banner_path ?: $category->image_path);

        return [
            'category' => $category,
            'subcategories' => $subcategories,
            'products' => $products,
            'breadcrumbs' => $breadcrumbs,
            'breadcrumbSchema' => $this->buildBreadcrumbSchema($breadcrumbs),
            'categoryImageUrl' => $categoryImage,
            'seo' => [
                'title' => $seoBaseTitle.$pageSuffix.' — '.$storeName,
                'description' => $seoDescription,
                'canonical' => $canonical,
                'robots' => ($hasUnsupportedQueryParams || $isOutOfRangePage) ? 'noindex,follow' : 'index,follow',
                'og_image' => $categoryImage,
            ],
        ];
    }

    public function baseSellableProductQuery(): Builder
    {
        return Product::query()
            ->with([
                'images',
                'categories' => fn ($q) => $q->where('categories.is_active', true)->orderBy('categories.sort_order'),
                'variants' => fn ($q) => $q->where('product_variants.is_active', true),
            ])
            ->activeAndSellable();
    }

    public function getRootActiveCategories(): Collection
    {
        $roots = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->attachDescendantProductCounts($roots);
    }

    protected function getDirectActiveSubcategoriesWithCounts(int $parentCategoryId, ?Collection $activeCategories = null): Collection
    {
        $subcategories = Category::query()
            ->where('parent_id', $parentCategoryId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->attachDescendantProductCounts($subcategories, $activeCategories);
    }

    /**
     * Menghitung jumlah produk aktif dan tersedia stoknya untuk setiap kategori beserta seluruh descendant aktif,
     * bebas duplikasi (unique product_id) dan bebas N+1 query.
     */
    public function attachDescendantProductCounts(Collection $targetCategories, ?Collection $activeCategories = null): Collection
    {
        if ($targetCategories->isEmpty()) {
            return $targetCategories;
        }

        $activeTree = $activeCategories ?? Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id']);

        $childrenByParent = $activeTree->groupBy('parent_id');
        $descendantsByRoot = [];
        $allNeededCategoryIds = [];

        foreach ($targetCategories as $cat) {
            $visited = [];
            $ids = $this->collectDescendantCategoryIds((int) $cat->id, $childrenByParent, $visited);
            $descendantsByRoot[$cat->id] = $ids;
            foreach ($ids as $id) {
                $allNeededCategoryIds[$id] = true;
            }
        }

        $categoryIds = array_keys($allNeededCategoryIds);
        if ($categoryIds === []) {
            foreach ($targetCategories as $cat) {
                $cat->setAttribute('active_products_count', 0);
            }

            return $targetCategories;
        }

        $pairs = DB::table('product_categories')
            ->join('products', 'products.id', '=', 'product_categories.product_id')
            ->whereIn('product_categories.category_id', $categoryIds)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->where(function ($stockQuery) {
                $stockQuery->where(function ($simple) {
                    $simple->where('products.has_variants', false)
                        ->whereColumn('products.stock', '>', 'products.reserved_stock');
                })->orWhere(function ($varianted) {
                    $varianted->where('products.has_variants', true)
                        ->whereExists(function ($sub) {
                            $sub->selectRaw(1)
                                ->from('product_variants')
                                ->whereColumn('product_variants.product_id', 'products.id')
                                ->where('product_variants.is_active', true)
                                ->whereNull('product_variants.deleted_at')
                                ->whereColumn('product_variants.stock', '>', 'product_variants.reserved_stock');
                        });
                });
            })
            ->get(['product_categories.category_id', 'product_categories.product_id']);

        $productIdsByCategory = [];
        foreach ($pairs as $pair) {
            $productIdsByCategory[(int) $pair->category_id][] = (int) $pair->product_id;
        }

        foreach ($targetCategories as $cat) {
            $uniqueProductIds = [];
            foreach ($descendantsByRoot[$cat->id] ?? [] as $descId) {
                foreach ($productIdsByCategory[$descId] ?? [] as $productId) {
                    $uniqueProductIds[$productId] = true;
                }
            }
            $cat->setAttribute('active_products_count', count($uniqueProductIds));
        }

        return $targetCategories;
    }

    /**
     * Resolver rekursif aman dengan visited-set untuk mengumpulkan ID kategori dan seluruh descendant aktif.
     */
    protected function collectDescendantCategoryIds(int $rootId, Collection $childrenByParent, array &$visited = []): array
    {
        if (isset($visited[$rootId])) {
            return [];
        }

        $visited[$rootId] = true;
        $ids = [$rootId];

        foreach ($childrenByParent->get($rootId, collect()) as $child) {
            $ids = array_merge($ids, $this->collectDescendantCategoryIds((int) $child->id, $childrenByParent, $visited));
        }

        return $ids;
    }

    /**
     * Membangun breadcrumb hierarkis kategori aktif dengan proteksi loop parent_id.
     */
    protected function buildCategoryBreadcrumbs(Category $category, Collection $activeCategoryMap): array
    {
        $chain = [];
        $visited = [$category->id => true];
        $currentId = $category->parent_id ? (int) $category->parent_id : null;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $parent = $activeCategoryMap->get($currentId);
            if (! $parent) {
                break;
            }
            array_unshift($chain, [
                'label' => $parent->name,
                'url' => route('storefront.category', $parent->slug),
            ]);
            $currentId = $parent->parent_id ? (int) $parent->parent_id : null;
        }

        return array_merge(
            [
                ['label' => 'Beranda', 'url' => route('storefront.home')],
                ['label' => 'Katalog Produk', 'url' => route('storefront.catalog')],
            ],
            $chain,
            [
                ['label' => $category->name, 'url' => route('storefront.category', $category->slug)],
            ]
        );
    }

    protected function buildBreadcrumbSchema(array $breadcrumbs): array
    {
        $items = [];
        foreach (array_values($breadcrumbs) as $index => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Validasi canonical URL: hanya izinkan URL pada domain toko yang sama atau path internal aman.
     */
    public function validateSameDomainCanonical(?string $canonicalUrl): ?string
    {
        $canonicalUrl = trim((string) $canonicalUrl);
        if ($canonicalUrl === '') {
            return null;
        }

        if (str_starts_with($canonicalUrl, '/') && ! str_starts_with($canonicalUrl, '//')) {
            return url($canonicalUrl);
        }

        if (! filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($canonicalUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));
        $appHost = strtolower((string) parse_url(url('/'), PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $host !== $appHost) {
            return null;
        }

        return $canonicalUrl;
    }

    public function mediaUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));

            return in_array($scheme, ['http', 'https'], true) ? $path : null;
        }

        if (str_starts_with($path, 'javascript:') || str_starts_with($path, 'data:')) {
            return null;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    protected function hasNonPaginationQueryParams(Request $request): bool
    {
        $keys = array_keys($request->query());
        foreach ($keys as $key) {
            if ($key !== 'page') {
                return true;
            }
        }

        return false;
    }

    protected function resolveStoreName(): string
    {
        try {
            return (string) ($this->settingService->publicSettings()['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara'));
        } catch (Throwable) {
            return (string) config('app.name', 'Pasar Lokal Nusantara');
        }
    }

    protected function resolveDefaultCatalogDescription(string $storeName, int $currentPage): string
    {
        try {
            $desc = (string) ($this->settingService->get('seo', 'default_meta_description')
                ?: ($this->settingService->publicSettings()['store_description'] ?? ''));
        } catch (Throwable) {
            $desc = '';
        }

        if (trim($desc) === '') {
            $desc = 'Jelajahi katalog lengkap produk UMKM pilihan dari '.$storeName.'.';
        }

        if ($currentPage > 1) {
            $desc .= ' Halaman '.$currentPage.'.';
        }

        return $desc;
    }
}
