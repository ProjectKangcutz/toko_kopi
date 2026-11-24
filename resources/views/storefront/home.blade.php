@extends('layouts.storefront')

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $catalogUrl = Route::has('storefront.catalog') ? route('storefront.catalog') : url('/produk');
    $articlesUrl = Route::has('storefront.articles') ? route('storefront.articles') : url('/artikel');
    $hasAnyProducts = $featuredProducts->isNotEmpty()
        || $latestProducts->isNotEmpty()
        || $bestsellingProducts->isNotEmpty()
        || $promoProducts->isNotEmpty();

    $firstHero = $heroBanners->first();
    $heroOgImage = $firstHero
        ? \App\Services\Storefront\HomepageService::mediaUrl($firstHero->image_desktop_path)
        : ($heroFallback['image_url'] ?? null);
@endphp

@section('title', $storeName.' — Beranda')
@section('meta_description', $seoMetaDescription)
@section('canonical', route('storefront.home'))
@section('og_title', $storeName.' — Beranda')
@section('og_description', $seoMetaDescription)
@if(!empty($heroOgImage))
    @section('og_image', $heroOgImage)
@endif

@section('content')
{{-- 1. Hero Section (Dikonfigurasi via Banner placement=hero atau Pengaturan Toko) --}}
<section class="container py-3 py-md-4">
    @if($heroBanners->isNotEmpty())
        <div id="heroBannerCarousel" class="carousel slide mlm-hero-carousel overflow-hidden rounded-4 border" data-bs-ride="carousel">
            @if($heroBanners->count() > 1)
                <div class="carousel-indicators">
                    @foreach($heroBanners as $banner)
                        <button
                            type="button"
                            data-bs-target="#heroBannerCarousel"
                            data-bs-slide-to="{{ $loop->index }}"
                            class="{{ $loop->first ? 'active' : '' }}"
                            aria-current="{{ $loop->first ? 'true' : 'false' }}"
                            aria-label="Slide {{ $loop->iteration }}: {{ $banner->title }}"
                        ></button>
                    @endforeach
                </div>
            @endif

            <div class="carousel-inner">
                @foreach($heroBanners as $banner)
                    @php
                        $desktopImg = \App\Services\Storefront\HomepageService::mediaUrl($banner->image_desktop_path);
                        $mobileImg = \App\Services\Storefront\HomepageService::mediaUrl($banner->image_mobile_path);
                        $ctaUrl = \App\Services\Storefront\HomepageService::sanitizeUrl($banner->cta_url);
                    @endphp
                    <div class="carousel-item {{ $loop->first ? 'active' : '' }}">
                        <div class="mlm-hero-slide position-relative">
                            @if($desktopImg)
                                <picture>
                                    @if($mobileImg)
                                        <source media="(max-width: 767.98px)" srcset="{{ $mobileImg }}">
                                    @endif
                                    <img
                                        src="{{ $desktopImg }}"
                                        alt="{{ $banner->alt_text ?: $banner->title }}"
                                        class="mlm-hero-img w-100"
                                        loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                                        @if($loop->first) fetchpriority="high" @endif
                                        decoding="async"
                                    >
                                </picture>
                            @endif

                            <div class="mlm-hero-overlay d-flex align-items-center">
                                <div class="p-4 p-md-5 text-white" style="max-width: 640px;">
                                    @if($loop->first)
                                        <h1 class="display-6 fw-bold mb-2">{{ $banner->title }}</h1>
                                    @else
                                        <h2 class="display-6 fw-bold mb-2">{{ $banner->title }}</h2>
                                    @endif

                                    @if(!empty($banner->subtitle))
                                        <p class="mb-3 opacity-90">{{ $banner->subtitle }}</p>
                                    @endif

                                    @if($ctaUrl && !empty($banner->cta_label))
                                        <a href="{{ $ctaUrl }}" class="btn btn-accent px-4">
                                            {{ $banner->cta_label }} <i class="bi bi-arrow-right"></i>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($heroBanners->count() > 1)
                <button class="carousel-control-prev" type="button" data-bs-target="#heroBannerCarousel" data-bs-slide="prev" aria-label="Banner sebelumnya">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#heroBannerCarousel" data-bs-slide="next" aria-label="Banner berikutnya">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                </button>
            @endif
        </div>
    @else
        <div class="mlm-card p-4 p-md-5 bg-mlm-secondary border-0">
            <div class="row align-items-center g-4">
                <div class="col-12 col-lg-7">
                    <span class="badge rounded-pill px-3 py-2 mb-3 fw-semibold" style="background-color: var(--mlm-accent); color: #1f241f;">
                        {{ $heroFallback['badge'] }}
                    </span>
                    <h1 class="display-6 fw-bold text-mlm-primary mb-3">
                        {{ $heroFallback['title'] }}
                    </h1>
                    @if(!empty($heroFallback['subtitle']))
                        <p class="text-mlm-muted mb-4">{{ $heroFallback['subtitle'] }}</p>
                    @endif
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ $heroFallback['cta_url'] }}" class="btn btn-primary px-4">
                            <i class="bi bi-bag"></i> {{ $heroFallback['cta_label'] }}
                        </a>
                        @guest('customer')
                            <a href="{{ route('customer.register') }}" class="btn btn-outline-primary px-4">
                                Daftar Pelanggan
                            </a>
                        @endguest
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    @if(!empty($heroFallback['image_url']))
                        <img
                            src="{{ $heroFallback['image_url'] }}"
                            alt="{{ $heroFallback['title'] }}"
                            class="img-fluid rounded-4 shadow-sm w-100 object-fit-cover"
                            style="max-height: 320px;"
                            loading="eager"
                            fetchpriority="high"
                            decoding="async"
                        >
                    @else
                        <div class="mlm-card p-4 text-center">
                            <i class="bi bi-basket2-fill text-mlm-primary display-3 mb-3 d-block" aria-hidden="true"></i>
                            <h2 class="h5 fw-bold mb-1">Produk Lokal Pilihan</h2>
                            <p class="small text-mlm-muted mb-0">
                                Langsung dari pengrajin &amp; produsen UMKM Indonesia dengan pengiriman ke seluruh nusantara.
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</section>

