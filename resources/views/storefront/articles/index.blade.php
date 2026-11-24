@extends('layouts.storefront')

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $pageTitle = 'Artikel & Cerita UMKM — '.$storeName;
    $metaDescription = 'Kumpulan artikel, panduan belanja, dan cerita produk UMKM lokal Nusantara dari '.$storeName.'.';
@endphp

@section('title', $pageTitle)
@section('meta_description', $metaDescription)
@section('canonical', route('storefront.articles'))
@section('og_title', $pageTitle)
@section('og_description', $metaDescription)

@section('content')
<div class="container py-4 py-md-5">
    <x-breadcrumb :items="[
        ['label' => 'Beranda', 'url' => route('storefront.home')],
        ['label' => 'Artikel'],
    ]" />

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-mlm-primary mb-1">Artikel &amp; Cerita UMKM</h1>
            <p class="text-mlm-muted mb-0">Tips produk lokal, inspirasi kuliner dan kriya, serta kabar terbaru dari {{ $storeName }}.</p>
        </div>

        <form method="GET" action="{{ route('storefront.articles') }}" class="d-flex gap-2" style="min-width: min(100%, 340px);">
            <input
                type="search"
                name="q"
                value="{{ $search }}"
                class="form-control"
                placeholder="Cari judul artikel..."
                aria-label="Cari artikel"
            >
            <button type="submit" class="btn btn-primary flex-shrink-0">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="d-none d-sm-inline">Cari</span>
            </button>
            @if($search !== '')
                <a href="{{ route('storefront.articles') }}" class="btn btn-outline-secondary flex-shrink-0" aria-label="Reset pencarian">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </form>
    </div>

    @if($articles->isEmpty())
        <div class="mlm-card p-4 p-md-5">
            <x-empty-state
                icon="bi-journal-text"
                title="{{ $search !== '' ? 'Artikel Tidak Ditemukan' : 'Belum Ada Artikel Dipublikasikan' }}"
                message="{{ $search !== '' ? 'Tidak ada artikel yang sesuai dengan kata kunci pencarian Anda.' : 'Artikel dan cerita UMKM terbaru akan segera hadir di sini.' }}"
                :action-label="$search !== '' ? 'Lihat Semua Artikel' : 'Jelajahi Produk'"
                :action-url="$search !== '' ? route('storefront.articles') : route('storefront.catalog')"
            />
        </div>
    @else
        <div class="row g-4">
            @foreach($articles as $article)
                @php
                    $articleUrl = route('storefront.article', $article->slug);
                    $articleImage = \App\Services\Storefront\HomepageService::mediaUrl($article->featured_image_path);
                @endphp
                <div class="col-12 col-md-6 col-lg-4">
                    <article class="mlm-card h-100 d-flex flex-column overflow-hidden">
                        @if($articleImage)
                            <a href="{{ $articleUrl }}" class="d-block bg-mlm-secondary overflow-hidden">
                                <img
                                    src="{{ $articleImage }}"
                                    alt="{{ $article->featured_image_alt ?: $article->title }}"
                                    class="w-100 object-fit-cover"
                                    style="height: 210px;"
                                    loading="lazy"
                                    decoding="async"
                                >
                            </a>
                        @else
                            <a href="{{ $articleUrl }}" class="d-flex align-items-center justify-content-center bg-mlm-secondary text-mlm-primary text-decoration-none" style="height: 210px;">
                                <i class="bi bi-journal-richtext fs-1" aria-hidden="true"></i>
                            </a>
                        @endif

                        <div class="p-3 p-md-4 d-flex flex-column flex-grow-1">
                            <div class="small text-mlm-muted mb-2">
                                <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
                                {{ ($article->published_at ?? $article->created_at)?->translatedFormat('d M Y') }}
                                @if($article->author)
                                    &bull; {{ $article->author->name }}
                                @endif
                            </div>
                            <h2 class="h5 fw-bold mb-2">
                                <a href="{{ $articleUrl }}" class="text-decoration-none text-reset">{{ $article->title }}</a>
                            </h2>
                            @if(!empty($article->excerpt))
                                <p class="small text-mlm-muted mb-3 flex-grow-1">{{ \Illuminate\Support\Str::limit($article->excerpt, 140) }}</p>
                            @else
                                <p class="small text-mlm-muted mb-3 flex-grow-1">{{ \Illuminate\Support\Str::limit(strip_tags((string) $article->content), 140) }}</p>
                            @endif

                            <a href="{{ $articleUrl }}" class="small fw-semibold text-mlm-primary text-decoration-none mt-auto">
                                Baca selengkapnya <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            <x-pagination :paginator="$articles" />
        </div>
    @endif
</div>
@endsection
