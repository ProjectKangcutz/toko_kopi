<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Services\Setting\StoreSettingService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ProductDetailService
{
    public function __construct(
        protected CatalogService $catalogService,
        protected StoreSettingService $settingService,
    ) {
    }

    public function getProductDetailData(string $slug): array
    {
        $now = now();

        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
                'categories' => fn ($q) => $q->where('categories.is_active', true)
                    ->orderByDesc('product_categories.is_primary')
                    ->orderBy('categories.sort_order'),
                'variants' => fn ($q) => $q->where('product_variants.is_active', true)->orderBy('product_variants.id'),
                'variants.attributeValues.attribute',
            ])
            ->firstOrFail();

        $gallery = $this->normalizeGallery($product);
        $variants = $this->normalizeVariants($product, $now);

        if ($product->has_variants) {
            $sellableVariants = $variants->where('stock', '>', 0)->values();
            $initialVariant = $sellableVariants->first() ?? $variants->first();
            $availableStock = (int) $variants->sum('stock');

            if ($initialVariant !== null) {
                $effectivePrice = (float) $initialVariant['effective_price'];
                $regularPrice = (float) $initialVariant['regular_price'];
                $isPromo = (bool) $initialVariant['is_promo'];
                $discountPercent = (int) $initialVariant['discount_percent'];
            } else {
                $effectivePrice = (float) $product->effectivePrice($now);
                $regularPrice = (float) $product->price;
                $isPromo = $product->hasActivePromo($now);
                $discountPercent = $this->calculateDiscountPercent($regularPrice, $effectivePrice, $isPromo);
            }

            $initialVariantId = $sellableVariants->isNotEmpty()
                ? (int) $sellableVariants->first()['id']
                : null;
        } else {
            $availableStock = $product->availableStock();
            $effectivePrice = $product->effectivePrice($now);
            $regularPrice = (float) $product->price;
            $isPromo = $product->hasActivePromo($now);
            $discountPercent = $this->calculateDiscountPercent($regularPrice, $effectivePrice, $isPromo);
            $initialVariantId = null;
        }

        $promoPeriodLabel = $isPromo ? $this->formatPromoPeriod($product) : null;
        $specifications = $this->parseSpecifications($product->specifications);
        $reviewSummary = $this->loadApprovedReviews($product->id);
        $relatedProducts = $this->loadRelatedProducts($product);
        $breadcrumbs = $this->buildBreadcrumbs($product);

        $storeName = $this->resolveStoreName();
        $canonical = $this->catalogService->validateSameDomainCanonical($product->canonical_url)
            ?? route('storefront.product', $product->slug);

        $seoTitleBase = trim((string) ($product->seo_title ?: $product->name));
        $seoDescription = $this->resolveMetaDescription($product);
        $ogImage = $gallery->first()['image_url'] ?? null;

        return [
            'product' => $product,
            'gallery' => $gallery,
            'variants' => $variants,
            'initialVariantId' => $initialVariantId,
            'pricing' => [
                'effective_price' => $effectivePrice,
                'regular_price' => $regularPrice,
                'is_promo' => $isPromo,
                'discount_percent' => $discountPercent,
                'promo_period_label' => $promoPeriodLabel,
            ],
            'availableStock' => $availableStock,
            'specifications' => $specifications,
            'reviewSummary' => $reviewSummary,
            'relatedProducts' => $relatedProducts,
            'breadcrumbs' => $breadcrumbs,
            'breadcrumbSchema' => $this->buildBreadcrumbSchema($breadcrumbs),
            'productSchema' => $this->buildProductSchema(
                $product,
                $gallery,
                $variants,
                $availableStock,
                $effectivePrice,
                $reviewSummary,
                $canonical,
                $seoDescription,
                $storeName
            ),
            'seo' => [
                'title' => $seoTitleBase.' — '.$storeName,
                'description' => $seoDescription,
                'canonical' => $canonical,
                'robots' => 'index,follow',
                'og_type' => 'product',
                'og_image' => $ogImage,
            ],
        ];
    }

    protected function normalizeGallery(Product $product): Collection
    {
        return $product->images
            ->map(function ($image) use ($product) {
                $imageUrl = HomepageService::mediaUrl($image->image_path);
                if (! $imageUrl) {
                    return null;
                }

                $webpUrl = ! empty($image->webp_path) ? HomepageService::mediaUrl($image->webp_path) : null;

                return [
                    'id' => (int) $image->id,
                    'image_url' => $imageUrl,
                    'webp_url' => $webpUrl,
                    'alt' => trim((string) ($image->alt_text ?: $product->name)),
                    'is_primary' => (bool) $image->is_primary,
                ];
            })
            ->filter()
            ->values();
    }

    protected function normalizeVariants(Product $product, CarbonInterface $now): Collection
    {
        if (! $product->has_variants) {
            return collect();
        }

        return $product->variants
            ->where('is_active', true)
            ->map(function (ProductVariant $variant) use ($product, $now) {
                $effectivePrice = $variant->effectivePrice($product, $now);
                $regularPrice = (float) $variant->price;
                $isPromo = $variant->hasActivePromo($product, $now);

                $attributes = $variant->attributeValues
                    ->map(fn ($attrVal) => [
                        'name' => $attrVal->attribute?->name,
                        'value' => $attrVal->value,
                    ])
                    ->filter(fn ($pair) => ! empty($pair['name']) && ! empty($pair['value']))
                    ->values()
                    ->all();

                return [
                    'id' => (int) $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    'regular_price' => $regularPrice,
                    'effective_price' => $effectivePrice,
                    'is_promo' => $isPromo,
                    'promo_period_label' => $isPromo ? $this->formatPromoPeriod($product) : null,
                    'discount_percent' => $this->calculateDiscountPercent($regularPrice, $effectivePrice, $isPromo),
                    'stock' => $variant->availableStock(),
                    'weight_grams' => max(0, (int) $variant->weight_grams),
                    'image_url' => ! empty($variant->image_path) ? HomepageService::mediaUrl($variant->image_path) : null,
                    'attributes' => $attributes,
                ];
            })
            ->values();
    }

    protected function calculateDiscountPercent(float $regularPrice, float $effectivePrice, bool $isPromo): int
    {
        if (! $isPromo || $regularPrice <= 0 || $effectivePrice >= $regularPrice) {
            return 0;
        }

        return max(1, (int) round((($regularPrice - $effectivePrice) / $regularPrice) * 100));
    }

    protected function formatPromoPeriod(Product $product): ?string
    {
        $start = $product->promo_start_at;
        $end = $product->promo_end_at;

        if ($start && $end) {
            return 'Promo berlaku '.$start->translatedFormat('d M Y').' - '.$end->translatedFormat('d M Y');
        }

        if ($end) {
            return 'Promo berlaku hingga '.$end->translatedFormat('d M Y');
        }

        if ($start) {
            return 'Promo mulai '.$start->translatedFormat('d M Y');
        }

        return null;
    }

    protected function parseSpecifications(?string $raw): array
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return [
                'type' => 'empty',
                'rows' => [],
                'text' => '',
            ];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $decoded !== [] && ! array_is_list($decoded)) {
            $rows = [];
            foreach ($decoded as $key => $value) {
                if (is_bool($value)) {
                    $formattedValue = $value ? 'Ya' : 'Tidak';
                } elseif (is_array($value)) {
                    $formattedValue = implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : '', $value));
                } elseif (is_scalar($value) || $value === null) {
                    $formattedValue = trim((string) $value);
                } else {
                    $formattedValue = '';
                }

                if ($formattedValue === '') {
                    continue;
                }

                $rows[] = [
                    'label' => trim((string) $key),
                    'value' => $formattedValue,
                ];
            }

            if ($rows !== []) {
                return [
                    'type' => 'table',
                    'rows' => $rows,
                    'text' => '',
                ];
            }
        }

        return [
            'type' => 'text',
            'rows' => [],
            'text' => $trimmed,
        ];
    }

    protected function loadApprovedReviews(int $productId): array
    {
        $baseQuery = ProductReview::query()
            ->where('product_id', $productId)
            ->where('is_approved', true);

        $stats = (clone $baseQuery)
            ->selectRaw('COUNT(*) as total_count, AVG(rating) as avg_rating')
            ->first();

        $totalCount = (int) ($stats->total_count ?? 0);
        $avgRating = ($totalCount > 0 && $stats->avg_rating !== null)
            ? round((float) $stats->avg_rating, 1)
            : null;

        $items = (clone $baseQuery)
            ->with('customer:id,name')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (ProductReview $review) => [
                'id' => (int) $review->id,
                'customer_name' => $review->customer?->name ?: 'Pelanggan Terverifikasi',
                'rating' => max(1, min(5, (int) $review->rating)),
                'comment' => $review->comment,
                'created_at_label' => $review->created_at?->translatedFormat('d M Y') ?? '',
            ]);

        return [
            'count' => $totalCount,
            'average_rating' => $avgRating,
            'items' => $items,
        ];
    }

    protected function loadRelatedProducts(Product $product): Collection
    {
        $relations = [
            'images',
            'categories' => fn ($q) => $q->where('categories.is_active', true)->orderBy('categories.sort_order'),
            'variants' => fn ($q) => $q->where('product_variants.is_active', true),
        ];

        $categoryIds = $product->categories->pluck('id')->filter()->values()->all();
        $related = collect();

        if ($categoryIds !== []) {
            $related = Product::query()
                ->with($relations)
                ->activeAndSellable()
                ->whereKeyNot($product->id)
                ->whereHas('categories', function (Builder $q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                })
                ->latest('products.id')
                ->limit(4)
                ->get();
        }

        if ($related->count() < 4) {
            $excludeIds = $related->pluck('id')->push($product->id)->unique()->values()->all();
            $remaining = 4 - $related->count();

            $fallback = Product::query()
                ->with($relations)
                ->activeAndSellable()
                ->whereNotIn('products.id', $excludeIds)
                ->latest('products.id')
                ->limit($remaining)
                ->get();

            $related = $related->concat($fallback);
        }

        return $related->unique('id')->take(4)->values();
    }

    protected function buildBreadcrumbs(Product $product): array
    {
        $breadcrumbs = [
            ['label' => 'Beranda', 'url' => route('storefront.home')],
            ['label' => 'Katalog Produk', 'url' => route('storefront.catalog')],
        ];

        $primaryCategory = $product->categories
            ->first(fn (Category $cat) => (bool) ($cat->pivot?->is_primary ?? false))
            ?? $product->categories->first();

        if ($primaryCategory) {
            $activeCategories = Category::query()
                ->where('is_active', true)
                ->get(['id', 'parent_id', 'name', 'slug'])
                ->keyBy('id');

            $chain = [];
            $visited = [];
            $current = $activeCategories->get($primaryCategory->id) ?? $primaryCategory;

            while ($current && ! isset($visited[$current->id])) {
                $visited[$current->id] = true;
                array_unshift($chain, [
                    'label' => $current->name,
                    'url' => route('storefront.category', $current->slug),
                ]);
                $current = $current->parent_id ? $activeCategories->get($current->parent_id) : null;
            }

            $breadcrumbs = array_merge($breadcrumbs, $chain);
        }

        $breadcrumbs[] = [
            'label' => $product->name,
            'url' => route('storefront.product', $product->slug),
        ];

        return $breadcrumbs;
    }

    protected function buildBreadcrumbSchema(array $breadcrumbs): array
    {
        $elements = [];
        foreach (array_values($breadcrumbs) as $index => $crumb) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    protected function buildProductSchema(
        Product $product,
        Collection $gallery,
        Collection $variants,
        int $availableStock,
        float $effectivePrice,
        array $reviewSummary,
        string $canonical,
        string $seoDescription,
        string $storeName
    ): array {
        $availability = $availableStock > 0
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock';

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $seoDescription,
            'url' => $canonical,
            'brand' => [
                '@type' => 'Brand',
                'name' => $storeName,
            ],
        ];

        $images = $gallery->pluck('image_url')->filter()->values()->all();
        if ($images !== []) {
            $schema['image'] = $images;
        }

        if ($product->has_variants) {
            $sellablePool = $variants->where('stock', '>', 0)->filter(fn ($v) => (float) $v['effective_price'] > 0);
            $validPool = $sellablePool->isNotEmpty()
                ? $sellablePool
                : $variants->filter(fn ($v) => (float) $v['effective_price'] > 0);

            if ($validPool->isNotEmpty()) {
                $lowPrice = (float) $validPool->min('effective_price');
                $highPrice = (float) $validPool->max('effective_price');

                $schema['offers'] = [
                    '@type' => 'AggregateOffer',
                    'url' => $canonical,
                    'priceCurrency' => 'IDR',
                    'lowPrice' => number_format($lowPrice, 2, '.', ''),
                    'highPrice' => number_format($highPrice, 2, '.', ''),
                    'offerCount' => $validPool->count(),
                    'availability' => $availability,
                    'itemCondition' => 'https://schema.org/NewCondition',
                ];
            } else {
                $schema['offers'] = [
                    '@type' => 'Offer',
                    'url' => $canonical,
                    'priceCurrency' => 'IDR',
                    'availability' => 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                ];
            }
        } else {
            $schema['offers'] = [
                '@type' => 'Offer',
                'url' => $canonical,
                'priceCurrency' => 'IDR',
                'price' => number_format($effectivePrice, 2, '.', ''),
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
            ];
        }

        if (($reviewSummary['count'] ?? 0) > 0 && $reviewSummary['average_rating'] !== null) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $reviewSummary['average_rating'], 1, '.', ''),
                'reviewCount' => (int) $reviewSummary['count'],
            ];
        }

        return $schema;
    }

    protected function resolveMetaDescription(Product $product): string
    {
        $seoDesc = trim((string) ($product->seo_description ?? ''));
        if ($seoDesc !== '') {
            return $seoDesc;
        }

        $shortDesc = trim(strip_tags((string) ($product->short_description ?? '')));
        if ($shortDesc !== '') {
            return Str::limit($shortDesc, 160);
        }

        $plainDesc = trim(strip_tags((string) ($product->description ?? '')));
        if ($plainDesc !== '') {
            return Str::limit($plainDesc, 160);
        }

        return 'Beli '.$product->name.' asli UMKM dengan pembayaran aman dan pengiriman terpercaya.';
    }

    protected function resolveStoreName(): string
    {
        try {
            return (string) ($this->settingService->publicSettings()['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara'));
        } catch (Throwable) {
            return (string) config('app.name', 'Pasar Lokal Nusantara');
        }
    }
}