{{-- 2. Kategori Unggulan --}}
@if($categories->isNotEmpty())
    <section class="container py-4">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h4 fw-bold mb-1">Jelajahi Cita Rasa Nusantara</h2>
                <p class="small text-mlm-muted mb-0">Pilihan kopi dan perlengkapan seduh dari berbagai penjuru Indonesia</p>
            </div>
            <a href="{{ $catalogUrl }}" class="btn btn-sm btn-outline-primary flex-shrink-0">Semua Produk</a>
        </div>

        <div class="row g-3">
            @foreach($categories as $category)
                @php
                    $categoryUrl = Route::has('storefront.category')
                        ? route('storefront.category', $category->slug)
                        : url('/kategori/'.$category->slug);
                    $catImage = \App\Services\Storefront\HomepageService::mediaUrl($category->image_path);
                @endphp
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="{{ $categoryUrl }}" class="mlm-card mlm-category-card p-3 h-100 d-flex align-items-center gap-3 text-decoration-none text-reset">
                        @if($catImage)
                            <img
                                src="{{ $catImage }}"
                                alt="{{ $category->name }}"
                                class="rounded-3 object-fit-cover flex-shrink-0"
                                style="width: 56px; height: 56px;"
                                loading="lazy"
                                decoding="async"
                            >
                        @else
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-mlm-secondary text-mlm-primary flex-shrink-0" style="width: 56px; height: 56px;">
                                <i class="bi bi-grid fs-4" aria-hidden="true"></i>
                            </span>
                        @endif
                        <div class="min-w-0">
                            <h3 class="h6 fw-semibold mb-1 text-truncate">{{ $category->name }}</h3>
                            <span class="small text-mlm-muted">{{ (int) ($category->active_products_count ?? 0) }} produk</span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endif

@if(!$hasAnyProducts)
    {{-- 12. Empty State Saat Belum Ada Produk Aktif Terjual/Tersedia --}}
    <section class="container py-4">
        <x-empty-state
            icon="bi-box-seam"
            title="Katalog Produk Belum Tersedia"
            message="Saat ini belum ada produk aktif dengan stok tersedia untuk ditampilkan. Silakan kembali lagi nanti."
        />
    </section>
