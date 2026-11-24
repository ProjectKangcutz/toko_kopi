@extends('layouts.admin')

@section('title', 'Kelola Kontak Toko')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Kontak'],
]" />

@php
    $canManage = $admin->hasPermission('content.manage');
@endphp

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Informasi Kontak &amp; Halaman Kontak</h1>
        <p class="small text-mlm-muted mb-0">Kelola alamat toko, WhatsApp, telepon, email, jam operasional, media sosial, dan konten halaman /kontak.</p>
    </div>

    @if(($form['is_published'] ?? true))
        <a href="{{ route('storefront.contact') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Lihat Halaman Kontak
        </a>
    @endif
</div>

<form method="POST" action="{{ route('admin.contact.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <div class="mlm-card p-3 p-md-4 mb-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Saluran Kontak Resmi Toko</h2>
                <p class="small text-mlm-muted mb-3">
                    Data ini tampil di halaman <span class="font-monospace">/kontak</span> dan footer storefront secara otomatis.
                </p>

                <div class="mb-3">
                    <label for="contactAddress" class="form-label small fw-semibold">Alamat Toko / Gudang</label>
                    <textarea
                        id="contactAddress"
                        name="store_address"
                        rows="2"
                        class="form-control @error('store_address') is-invalid @enderror"
                        placeholder="Contoh: Jl. Merdeka No. 45, Bandung, Jawa Barat"
                    >{{ old('store_address', $form['store_address']) }}</textarea>
                    @error('store_address')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="contactWhatsapp" class="form-label small fw-semibold">Nomor WhatsApp</label>
                        <input
                            type="text"
                            id="contactWhatsapp"
                            name="store_whatsapp"
                            value="{{ old('store_whatsapp', $form['store_whatsapp']) }}"
                            class="form-control @error('store_whatsapp') is-invalid @enderror"
                            placeholder="Contoh: 6281234567890"
                        >
                        @error('store_whatsapp')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="contactPhone" class="form-label small fw-semibold">Telepon</label>
                        <input
                            type="text"
                            id="contactPhone"
                            name="store_phone"
                            value="{{ old('store_phone', $form['store_phone']) }}"
                            class="form-control @error('store_phone') is-invalid @enderror"
                            placeholder="Contoh: (022) 7654321"
                        >
                        @error('store_phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="contactEmail" class="form-label small fw-semibold">Email Layanan Pelanggan</label>
                        <input
                            type="email"
                            id="contactEmail"
                            name="store_email"
                            value="{{ old('store_email', $form['store_email']) }}"
                            class="form-control @error('store_email') is-invalid @enderror"
                            placeholder="cs@pasarlokal.id"
                        >
                        @error('store_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="contactHours" class="form-label small fw-semibold">Jam Operasional</label>
                        <input
                            type="text"
                            id="contactHours"
                            name="store_hours"
                            value="{{ old('store_hours', $form['store_hours']) }}"
                            class="form-control @error('store_hours') is-invalid @enderror"
                            placeholder="Senin - Sabtu, 08:00 - 17:00 WIB"
                        >
                        @error('store_hours')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="contactAdditionalInfo" class="form-label small fw-semibold">Informasi Kontak Tambahan</label>
                    <textarea
                        id="contactAdditionalInfo"
                        name="contact_additional_info"
                        rows="3"
                        class="form-control @error('contact_additional_info') is-invalid @enderror"
                        placeholder="Catatan pengiriman, waktu respon admin, atau informasi pengambilan pesanan..."
                    >{{ old('contact_additional_info', $form['contact_additional_info']) }}</textarea>
                    @error('contact_additional_info')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mlm-card p-3 p-md-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Tautan Media Sosial</h2>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="socialInstagram" class="form-label small fw-semibold">Instagram URL</label>
                        <input
                            type="url"
                            id="socialInstagram"
                            name="social_instagram"
                            value="{{ old('social_instagram', $form['social_instagram']) }}"
                            class="form-control @error('social_instagram') is-invalid @enderror"
                            placeholder="https://instagram.com/..."
                        >
                        @error('social_instagram')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="socialFacebook" class="form-label small fw-semibold">Facebook URL</label>
                        <input
                            type="url"
                            id="socialFacebook"
                            name="social_facebook"
                            value="{{ old('social_facebook', $form['social_facebook']) }}"
                            class="form-control @error('social_facebook') is-invalid @enderror"
                            placeholder="https://facebook.com/..."
                        >
                        @error('social_facebook')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="socialTiktok" class="form-label small fw-semibold">TikTok URL</label>
                        <input
                            type="url"
                            id="socialTiktok"
                            name="social_tiktok"
                            value="{{ old('social_tiktok', $form['social_tiktok']) }}"
                            class="form-control @error('social_tiktok') is-invalid @enderror"
                            placeholder="https://tiktok.com/@..."
                        >
                        @error('social_tiktok')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="socialYoutube" class="form-label small fw-semibold">YouTube URL</label>
                        <input
                            type="url"
                            id="socialYoutube"
                            name="social_youtube"
                            value="{{ old('social_youtube', $form['social_youtube']) }}"
                            class="form-control @error('social_youtube') is-invalid @enderror"
                            placeholder="https://youtube.com/@..."
                        >
                        @error('social_youtube')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="mlm-card p-3 p-md-4 mb-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Konten &amp; SEO Halaman Kontak</h2>

                <div class="mb-3">
                    <label for="contactTitle" class="form-label small fw-semibold">Judul Halaman <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        id="contactTitle"
                        name="title"
                        value="{{ old('title', $form['title']) }}"
                        class="form-control @error('title') is-invalid @enderror"
                        required
                    >
                    @error('title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    @include('admin.partials.cms-editor', [
                        'id' => 'contactContent',
                        'name' => 'content',
                        'label' => 'Teks Pengantar Halaman Kontak',
                        'value' => old('content', $form['content']),
                        'rows' => 7,
                        'required' => false,
                        'placeholder' => 'Tulis sapaan atau panduan menghubungi tim layanan pelanggan...',
                    ])
                </div>

                <div class="mb-3">
                    <label for="contactSeoTitle" class="form-label small fw-semibold">SEO Title</label>
                    <input
                        type="text"
                        id="contactSeoTitle"
                        name="seo_title"
                        value="{{ old('seo_title', $form['seo_title']) }}"
                        class="form-control @error('seo_title') is-invalid @enderror"
                        maxlength="160"
                    >
                    @error('seo_title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="contactSeoDescription" class="form-label small fw-semibold">Meta Description</label>
                    <textarea
                        id="contactSeoDescription"
                        name="seo_description"
                        rows="2"
                        class="form-control @error('seo_description') is-invalid @enderror"
                        maxlength="320"
                    >{{ old('seo_description', $form['seo_description']) }}</textarea>
                    @error('seo_description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-check form-switch mb-4">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="contactPublished"
                        name="is_published"
                        value="1"
                        @checked(old('is_published', $form['is_published']))
                    >
                    <label class="form-check-label fw-semibold" for="contactPublished">
                        Tampilkan halaman Kontak di storefront (/kontak)
                    </label>
                </div>

                @if($canManage)
                    <button type="submit" class="btn btn-primary fw-semibold w-100">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>Simpan Informasi Kontak</span>
                    </button>
                @else
                    <div class="alert alert-info small mb-0">Anda memiliki akses lihat saja untuk halaman ini.</div>
                @endif
            </div>
        </div>
    </div>
</form>
@endsection
