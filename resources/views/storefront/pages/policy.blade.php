@extends('layouts.storefront')

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $isPreviewMode = (bool) ($isPreview ?? false);
    $seoTitle = trim((string) ($page->seo_title ?? '')) !== ''
        ? $page->seo_title
        : $page->title.' — '.$storeName;
    $metaDescription = trim((string) ($page->seo_description ?? '')) !== ''
        ? $page->seo_description
        : \Illuminate\Support\Str::limit(strip_tags((string) $page->content), 155);
    $bannerUrl = \App\Services\Storefront\HomepageService::mediaUrl($page->banner_image_path ?? null);
@endphp

@section('title', $seoTitle)
@section('meta_description', $metaDescription)
@section('canonical', route('storefront.page', $page->slug))
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
                    {{ $page->is_published ? 'Enabled (Aktif)' : 'Disabled (Nonaktif)' }}
                </span>
            </div>
            @if(!empty($previewBackUrl))
                <a href="{{ $previewBackUrl }}" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali ke Editor Halaman
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

    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <div class="mlm-card overflow-hidden">
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
                    <h1 class="h2 fw-bold text-mlm-primary mb-2">{{ $page->title }}</h1>
                    <div class="small text-mlm-muted mb-4">
                        Terakhir diperbarui: {{ ($page->updated_at ?? now())->translatedFormat('d F Y') }}
                    </div>

                    <div class="mlm-prose">
                        {!! $sanitizedContent !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