@else
    {{-- 3. Produk Promo Aktif --}}
    @if($promoProducts->isNotEmpty())
        <section class="container py-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-1">Penawaran Istimewa</h2>
                    <p class="small text-mlm-muted mb-0">Temukan kopi pilihan dengan harga terbaik</p>
                </div>
                <a href="{{ $catalogUrl }}?promo=1" class="btn btn-sm btn-outline-primary flex-shrink-0">Lihat Promo</a>
            </div>
            <div class="row g-3">
                @foreach($promoProducts as $product)
                    <div class="col-6 col-md-4 col-lg-3">
                        <x-product-card :product="$product" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 4. Produk Unggulan --}}
    @if($featuredProducts->isNotEmpty())
        <section id="katalog-unggulan" class="container py-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-1">Pilihan Barista Kami</h2>
                    <p class="small text-mlm-muted mb-0">Koleksi pilihan untuk pengalaman seduh yang lebih istimewa</p>
                </div>
                <a href="{{ $catalogUrl }}" class="btn btn-sm btn-outline-primary flex-shrink-0">Lihat Semua</a>
            </div>
            <div class="row g-3">
                @foreach($featuredProducts as $product)
                    <div class="col-6 col-md-4 col-lg-3">
                        <x-product-card :product="$product" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 5. Banner Promosi Tengah (placement=promo_middle) --}}
    @if($promoBanners->isNotEmpty())
        <section class="container py-3">
            <div class="row g-3">
                @foreach($promoBanners as $banner)
                    @php
                        $desktopImg = \App\Services\Storefront\HomepageService::mediaUrl($banner->image_desktop_path);
                        $mobileImg = \App\Services\Storefront\HomepageService::mediaUrl($banner->image_mobile_path);
                        $ctaUrl = \App\Services\Storefront\HomepageService::sanitizeUrl($banner->cta_url);
                    @endphp
                    <div class="{{ $promoBanners->count() === 1 ? 'col-12' : 'col-12 col-md-6' }}">
                        <div class="mlm-card mlm-promo-banner overflow-hidden position-relative border-0">
                            @if($desktopImg)
                                <picture>
                                    @if($mobileImg)
                                        <source media="(max-width: 767.98px)" srcset="{{ $mobileImg }}">
                                    @endif
                                    <img
                                        src="{{ $desktopImg }}"
                                        alt="{{ $banner->alt_text ?: $banner->title }}"
                                        class="w-100 object-fit-cover"
                                        style="height: 220px;"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                </picture>
                            @endif
                            <div class="mlm-promo-banner-overlay p-4 d-flex flex-column justify-content-end text-white">
                                <h3 class="h5 fw-bold mb-1">{{ $banner->title }}</h3>
                                @if(!empty($banner->subtitle))
                                    <p class="small mb-2 opacity-90">{{ $banner->subtitle }}</p>
                                @endif
                                @if($ctaUrl && !empty($banner->cta_label))
                                    <div>
                                        <a href="{{ $ctaUrl }}" class="btn btn-sm btn-accent">{{ $banner->cta_label }}</a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 6. Produk Terlaris Berdasarkan Transaksi Valid --}}
    @if($bestsellingProducts->isNotEmpty())
        <section class="container py-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-1">Paling Dicari Pecinta Kopi</h2>
                    <p class="small text-mlm-muted mb-0">Favorit pelanggan yang layak masuk ke cangkir Anda</p>
                </div>
                <a href="{{ $catalogUrl }}?sort=bestseller" class="btn btn-sm btn-outline-primary flex-shrink-0">Lihat Katalog</a>
            </div>
            <div class="row g-3">
                @foreach($bestsellingProducts as $product)
                    <div class="col-6 col-md-4 col-lg-3">
                        <x-product-card :product="$product" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- 7. Produk Terbaru --}}
    @if($latestProducts->isNotEmpty())
        <section class="container py-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h4 fw-bold mb-1">Baru Dipanggang, Baru Hadir</h2>
                    <p class="small text-mlm-muted mb-0">Temukan koleksi terbaru dari kedai dan roastery pilihan</p>
                </div>
                <a href="{{ $catalogUrl }}?sort=latest" class="btn btn-sm btn-outline-primary flex-shrink-0">Jelajahi Semua</a>
            </div>
            <div class="row g-3">
                @foreach($latestProducts as $product)
                    <div class="col-6 col-md-4 col-lg-3">
                        <x-product-card :product="$product" />
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endif

