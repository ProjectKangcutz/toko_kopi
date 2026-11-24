@extends('layouts.storefront')

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $isPreviewMode = (bool) ($isPreview ?? false);
    $seoTitle = trim((string) ($page->seo_title ?? '')) !== ''
        ? $page->seo_title
        : $page->title.' — '.$storeName;
    $metaDescription = trim((string) ($page->seo_description ?? '')) !== ''
        ? $page->seo_description
        : ($aboutSettings['subtitle'] ?: \Illuminate\Support\Str::limit(strip_tags((string) $page->content), 155));
    $bannerUrl = \App\Services\Storefront\HomepageService::mediaUrl($aboutSettings['banner_path'] ?? null);
@endphp

@section('title', $seoTitle)
@section('meta_description', $metaDescription)
@section('canonical', route('storefront.about'))
@section('robots', $isPreviewMode ? 'noindex,nofollow' : ($page->robots_meta ?: 'index,follow'))
@section('og_title', $seoTitle)
@section('og_description', $metaDescription)
@if(!empty($bannerUrl))
    @section('og_image', $bannerUrl)
@endif

@section('content')
@if($isPreviewMode)
    <div class="bg-warning-subtle border-bottom py-2">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small fw-semibold text-dark">
                <i class="bi bi-eye me-1" aria-hidden="true"></i>
                Mode Pratinjau Admin — Status halaman:
                <span class="badge {{ $page->is_published ? 'bg-success' : 'bg-secondary' }} ms-1">
                    {{ $page->is_published ? 'Dipublikasikan' : 'Belum Dipublikasikan' }}
                </span>
            </div>
            @if(!empty($previewBackUrl))
                <a href="{{ $previewBackUrl }}" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali ke Editor Tentang Kami
                </a>
            @endif
        </div>
    </div>
@endif

<div class="container py-4 py-md-5">
    <x-breadcrumb :items="[
        ['label' => 'Beranda', 'url' => route('storefront.home')],
        ['label' => $page->title],
    ]" />

    <div class="mlm-card overflow-hidden mb-4">
        @if($bannerUrl)
            <div class="bg-mlm-secondary overflow-hidden">
                <img
                    src="{{ $bannerUrl }}"
                    alt="{{ $page->title }}"
                    class="w-100 object-fit-cover"
                    style="max-height: 360px;"
                >
            </div>
        @endif

        <div class="p-4 p-md-5">
            <span class="badge bg-mlm-secondary text-mlm-primary mb-2">{{ $storeName }}</span>
            <h1 class="h2 fw-bold text-mlm-primary mb-2">{{ $page->title }}</h1>
            @if(!empty($aboutSettings['subtitle']))
                <p class="lead fs-6 text-mlm-muted mb-4">{{ $aboutSettings['subtitle'] }}</p>
            @endif

            <div class="mlm-prose">
                {!! $sanitizedContent !!}
            </div>
        </div>
    </div>

    @if(!empty($aboutSettings['vision']) || !empty($aboutSettings['mission']))
        <div class="row g-4 mb-4">
            @if(!empty($aboutSettings['vision']))
                <div class="col-12 {{ !empty($aboutSettings['mission']) ? 'col-md-6' : '' }}">
                    <div class="mlm-card p-4 h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </span>
                            <h2 class="h5 fw-bold mb-0">Visi Kami</h2>
                        </div>
                        <p class="text-mlm-muted mb-0" style="white-space: pre-line;">{{ $aboutSettings['vision'] }}</p>
                    </div>
                </div>
            @endif

            @if(!empty($aboutSettings['mission']))
                <div class="col-12 {{ !empty($aboutSettings['vision']) ? 'col-md-6' : '' }}">
                    <div class="mlm-card p-4 h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                                <i class="bi bi-compass" aria-hidden="true"></i>
                            </span>
                            <h2 class="h5 fw-bold mb-0">Misi Kami</h2>
                        </div>
                        <p class="text-mlm-muted mb-0" style="white-space: pre-line;">{{ $aboutSettings['mission'] }}</p>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if(!empty($aboutSettings['business_profile']))
        <div class="mlm-card p-4 mb-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-2">Profil Usaha</h2>
            <p class="text-mlm-muted mb-0" style="white-space: pre-line;">{{ $aboutSettings['business_profile'] }}</p>
        </div>
    @endif

    <div class="mlm-card p-4 bg-mlm-secondary border-0 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <h2 class="h5 fw-bold text-mlm-primary mb-1">Ingin Berbelanja atau Bertanya Langsung?</h2>
            <p class="small text-mlm-muted mb-0">Lihat koleksi produk lokal pilihan kami atau hubungi layanan pelanggan.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('storefront.catalog') }}" class="btn btn-primary">Jelajahi Produk</a>
            <a href="{{ route('storefront.contact') }}" class="btn btn-outline-primary">Hubungi Kami</a>
        </div>
    </div>
</div>
@endsection
