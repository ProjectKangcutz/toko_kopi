@extends('layouts.storefront')

@section('title', $seo['title'])
@section('meta_description', $seo['description'])
@section('canonical', $seo['canonical'])
@section('robots', $seo['robots'])
@section('og_type', $seo['og_type'])
@section('og_title', $seo['title'])
@section('og_description', $seo['description'])
@if(!empty($seo['og_image']))
    @section('og_image', $seo['og_image'])
@endif

@push('structured_data')
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script type="application/ld+json">{!! json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@php
    $variantsArray = $variants->values()->all();
    $galleryArray = $gallery->values()->all();
    $initialPrimaryImage = $galleryArray[0] ?? null;
    $hasSellableStock = $availableStock > 0 && (! $product->has_variants || $initialVariantId !== null);
    $hasDimensions = $product->length_cm || $product->width_cm || $product->height_cm;
@endphp

@section('content')
<div class="container py-3 py-md-4">
    <x-breadcrumb :items="$breadcrumbs" />

    <section
        class="mlm-card p-3 p-md-4 mb-4"
        x-data="productDetailPage({
            productId: {{ (int) $product->id }},
            productName: @js($product->name),
            productSku: @js($product->sku),
            productWeightGrams: {{ (int) $product->weight_grams }},
            hasVariants: {{ $product->has_variants ? 'true' : 'false' }},
            variants: @js($variantsArray),
            initialVariantId: {{ $initialVariantId !== null ? (int) $initialVariantId : 'null' }},
            gallery: @js($galleryArray),
            basePricing: {
                effective_price: {{ (float) $pricing['effective_price'] }},
                regular_price: {{ (float) $pricing['regular_price'] }},
                is_promo: {{ $pricing['is_promo'] ? 'true' : 'false' }},
                discount_percent: {{ (int) $pricing['discount_percent'] }},
                promo_period_label: @js($pricing['promo_period_label']),
            },
            baseStock: {{ (int) $availableStock }},
            addToCartUrl: @js(route('storefront.cart.add')),
        })"
    >
        <div class="row g-4">
            {{-- 1. Galeri Produk --}}
            <div class="col-12 col-lg-6">
                <div class="position-relative rounded-3 overflow-hidden bg-mlm-secondary mlm-gallery-main">
                    @if($initialPrimaryImage)
                        <picture x-show="!activeVariantImageUrl">
                            <source
                                :srcset="currentGalleryItem && currentGalleryItem.webp_url ? currentGalleryItem.webp_url : null"
                                @if(!empty($initialPrimaryImage['webp_url']))
                                    srcset="{{ $initialPrimaryImage['webp_url'] }}"
                                @endif
                                type="image/webp"
                            >
                            <img
                                :src="currentGalleryItem ? currentGalleryItem.image_url : @js($initialPrimaryImage['image_url'])"
                                :alt="currentGalleryItem ? currentGalleryItem.alt : @js($initialPrimaryImage['alt'])"
                                src="{{ $initialPrimaryImage['image_url'] }}"
                                alt="{{ $initialPrimaryImage['alt'] }}"
                                class="w-100 h-100 object-fit-cover"
                                loading="eager"
                                decoding="async"
                            >
                        </picture>
                        <img
                            x-show="activeVariantImageUrl"
                            x-cloak
                            :src="activeVariantImageUrl || ''"
                            :alt="activeAlt"
                            class="w-100 h-100 object-fit-cover"
                            loading="eager"
                            decoding="async"
                        >
                    @else
                        <template x-if="activeVariantImageUrl">
                            <img
                                :src="activeVariantImageUrl"
                                :alt="productName"
                                class="w-100 h-100 object-fit-cover"
                                loading="eager"
                                decoding="async"
                            >
                        </template>
                        <div x-show="!activeVariantImageUrl" class="w-100 h-100">
                            <div class="w-100 h-100 d-flex align-items-center justify-content-center text-mlm-primary">
                                <i class="bi bi-image display-3 opacity-50" aria-hidden="true"></i>
                            </div>
                        </div>
                    @endif

                    <span
                        x-show="currentIsPromo"
                        x-cloak
                        class="badge position-absolute top-0 start-0 m-3 fw-semibold fs-6"
                        style="background-color: var(--mlm-accent); color: #1f241f;"
                    >
                        Promo <span x-show="currentDiscountPercent > 0" x-text="'-' + currentDiscountPercent + '%'"></span>
                    </span>

                    @if($pricing['is_promo'])
                        <noscript>
                            <span class="badge position-absolute top-0 start-0 m-3 fw-semibold fs-6" style="background-color: var(--mlm-accent); color: #1f241f;">
                                Promo @if($pricing['discount_percent'] > 0)-{{ $pricing['discount_percent'] }}%@endif
                            </span>
                        </noscript>
                    @endif

                    <span
                        x-show="currentStock <= 0"
                        @if($hasSellableStock) x-cloak @endif
                        class="badge bg-secondary position-absolute top-0 end-0 m-3 fs-6"
                    >
                        Stok Habis
                    </span>
                </div>

                @if($gallery->count() > 1)
                    <div class="d-flex flex-wrap gap-2 mt-3" role="list" aria-label="Thumbnail gambar produk">
                        @foreach($gallery as $index => $image)
                            <button
                                type="button"
                                class="mlm-gallery-thumb p-0"
                                :class="{ 'active': !activeVariantImageUrl && activeGalleryIndex === {{ $index }} }"
                                @click="selectGalleryIndex({{ $index }})"
                                aria-label="Lihat gambar {{ $index + 1 }} untuk {{ $product->name }}"
                            >
                                <img
                                    src="{{ $image['image_url'] }}"
                                    alt="{{ $image['alt'] }}"
                                    class="w-100 h-100 object-fit-cover"
                                    loading="lazy"
                                    decoding="async"
                                >
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- 2. Ringkasan Informasi & Form Keranjang --}}
            <div class="col-12 col-lg-6 d-flex flex-column">
                @if($product->categories->isNotEmpty())
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        @foreach($product->categories as $category)
                            <a href="{{ route('storefront.category', $category->slug) }}" class="badge text-decoration-none bg-mlm-secondary text-mlm-primary fw-semibold px-2 py-1">
                                {{ $category->name }}
                            </a>
                        @endforeach
                    </div>
                @endif

                <h1 class="h2 fw-bold text-mlm-primary mb-2">{{ $product->name }}</h1>

                <div class="d-flex flex-wrap align-items-center gap-3 small text-mlm-muted mb-3">
                    <span>SKU: <strong class="text-body" x-text="currentSku">{{ $product->sku }}</strong></span>
                    @if($reviewSummary['count'] > 0 && $reviewSummary['average_rating'] !== null)
                        <span class="d-inline-flex align-items-center gap-1">
                            <i class="bi bi-star-fill text-mlm-accent" aria-hidden="true"></i>
                            <strong class="text-body">{{ number_format((float) $reviewSummary['average_rating'], 1, ',', '.') }}</strong>
                            <span>({{ $reviewSummary['count'] }} ulasan)</span>
                        </span>
                    @endif
                </div>

                @if(!empty($product->short_description))
                    <p class="text-mlm-muted mb-3">{{ $product->short_description }}</p>
                @endif

                {{-- Harga & Periode Promo --}}
                <div class="p-3 rounded-3 bg-mlm-secondary mb-3">
                    <div x-show="currentIsPromo" @if(!$pricing['is_promo']) x-cloak @endif class="mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-decoration-line-through text-mlm-muted small" x-text="formatRupiah(currentRegularPrice)">
                                Rp {{ number_format($pricing['regular_price'], 0, ',', '.') }}
                            </span>
                            <span
                                x-show="currentDiscountPercent > 0"
                                class="badge fw-semibold"
                                style="background-color: var(--mlm-accent); color: #1f241f;"
                                x-text="'Hemat ' + currentDiscountPercent + '%'"
                            >
                                @if($pricing['discount_percent'] > 0)Hemat {{ $pricing['discount_percent'] }}%@endif
                            </span>
                        </div>
                    </div>

                    <div class="fs-3 fw-bold text-mlm-primary" x-text="formatRupiah(currentEffectivePrice)">
                        Rp {{ number_format($pricing['effective_price'], 0, ',', '.') }}
                    </div>

                    <div
                        x-show="currentIsPromo && Boolean(currentPromoPeriodLabel)"
                        @if(!$pricing['is_promo'] || empty($pricing['promo_period_label'])) x-cloak @endif
                        class="small text-mlm-primary mt-1"
                    >
                        <i class="bi bi-clock me-1" aria-hidden="true"></i>
                        <span x-text="currentPromoPeriodLabel">{{ ($pricing['is_promo'] && !empty($pricing['promo_period_label'])) ? $pricing['promo_period_label'] : '' }}</span>
                    </div>
                </div>

                {{-- Status Stok, Berat & Dimensi --}}
                <div class="d-flex flex-wrap gap-3 small mb-3">
                    <div class="d-inline-flex align-items-center gap-1">
                        <i class="bi" :class="currentStock > 0 ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'" aria-hidden="true"></i>
                        <span x-show="currentStock > 0" @if(!$hasSellableStock) x-cloak @endif>
                            Stok Tersedia: <strong x-text="currentStock">{{ $availableStock }}</strong> unit
                        </span>
                        <span x-show="currentStock <= 0" @if($hasSellableStock) x-cloak @endif class="fw-semibold text-danger">
                            Stok Habis
                        </span>
                    </div>

                    <div class="text-mlm-muted">
                        <i class="bi bi-box-seam me-1" aria-hidden="true"></i>
                        Berat: <strong class="text-body" x-text="currentWeightGrams + ' gram'">{{ (int) $product->weight_grams }} gram</strong>
                    </div>

                    @if($hasDimensions)
                        <div class="text-mlm-muted">
                            <i class="bi bi-aspect-ratio me-1" aria-hidden="true"></i>
                            Dimensi:
                            <strong class="text-body">
                                {{ rtrim(rtrim(number_format((float) ($product->length_cm ?? 0), 2, '.', ''), '0'), '.') ?: '0' }}
                                &times;
                                {{ rtrim(rtrim(number_format((float) ($product->width_cm ?? 0), 2, '.', ''), '0'), '.') ?: '0' }}
                                &times;
                                {{ rtrim(rtrim(number_format((float) ($product->height_cm ?? 0), 2, '.', ''), '0'), '.') ?: '0' }} cm
                            </strong>
                        </div>
                    @endif
                </div>

                {{-- Form Tambah ke Keranjang --}}
                <form method="POST" action="{{ route('storefront.cart.add') }}" @submit.prevent="submitCart" class="mt-auto">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ (int) $product->id }}">

                    @if($product->has_variants)
                        <div class="mb-3">
                            <label class="form-label fw-semibold d-block mb-2">Pilih Varian</label>

                            @if($variants->isEmpty())
                                <div class="alert alert-warning small mb-0">
                                    Saat ini belum ada varian aktif yang tersedia untuk dibeli.
                                </div>
                            @else
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($variants as $variant)
                                        @php
                                            $variantDisabled = (int) $variant['stock'] <= 0;
                                            $variantSelected = $initialVariantId !== null && (int) $initialVariantId === (int) $variant['id'];
                                        @endphp
                                        <div class="mlm-variant-item">
                                            <input
                                                type="radio"
                                                class="btn-check"
                                                name="product_variant_id"
                                                id="variant-option-{{ $variant['id'] }}"
                                                value="{{ $variant['id'] }}"
                                                x-model.number="selectedVariantId"
                                                @change="onVariantSelected({{ $variant['id'] }})"
                                                @if($variantDisabled) disabled @endif
                                                @if($variantSelected) checked @endif
                                            >
                                            <label
                                                class="mlm-variant-chip {{ $variantDisabled ? 'disabled opacity-50' : '' }}"
                                                for="variant-option-{{ $variant['id'] }}"
                                            >
                                                <span class="fw-semibold d-block">{{ $variant['name'] }}</span>
                                                <span class="small d-block opacity-75">
                                                    Rp {{ number_format($variant['effective_price'], 0, ',', '.') }}
                                                    @if($variantDisabled)
                                                        &bull; Habis
                                                    @else
                                                        &bull; Stok {{ $variant['stock'] }}
                                                    @endif
                                                </span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-12 col-sm-5">
                            <label for="product-quantity-input" class="form-label fw-semibold">Jumlah</label>
                            <div class="input-group mlm-qty-group">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary px-3"
                                    @click="decrementQty"
                                    :disabled="quantity <= 1 || !canAddToCart"
                                    aria-label="Kurangi jumlah"
                                >
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                                <input
                                    id="product-quantity-input"
                                    type="number"
                                    name="quantity"
                                    class="form-control text-center fw-semibold"
                                    value="1"
                                    min="1"
                                    :max="Math.max(1, currentStock)"
                                    x-model.number="quantity"
                                    @change="clampQuantity"
                                    :disabled="!canAddToCart"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-primary px-3"
                                    @click="incrementQty"
                                    :disabled="quantity >= currentStock || !canAddToCart"
                                    aria-label="Tambah jumlah"
                                >
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12 col-sm-7">
                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                :disabled="!canAddToCart || submitting"
                                @if(!$hasSellableStock) disabled @endif
                            >
                                <span x-show="!submitting">
                                    <span class="d-inline-flex align-items-center gap-2">
                                        <i class="bi bi-bag-plus"></i>
                                        <span x-text="canAddToCart ? 'Tambah ke Keranjang' : 'Stok Habis'">
                                            {{ $hasSellableStock ? 'Tambah ke Keranjang' : 'Stok Habis' }}
                                        </span>
                                    </span>
                                </span>
                                <span x-show="submitting" x-cloak>
                                    <span class="d-inline-flex align-items-center gap-2">
                                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                        <span>Menambahkan...</span>
                                    </span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div
                        x-show="errorMessage"
                        x-cloak
                        class="alert alert-danger py-2 px-3 small mb-0"
                        role="alert"
                        x-text="errorMessage"
                    ></div>
                </form>
            </div>
        </div>
    </section>

    {{-- 3. Deskripsi & Spesifikasi --}}
    <div class="row g-4 mb-4">
        <div class="col-12 {{ $specifications['type'] !== 'empty' ? 'col-lg-7' : '' }}">
            <section class="mlm-card p-3 p-md-4 h-100">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Deskripsi Produk</h2>
                @if(!empty($product->description))
                    <div class="text-body lh-lg">{!! nl2br(e($product->description)) !!}</div>
                @else
                    <p class="text-mlm-muted mb-0">Belum ada deskripsi rinci untuk produk ini.</p>
                @endif
            </section>
        </div>

        @if($specifications['type'] !== 'empty')
            <div class="col-12 col-lg-5">
                <section class="mlm-card p-3 p-md-4 h-100">
                    <h2 class="h5 fw-bold text-mlm-primary mb-3">Spesifikasi Produk</h2>
                    @if($specifications['type'] === 'table')
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0">
                                <tbody>
                                    @foreach($specifications['rows'] as $row)
                                        <tr class="border-bottom">
                                            <th scope="row" class="text-mlm-muted fw-medium py-2 ps-0" style="width: 42%;">{{ $row['label'] }}</th>
                                            <td class="fw-medium py-2 pe-0">{{ $row['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-body small lh-lg">{!! nl2br(e($specifications['text'])) !!}</div>
                    @endif
                </section>
            </div>
        @endif
    </div>

    {{-- 4. Ulasan Pelanggan yang Disetujui --}}
    <section class="mlm-card p-3 p-md-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 fw-bold text-mlm-primary mb-0">Ulasan Pelanggan</h2>
            @if($reviewSummary['count'] > 0 && $reviewSummary['average_rating'] !== null)
                <div class="d-inline-flex align-items-center gap-2 bg-mlm-secondary px-3 py-1 rounded-2">
                    <i class="bi bi-star-fill text-mlm-accent" aria-hidden="true"></i>
                    <span class="fw-bold text-mlm-primary">{{ number_format((float) $reviewSummary['average_rating'], 1, ',', '.') }} / 5</span>
                    <span class="small text-mlm-muted">({{ $reviewSummary['count'] }} ulasan terverifikasi)</span>
                </div>
            @endif
        </div>

        @if($reviewSummary['items']->isEmpty())
            <p class="text-mlm-muted small mb-0">Belum ada ulasan yang ditampilkan untuk produk ini.</p>
        @else
            <div class="d-flex flex-column gap-3">
                @foreach($reviewSummary['items'] as $review)
                    <article class="border-bottom pb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                            <div class="fw-semibold">{{ $review['customer_name'] }}</div>
                            @if(!empty($review['created_at_label']))
                                <small class="text-mlm-muted">{{ $review['created_at_label'] }}</small>
                            @endif
                        </div>
                        <div class="text-mlm-accent small mb-1" aria-label="Rating {{ $review['rating'] }} dari 5">
                            @for($star = 1; $star <= 5; $star++)
                                <i class="bi {{ $star <= $review['rating'] ? 'bi-star-fill' : 'bi-star' }}" aria-hidden="true"></i>
                            @endfor
                        </div>
                        @if(!empty($review['comment']))
                            <p class="small mb-0 text-body">{!! nl2br(e($review['comment'])) !!}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- 5. Produk Terkait --}}
    @if($relatedProducts->isNotEmpty())
        <section>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h4 fw-bold text-mlm-primary mb-0">Produk Terkait</h2>
                <a href="{{ route('storefront.catalog') }}" class="btn btn-sm btn-outline-primary">Lihat Katalog</a>
            </div>
            <div class="row g-3">
                @foreach($relatedProducts as $relatedProduct)
                    <div class="col-6 col-md-4 col-lg-3">
                        <x-product-card :product="$relatedProduct" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('productDetailPage', (config) => ({
        productId: config.productId,
        productName: config.productName,
        productSku: config.productSku,
        productWeightGrams: config.productWeightGrams,
        hasVariants: config.hasVariants,
        variants: config.variants || [],
        selectedVariantId: config.initialVariantId,
        gallery: config.gallery || [],
        activeGalleryIndex: 0,
        activeVariantImageUrl: null,
        basePricing: config.basePricing,
        baseStock: config.baseStock,
        addToCartUrl: config.addToCartUrl,
        quantity: 1,
        submitting: false,
        errorMessage: '',

        init() {
            const initialVariant = this.selectedVariant;
            if (initialVariant && initialVariant.image_url) {
                this.activeVariantImageUrl = initialVariant.image_url;
            }
        },

        get selectedVariant() {
            if (!this.hasVariants || !this.selectedVariantId) {
                return null;
            }
            return this.variants.find((variant) => Number(variant.id) === Number(this.selectedVariantId)) || null;
        },

        get currentGalleryItem() {
            return this.gallery[this.activeGalleryIndex] || this.gallery[0] || null;
        },

        get activeAlt() {
            const variant = this.selectedVariant;
            return variant ? `${this.productName} - ${variant.name}` : this.productName;
        },

        get currentSku() {
            return this.selectedVariant ? this.selectedVariant.sku : this.productSku;
        },

        get currentWeightGrams() {
            return this.selectedVariant ? Number(this.selectedVariant.weight_grams) : Number(this.productWeightGrams);
        },

        get currentStock() {
            if (this.hasVariants) {
                return this.selectedVariant ? Number(this.selectedVariant.stock) : 0;
            }
            return Number(this.baseStock);
        },

        get currentEffectivePrice() {
            return this.selectedVariant ? Number(this.selectedVariant.effective_price) : Number(this.basePricing.effective_price);
        },

        get currentRegularPrice() {
            return this.selectedVariant ? Number(this.selectedVariant.regular_price) : Number(this.basePricing.regular_price);
        },

        get currentIsPromo() {
            return this.selectedVariant ? Boolean(this.selectedVariant.is_promo) : Boolean(this.basePricing.is_promo);
        },

        get currentDiscountPercent() {
            return this.selectedVariant ? Number(this.selectedVariant.discount_percent) : Number(this.basePricing.discount_percent);
        },

        get currentPromoPeriodLabel() {
            if (!this.currentIsPromo) {
                return '';
            }
            return this.selectedVariant
                ? (this.selectedVariant.promo_period_label || '')
                : (this.basePricing.promo_period_label || '');
        },

        get canAddToCart() {
            if (this.hasVariants) {
                return Boolean(this.selectedVariant) && this.currentStock > 0;
            }
            return this.currentStock > 0;
        },

        selectGalleryIndex(index) {
            this.activeVariantImageUrl = null;
            this.activeGalleryIndex = index;
        },

        onVariantSelected(variantId) {
            this.selectedVariantId = Number(variantId);
            this.errorMessage = '';
            const variant = this.selectedVariant;
            if (variant && variant.image_url) {
                this.activeVariantImageUrl = variant.image_url;
            } else {
                this.activeVariantImageUrl = null;
            }
            this.clampQuantity();
        },

        decrementQty() {
            if (this.quantity > 1) {
                this.quantity -= 1;
            }
        },

        incrementQty() {
            if (this.quantity < this.currentStock) {
                this.quantity += 1;
            }
        },

        clampQuantity() {
            const max = Math.max(1, this.currentStock);
            let parsed = parseInt(this.quantity, 10);
            if (Number.isNaN(parsed) || parsed < 1) {
                parsed = 1;
            }
            if (parsed > max) {
                parsed = max;
            }
            this.quantity = parsed;
        },

        formatRupiah(value) {
            return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(Number(value) || 0));
        },

        async submitCart() {
            if (!this.canAddToCart || this.submitting) {
                return;
            }

            this.clampQuantity();
            this.submitting = true;
            this.errorMessage = '';

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const response = await fetch(this.addToCartUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        product_id: this.productId,
                        product_variant_id: this.hasVariants ? this.selectedVariantId : null,
                        quantity: this.quantity,
                    }),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
                    const message = firstError || data.message || 'Gagal menambahkan produk ke keranjang.';
                    this.errorMessage = message;
                    if (window.Alpine?.store('toast')) {
                        window.Alpine.store('toast').push(message, 'error');
                    }
                    return;
                }

                const newCount = Number(data.cart_item_count ?? 0);
                document.querySelectorAll('.mlm-cart-badge').forEach((badge) => {
                    badge.textContent = String(newCount);
                    const link = badge.closest('a');
                    if (link) {
                        link.setAttribute('aria-label', `Keranjang belanja (${newCount} item)`);
                    }
                });

                if (window.Alpine?.store('toast')) {
                    window.Alpine.store('toast').push(data.message || 'Produk berhasil ditambahkan ke keranjang.', 'success');
                }
            } catch (error) {
                const message = 'Terjadi gangguan jaringan. Silakan coba kembali.';
                this.errorMessage = message;
                if (window.Alpine?.store('toast')) {
                    window.Alpine.store('toast').push(message, 'error');
                }
            } finally {
                this.submitting = false;
            }
        },
    }));
});
</script>
@endpush