{{-- 8. Keunggulan Toko & Informasi Layanan --}}
<section id="keunggulan-toko" class="container py-4">
    <div class="mb-3">
        <h2 class="h4 fw-bold mb-1">Lebih dari Sekadar Secangkir Kopi</h2>
        <p class="small text-mlm-muted mb-0">Cerita asal, mutu pilihan, dan pengalaman belanja yang nyaman</p>
    </div>
    <div class="row g-3">
        @foreach($advantages as $advantage)
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="mlm-card p-4 h-100">
                    <i class="bi {{ $advantage['icon'] }} text-mlm-primary fs-2 mb-2 d-block" aria-hidden="true"></i>
                    <h3 class="h6 fw-bold mb-2">{{ $advantage['title'] }}</h3>
                    <p class="small text-mlm-muted mb-0">{{ $advantage['description'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- 9. Testimoni Disetujui --}}
@if($testimonials->isNotEmpty())
    <section class="container py-4">
        <div class="mb-3">
            <h2 class="h4 fw-bold mb-1">Kata Pelanggan Kami</h2>
            <p class="small text-mlm-muted mb-0">Pengalaman belanja nyata dari pelanggan di berbagai kota</p>
        </div>
        <div class="row g-3">
            @foreach($testimonials as $testimonial)
                @php
                    $rating = max(1, min(5, (int) $testimonial->rating));
                    $avatarUrl = \App\Services\Storefront\HomepageService::mediaUrl($testimonial->avatar_path);
                @endphp
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="mlm-card p-4 h-100 d-flex flex-column">
                        <div class="text-mlm-accent mb-2" aria-label="Rating {{ $rating }} dari 5">
                            @for($i = 1; $i <= 5; $i++)
                                <i class="bi {{ $i <= $rating ? 'bi-star-fill' : 'bi-star' }}" aria-hidden="true"></i>
                            @endfor
                        </div>
                        <blockquote class="small text-mlm-text mb-3 flex-grow-1">
                            &ldquo;{{ $testimonial->quote }}&rdquo;
                        </blockquote>
                        <div class="d-flex align-items-center gap-2 pt-2 border-top">
                            @if($avatarUrl)
                                <img
                                    src="{{ $avatarUrl }}"
                                    alt="{{ $testimonial->customer_name }}"
                                    class="rounded-circle object-fit-cover flex-shrink-0"
                                    style="width: 40px; height: 40px;"
                                    loading="lazy"
                                    decoding="async"
                                >
                            @else
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-mlm-secondary text-mlm-primary fw-bold flex-shrink-0" style="width: 40px; height: 40px;">
                                    {{ mb_strtoupper(mb_substr($testimonial->customer_name, 0, 1)) }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                <div class="fw-semibold small text-truncate">{{ $testimonial->customer_name }}</div>
                                @if(!empty($testimonial->customer_role_or_city))
                                    <div class="text-mlm-muted" style="font-size: 0.78rem;">{{ $testimonial->customer_role_or_city }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- 10. Artikel Terbaru --}}
@if($articles->isNotEmpty())
    <section class="container py-4">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h4 fw-bold mb-1">Artikel &amp; Cerita UMKM</h2>
                <p class="small text-mlm-muted mb-0">Tips produk, inspirasi lokal, dan kabar terbaru dari toko</p>
            </div>
            <a href="{{ $articlesUrl }}" class="btn btn-sm btn-outline-primary flex-shrink-0">Semua Artikel</a>
        </div>
        <div class="row g-3">
            @foreach($articles as $article)
                @php
                    $articleUrl = Route::has('storefront.article')
                        ? route('storefront.article', $article->slug)
                        : url('/artikel/'.$article->slug);
                    $articleImage = \App\Services\Storefront\HomepageService::mediaUrl($article->featured_image_path);
                @endphp
                <div class="col-12 col-md-4">
                    <article class="mlm-card h-100 d-flex flex-column overflow-hidden">
                        @if($articleImage)
                            <a href="{{ $articleUrl }}" class="d-block bg-mlm-secondary overflow-hidden">
                                <img
                                    src="{{ $articleImage }}"
                                    alt="{{ $article->featured_image_alt ?: $article->title }}"
                                    class="w-100 object-fit-cover"
                                    style="height: 190px;"
                                    loading="lazy"
                                    decoding="async"
                                >
                            </a>
                        @endif
                        <div class="p-3 p-md-4 d-flex flex-column flex-grow-1">
                            <div class="small text-mlm-muted mb-1">
                                {{ ($article->published_at ?? $article->created_at)?->translatedFormat('d M Y') }}
                                @if($article->author)
                                    &bull; {{ $article->author->name }}
                                @endif
                            </div>
                            <h3 class="h6 fw-bold mb-2">
                                <a href="{{ $articleUrl }}" class="text-decoration-none text-reset">{{ $article->title }}</a>
                            </h3>
                            @if(!empty($article->excerpt))
                                <p class="small text-mlm-muted mb-3 flex-grow-1">{{ \Illuminate\Support\Str::limit($article->excerpt, 120) }}</p>
                            @endif
                            <a href="{{ $articleUrl }}" class="small fw-semibold text-mlm-primary text-decoration-none mt-auto">
                                Baca selengkapnya <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- 11. CTA Penutup Menuju Katalog Produk --}}
<section class="container py-4 mb-4">
    <div class="mlm-card p-4 p-md-5 bg-mlm-secondary border-0 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
            <h2 class="h4 fw-bold text-mlm-primary mb-1">Temukan Produk Lokal Favorit Anda</h2>
            <p class="small text-mlm-muted mb-0">
                Jelajahi seluruh koleksi produk UMKM siap kirim dari {{ $storeName }}.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 flex-shrink-0">
            <a href="{{ $catalogUrl }}" class="btn btn-primary px-4">
                <i class="bi bi-grid"></i> Buka Katalog Produk
            </a>
        </div>
    </div>
</section>
@endsection
