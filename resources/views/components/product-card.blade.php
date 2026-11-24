@props([
    'product',
    'eager' => false,
])

@php
    $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
    $pricing = $product->displayPricing();
    $effectivePrice = $pricing['effective_price'];
    $regularPrice = $pricing['regular_price'];
    $isPromo = $pricing['is_promo'];
    $startsFrom = $pricing['starts_from'];
    $discountPercent = ($isPromo && $regularPrice > 0)
        ? max(1, (int) round((($regularPrice - $effectivePrice) / $regularPrice) * 100))
        : 0;
    $availableStock = $product->availableStock();
    $validSoldCount = (int) ($product->getAttribute('valid_sold_count') ?? 0);
    $productUrl = Route::has('storefront.product')
        ? route('storefront.product', $product->slug)
        : url('/produk/'.$product->slug);
    $primaryCategory = $product->relationLoaded('categories')
        ? ($product->categories->first(fn ($cat) => (bool) ($cat->pivot?->is_primary ?? false)) ?? $product->categories->first())
        : null;
@endphp

<article class="mlm-card mlm-product-card h-100 d-flex flex-column overflow-hidden position-relative">
    <a href="{{ $productUrl }}" class="position-relative d-block text-decoration-none bg-mlm-secondary overflow-hidden">
        @if($primaryImage)
            <picture>
                @if(!empty($primaryImage->webp_path))
                    <source srcset="{{ \App\Services\Storefront\HomepageService::mediaUrl($primaryImage->webp_path) }}" type="image/webp">
                @endif
                <img
                    src="{{ \App\Services\Storefront\HomepageService::mediaUrl($primaryImage->image_path) }}"
                    alt="{{ $primaryImage->alt_text ?: $product->name }}"
                    class="mlm-product-thumb"
                    loading="{{ $eager ? 'eager' : 'lazy' }}"
                    decoding="async"
                >
            </picture>
        @else
            <div class="mlm-product-thumb d-flex align-items-center justify-content-center text-mlm-primary">
                <i class="bi bi-image fs-1 opacity-50" aria-hidden="true"></i>
            </div>
        @endif

        @if($isPromo)
            <span class="badge position-absolute top-0 start-0 m-2 fw-semibold" style="background-color: var(--mlm-accent); color: #1f241f;">
                Promo @if($discountPercent > 0)-{{ $discountPercent }}%@endif
            </span>
        @endif

        @if($availableStock <= 0)
            <span class="badge bg-secondary position-absolute top-0 end-0 m-2">Stok Habis</span>
        @endif
    </a>

    <div class="p-3 d-flex flex-column flex-grow-1">
        @if($primaryCategory)
            <div class="small text-mlm-muted text-truncate mb-1">{{ $primaryCategory->name }}</div>
        @endif

        <h3 class="h6 fw-semibold mb-1 mlm-line-clamp-2">
            <a href="{{ $productUrl }}" class="text-decoration-none text-reset stretched-link">
                {{ $product->name }}
            </a>
        </h3>

        <div class="d-flex flex-wrap align-items-center gap-2 small text-mlm-muted mb-2">
            <span>Stok: {{ number_format($availableStock, 0, ',', '.') }}</span>
            @if($validSoldCount > 0)
                <span>&bull; Terjual {{ number_format($validSoldCount, 0, ',', '.') }}</span>
            @endif
        </div>

        <div class="mt-auto pt-1">
            @if($isPromo)
                <div class="small text-decoration-line-through text-mlm-muted">
                    Rp {{ number_format($regularPrice, 0, ',', '.') }}
                </div>
            @endif
            <div class="fw-bold text-mlm-primary fs-6">
                @if($startsFrom)
                    <span class="small fw-normal text-mlm-muted">Mulai</span>
                @endif
                Rp {{ number_format($effectivePrice, 0, ',', '.') }}
            </div>
        </div>
    </div>
</article>
