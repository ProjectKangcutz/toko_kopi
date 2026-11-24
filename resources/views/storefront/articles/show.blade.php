@extends('layouts.storefront')

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $isPreviewMode = (bool) ($isPreview ?? false);
    $seoTitle = trim((string) ($article->seo_title ?? '')) !== ''
        ? $article->seo_title
        : $article->title.' — '.$storeName;
    $metaDescription = trim((string) ($article->seo_description ?? '')) !== ''
        ? $article->seo_description
        : (\Illuminate\Support\Str::limit(trim((string) ($article->excerpt ?: strip_tags((string) $article->content))), 155));
    $canonical = trim((string) ($article->canonical_url ?? '')) !== ''
        ? $article->canonical_url
        : route('storefront.article', $article->slug);
    $featuredImageUrl = \App\Services\Storefront\HomepageService::mediaUrl($article->featured_image_path);

    $articleSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article->title,
        'description' => $metaDescription,
        'image' => $featuredImageUrl ? [$featuredImageUrl] : null,
        'datePublished' => ($article->published_at ?? $article->created_at)?->toIso8601String(),
        'dateModified' => $article->updated_at?->toIso8601String(),
        'author' => $article->author ? [
            '@type' => 'Person',
            'name' => $article->author->name,
        ] : [
            '@type' => 'Organization',
            'name' => $storeName,
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $storeName,
        ],
        'mainEntityOfPage' => $canonical,
    ]);

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Beranda',
                'item' => route('storefront.home'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Artikel',
                'item' => route('storefront.articles'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $article->title,
                'item' => $canonical,
            ],
        ],
    ];
@endphp

@section('title', $seoTitle)
@section('meta_description', $metaDescription)
@section('canonical', $canonical)
@section('robots', $isPreviewMode ? 'noindex,nofollow' : 'index,follow')
@section('og_type', 'article')
@section('og_title', $seoTitle)
@section('og_description', $metaDescription)
@if(!empty($featuredImageUrl))
    @section('og_image', $featuredImageUrl)
@endif

@push('structured_data')
    @unless($isPreviewMode)
        <script type="application/ld+json">{!! json_encode($articleSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endunless
@endpush

@section('content')
@if($isPreviewMode)
    <div class="bg-warning-subtle border-bottom py-2">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small fw-semibold text-dark">
                <i class="bi bi-eye me-1" aria-hidden="true"></i>
                Mode Pratinjau Admin — Status artikel saat ini:
                <span class="badge {{ $article->status === 'published' ? 'bg-success' : 'bg-secondary' }} ms-1">
                    {{ $article->status === 'published' ? 'Published' : 'Draft' }}
                </span>
            </div>
            @if(!empty($previewBackUrl))
                <a href="{{ $previewBackUrl }}" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali ke Editor Admin
                </a>
            @endif
        </div>
    </div>
@endif

<div class="container py-4 py-md-5">
    <x-breadcrumb :items="[
        ['label' => 'Beranda', 'url' => route('storefront.home')],
        ['label' => 'Artikel', 'url' => route('storefront.articles')],
        ['label' => $article->title],
    ]" />

    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <article class="mlm-card overflow-hidden">
                @if($featuredImageUrl)
                    <div class="bg-mlm-secondary overflow-hidden">
                        <img
                            src="{{ $featuredImageUrl }}"
                            alt="{{ $article->featured_image_alt ?: $article->title }}"
                            class="w-100 object-fit-cover"
                            style="max-height: 420px;"
                        >
                    </div>
                @endif

                <div class="p-4 p-md-5">
                    <div class="d-flex flex-wrap align-items-center gap-3 small text-mlm-muted mb-3">
                        <span>
                            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
                            {{ ($article->published_at ?? $article->created_at ?? now())->translatedFormat('d F Y') }}
                        </span>
                        @if($article->author)
                            <span>
                                <i class="bi bi-person me-1" aria-hidden="true"></i>
                                {{ $article->author->name }}
                            </span>
                        @endif
                    </div>

                    <h1 class="h2 fw-bold text-mlm-primary mb-3">{{ $article->title }}</h1>

                    @if(!empty($article->excerpt))
                        <p class="lead fs-6 text-mlm-muted border-start border-3 border-success ps-3 mb-4">{{ $article->excerpt }}</p>
                    @endif

                    <div class="mlm-prose">
                        {!! $sanitizedContent !!}
                    </div>
                </div>
            </article>

            <div class="d-flex justify-content-between align-items-center mt-4">
                <a href="{{ route('storefront.articles') }}" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Semua Artikel
                </a>
                <a href="{{ route('storefront.catalog') }}" class="btn btn-primary">
                    Jelajahi Produk UMKM <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            @if(isset($relatedArticles) && $relatedArticles->isNotEmpty())
                <section class="mt-5">
                    <h2 class="h5 fw-bold mb-3">Artikel Lainnya</h2>
                    <div class="row g-3">
                        @foreach($relatedArticles as $rel)
                            <div class="col-12 col-md-4">
                                <div class="mlm-card p-3 h-100 d-flex flex-column">
                                    <div class="small text-mlm-muted mb-1">
                                        {{ ($rel->published_at ?? $rel->created_at)?->translatedFormat('d M Y') }}
                                    </div>
                                    <h3 class="h6 fw-bold mb-2">
                                        <a href="{{ route('storefront.article', $rel->slug) }}" class="text-decoration-none text-reset">
                                            {{ $rel->title }}
                                        </a>
                                    </h3>
                                    <a href="{{ route('storefront.article', $rel->slug) }}" class="small fw-semibold text-mlm-primary text-decoration-none mt-auto">
                                        Baca <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
