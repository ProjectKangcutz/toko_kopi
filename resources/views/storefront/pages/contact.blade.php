@extends('layouts.storefront')

@php
    $storeName = $contact['store_name'] ?? ($storeSettings['store_name'] ?? 'Pasar Lokal Nusantara');
    $seoTitle = trim((string) ($page->seo_title ?? '')) !== ''
        ? $page->seo_title
        : $page->title.' — '.$storeName;
    $metaDescription = trim((string) ($page->seo_description ?? '')) !== ''
        ? $page->seo_description
        : 'Informasi kontak resmi, alamat toko, WhatsApp, email, dan jam operasional '.$storeName.'.';

    $waDigits = preg_replace('/\D+/', '', (string) ($contact['store_whatsapp'] ?? ''));
    if (str_starts_with($waDigits, '0')) {
        $waDigits = '62'.substr($waDigits, 1);
    }

    $socialLinks = array_filter([
        'Instagram' => [
            'url' => \App\Services\Storefront\HomepageService::sanitizeUrl($contact['social_instagram'] ?? null),
            'icon' => 'bi-instagram',
        ],
        'Facebook' => [
            'url' => \App\Services\Storefront\HomepageService::sanitizeUrl($contact['social_facebook'] ?? null),
            'icon' => 'bi-facebook',
        ],
        'TikTok' => [
            'url' => \App\Services\Storefront\HomepageService::sanitizeUrl($contact['social_tiktok'] ?? null),
            'icon' => 'bi-tiktok',
        ],
        'YouTube' => [
            'url' => \App\Services\Storefront\HomepageService::sanitizeUrl($contact['social_youtube'] ?? null),
            'icon' => 'bi-youtube',
        ],
    ], fn ($item) => !empty($item['url']));
@endphp

@section('title', $seoTitle)
@section('meta_description', $metaDescription)
@section('canonical', route('storefront.contact'))
@section('robots', $page->robots_meta ?: 'index,follow')
@section('og_title', $seoTitle)
@section('og_description', $metaDescription)

@section('content')
<div class="container py-4 py-md-5">
    <x-breadcrumb :items="[
        ['label' => 'Beranda', 'url' => route('storefront.home')],
        ['label' => $page->title],
    ]" />

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="mlm-card p-4 p-md-5 h-100">
                <span class="badge bg-mlm-secondary text-mlm-primary mb-2">Layanan Pelanggan</span>
                <h1 class="h2 fw-bold text-mlm-primary mb-3">{{ $page->title }}</h1>

                @if($sanitizedContent !== '')
                    <div class="mlm-prose mb-4">
                        {!! $sanitizedContent !!}
                    </div>
                @endif

                @if(!empty($contact['contact_additional_info']))
                    <div class="p-3 rounded-3 bg-mlm-secondary mb-4">
                        <h2 class="h6 fw-bold text-mlm-primary mb-1">Informasi Tambahan</h2>
                        <p class="small text-mlm-muted mb-0" style="white-space: pre-line;">{{ $contact['contact_additional_info'] }}</p>
                    </div>
                @endif

                <div class="border-top pt-4">
                    <h2 class="h6 fw-bold mb-2">Sudah Memiliki Akun Pelanggan?</h2>
                    <p class="small text-mlm-muted mb-3">
                        Masuk ke dashboard pelanggan untuk memantau pesanan, status pengiriman, dan alamat pengiriman Anda.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        @if(auth('customer')->check())
                            <a href="{{ Route::has('customer.support.index') ? route('customer.support.index') : route('customer.dashboard') }}" class="btn btn-primary">
                                <i class="bi bi-person-badge" aria-hidden="true"></i> Buka Dashboard Pelanggan
                            </a>
                        @else
                            <a href="{{ route('customer.login') }}" class="btn btn-primary">
                                <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Masuk Akun Pelanggan
                            </a>
                        @endif
                        <a href="{{ route('storefront.faq') }}" class="btn btn-outline-primary">
                            Baca FAQ
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="mlm-card p-4 h-100 d-flex flex-column gap-3">
                <h2 class="h5 fw-bold text-mlm-primary mb-1">Detail Kontak Toko</h2>

                @if(!empty($contact['store_address']))
                    <div class="d-flex gap-3">
                        <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="small fw-semibold">Alamat Toko</div>
                            <div class="small text-mlm-muted">{{ $contact['store_address'] }}</div>
                        </div>
                    </div>
                @endif

                @if(!empty($contact['store_hours']))
                    <div class="d-flex gap-3">
                        <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;">
                            <i class="bi bi-clock" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="small fw-semibold">Jam Operasional</div>
                            <div class="small text-mlm-muted">{{ $contact['store_hours'] }}</div>
                        </div>
                    </div>
                @endif

                @if($waDigits !== '')
                    <div class="d-flex gap-3">
                        <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;">
                            <i class="bi bi-whatsapp" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="small fw-semibold">WhatsApp</div>
                            <div class="small">
                                <a href="https://wa.me/{{ $waDigits }}" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-mlm-primary fw-semibold">
                                    {{ $contact['store_whatsapp'] }} <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                @if(!empty($contact['store_phone']))
                    <div class="d-flex gap-3">
                        <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;">
                            <i class="bi bi-telephone" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="small fw-semibold">Telepon</div>
                            <div class="small text-mlm-muted">{{ $contact['store_phone'] }}</div>
                        </div>
                    </div>
                @endif

                @if(!empty($contact['store_email']))
                    <div class="d-flex gap-3">
                        <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;">
                            <i class="bi bi-envelope" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="small fw-semibold">Email</div>
                            <div class="small">
                                <a href="mailto:{{ $contact['store_email'] }}" class="text-decoration-none text-mlm-primary">
                                    {{ $contact['store_email'] }}
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                @if(count($socialLinks) > 0)
                    <div class="border-top pt-3 mt-auto">
                        <div class="small fw-semibold mb-2">Media Sosial Resmi</div>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($socialLinks as $label => $item)
                                <a
                                    href="{{ $item['url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i> {{ $label }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
