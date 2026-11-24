@extends('layouts.storefront')

@section('title', $seo['title'])
@section('meta_description', $seo['description'])
@section('canonical', $seo['canonical'])
@section('robots', $seo['robots'])
@section('og_title', $seo['title'])
@section('og_description', $seo['description'])
@if(!empty($seo['og_image']))
    @section('og_image', $seo['og_image'])
@endif

@push('structured_data')
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<div class="container py-3 py-md-4">
    <x-breadcrumb :items="$breadcrumbs" />

    <section class="mlm-card p-4 mb-4 bg-mlm-secondary border-0 overflow-hidden">
        <div class="row align-items-center g-3">
            <div class="col-12 {{ $categoryImageUrl ? 'col-lg-8' : '' }}">
                <h1 class="h2 fw-bold text-mlm-primary mb-2">{{ $category->name }}</h1>
                @if(!empty($category->description))
                    <p class="text-mlm-muted small mb-2" style="max-width: 700px;">{{ $category->description }}</p>
                @endif
                <div class="small fw-semibold text-mlm-primary">
                    @if($products->total() > 0 && $products->isNotEmpty())
                        Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ number_format($products->total(), 0, ',', '.') }} produk tersedia
                    @else
                        {{ number_format($products->total(), 0, ',', '.') }} produk tersedia
                    @endif
                </div>
            </div>

            @if($categoryImageUrl)
                <div class="col-12 col-lg-4">
                    <img
                        src="{{ $categoryImageUrl }}"
                        alt="{{ $category->name }}"
                        class="w-100 rounded-3 object-fit-cover"
                        style="max-height: 180px;"
                        loading="eager"
                        decoding="async"
                    >
                </div>
            @endif
        </div>
    </section>

    @if($subcategories->isNotEmpty())
        <section class="mb-4">
            <h2 class="h6 fw-bold text-uppercase text-mlm-muted mb-2">Subkategori {{ $category->name }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @foreach($subcategories as $subcategory)
                    <a href="{{ route('storefront.category', $subcategory->slug) }}" class="mlm-category-chip">
                        <span>{{ $subcategory->name }}</span>
                        <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary ms-1">{{ (int) ($subcategory->active_products_count ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 fw-bold mb-0">Produk Kategori {{ $category->name }}</h2>
            <a href="{{ route('storefront.catalog') }}" class="btn btn-sm btn-outline-primary">Semua Produk</a>
        </div>

        @if($products->total() > 0 && $products->isEmpty())
            <x-empty-state
                icon="bi-file-earmark-x"
                title="Halaman Tidak Memiliki Produk"
                message="Nomor halaman yang Anda buka melebihi jumlah halaman pada kategori ini."
                action-label="Kembali ke Halaman 1"
                :action-url="route('storefront.category', $category->slug)"
            />
        @elseif($products->isEmpty())
            <x-empty-state
                icon="bi-grid"
                title="Belum Ada Produk di Kategori Ini"
                message="Saat ini belum ada produk aktif dengan stok tersedia pada kategori {{ $category->name }}."
                action-label="Lihat Semua Produk"
                :action-url="route('storefront.catalog')"
            />
        @else
            <div class="row g-3">
                @foreach($products as $product)
                    <div class="col-6 col-md-4 col-lg-3">
                        <x-product-card :product="$product" />
                    </div>
                @endforeach
            </div>

            <x-pagination :paginator="$products" />
        @endif
    </section>
</div>
@endsection
