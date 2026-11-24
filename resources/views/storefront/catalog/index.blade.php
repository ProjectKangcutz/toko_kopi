@extends('layouts.storefront')

@section('title', $seo['title'])
@section('meta_description', $seo['description'])
@section('canonical', $seo['canonical'])
@section('robots', $seo['robots'])
@section('og_title', $seo['title'])
@section('og_description', $seo['description'])

@push('structured_data')
    <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<div class="container py-3 py-md-4">
    <x-breadcrumb :items="$breadcrumbs" />

    <section class="mlm-card p-4 mb-4 bg-mlm-secondary border-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
            <div>
                <h1 class="h2 fw-bold text-mlm-primary mb-2">Katalog Produk</h1>
                <p class="text-mlm-muted small mb-0" style="max-width: 680px;">
                    {{ $storeSettings['store_description'] ?? 'Temukan seluruh koleksi produk UMKM lokal pilihan siap kirim.' }}
                </p>
            </div>
            <div class="small fw-semibold text-mlm-primary flex-shrink-0">
                @if($products->total() > 0 && $products->isNotEmpty())
                    Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ number_format($products->total(), 0, ',', '.') }} produk tersedia
                @else
                    {{ number_format($products->total(), 0, ',', '.') }} produk tersedia
                @endif
            </div>
        </div>
    </section>

    @if($categories->isNotEmpty())
        <section class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="h6 fw-bold text-uppercase text-mlm-muted mb-0">Jelajahi Kategori</h2>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('storefront.catalog') }}" class="mlm-category-chip active">
                    Semua Produk
                </a>
                @foreach($categories as $category)
                    <a href="{{ route('storefront.category', $category->slug) }}" class="mlm-category-chip">
                        <span>{{ $category->name }}</span>
                        <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary ms-1">{{ (int) ($category->active_products_count ?? 0) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 fw-bold mb-0">Daftar Produk Tersedia</h2>
        </div>

        @if($products->total() > 0 && $products->isEmpty())
            <x-empty-state
                icon="bi-file-earmark-x"
                title="Halaman Tidak Memiliki Produk"
                message="Nomor halaman yang Anda buka melebihi jumlah halaman katalog yang tersedia."
                action-label="Kembali ke Halaman 1"
                :action-url="route('storefront.catalog')"
            />
        @elseif($products->isEmpty())
            <x-empty-state
                icon="bi-box-seam"
                title="Belum Ada Produk Tersedia"
                message="Saat ini belum ada produk aktif dengan stok tersedia di katalog toko."
                action-label="Kembali ke Beranda"
                :action-url="route('storefront.home')"
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
