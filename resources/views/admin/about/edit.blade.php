@extends('layouts.admin')

@section('title', 'Kelola Tentang Kami')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Tentang Kami'],
]" />

@php
    $bannerUrl = \App\Services\Storefront\HomepageService::mediaUrl($form['banner_path'] ?? null);
    $canManage = $admin->hasPermission('content.manage');
@endphp

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Halaman Tentang Kami</h1>
        <p class="small text-mlm-muted mb-0">Kelola profil usaha, cerita UMKM, visi, misi, banner halaman, dan metadata SEO.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.about.preview') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">
            <i class="bi bi-eye" aria-hidden="true"></i> Preview Tersimpan
        </a>
        @if(($form['is_published'] ?? true))
            <a href="{{ route('storefront.about') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Lihat Storefront
            </a>
        @endif
    </div>
</div>

<form method="POST" action="{{ route('admin.about.update') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="mlm-card p-3 p-md-4 mb-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Konten Utama Halaman</h2>

                <div class="mb-3">
                    <label for="aboutTitle" class="form-label small fw-semibold">Judul Halaman <span class="text-danger">*</span></label>
                    <input
                        type="text"
                        id="aboutTitle"
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
                    <label for="aboutSubtitle" class="form-label small fw-semibold">Subjudul</label>
                    <input
                        type="text"
                        id="aboutSubtitle"
                        name="subtitle"
                        value="{{ old('subtitle', $form['subtitle']) }}"
                        class="form-control @error('subtitle') is-invalid @enderror"
                        placeholder="Contoh: Menghubungkan pengrajin dan produsen lokal terbaik ke rumah Anda"
                    >
                    @error('subtitle')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @include('admin.partials.cms-editor', [
                    'id' => 'aboutContent',
                    'name' => 'content',
                    'label' => 'Konten Utama',
                    'value' => old('content', $form['content']),
                    'rows' => 12,
                    'required' => true,
                    'placeholder' => 'Ceritakan latar belakang toko, nilai lokal, dan komitmen kualitas UMKM...',
                ])
            </div>

            <div class="mlm-card p-3 p-md-4 mb-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Visi, Misi &amp; Profil Usaha</h2>

                <div class="mb-3">
                    <label for="aboutVision" class="form-label small fw-semibold">Visi</label>
                    <textarea
                        id="aboutVision"
                        name="vision"
                        rows="3"
                        class="form-control @error('vision') is-invalid @enderror"
                        placeholder="Tuliskan visi usaha atau toko..."
                    >{{ old('vision', $form['vision']) }}</textarea>
                    @error('vision')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="aboutMission" class="form-label small fw-semibold">Misi</label>
                    <textarea
                        id="aboutMission"
                        name="mission"
                        rows="4"
                        class="form-control @error('mission') is-invalid @enderror"
                        placeholder="Tuliskan poin-poin misi usaha..."
                    >{{ old('mission', $form['mission']) }}</textarea>
                    @error('mission')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="aboutBusinessProfile" class="form-label small fw-semibold">Informasi Profil Usaha</label>
                    <textarea
                        id="aboutBusinessProfile"
                        name="business_profile"
                        rows="4"
                        class="form-control @error('business_profile') is-invalid @enderror"
                        placeholder="Informasi singkat legalitas, tahun berdiri, mitra UMKM, atau standar kurasi produk..."
                    >{{ old('business_profile', $form['business_profile']) }}</textarea>
                    @error('business_profile')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mlm-card p-3 p-md-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Pengaturan SEO</h2>

                <div class="mb-3">
                    <label for="aboutSeoTitle" class="form-label small fw-semibold">SEO Title</label>
                    <input
                        type="text"
                        id="aboutSeoTitle"
                        name="seo_title"
                        value="{{ old('seo_title', $form['seo_title']) }}"
                        class="form-control @error('seo_title') is-invalid @enderror"
                        maxlength="160"
                        placeholder="Opsional — default menggunakan judul halaman"
                    >
                    @error('seo_title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label for="aboutSeoDescription" class="form-label small fw-semibold">Meta Description</label>
                    <textarea
                        id="aboutSeoDescription"
                        name="seo_description"
                        rows="2"
                        class="form-control @error('seo_description') is-invalid @enderror"
                        maxlength="320"
                        placeholder="Ringkasan halaman Tentang Kami untuk mesin pencari..."
                    >{{ old('seo_description', $form['seo_description']) }}</textarea>
                    @error('seo_description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="mlm-card p-3 p-md-4 mb-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Publikasi &amp; Pratinjau</h2>

                <div class="form-check form-switch mb-3">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="aboutPublished"
                        name="is_published"
                        value="1"
                        @checked(old('is_published', $form['is_published']))
                    >
                    <label class="form-check-label fw-semibold" for="aboutPublished">
                        Tampilkan halaman di storefront (/tentang-kami)
                    </label>
                </div>

                <div class="d-grid gap-2">
                    @if($canManage)
                        <button type="submit" class="btn btn-primary fw-semibold">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                            <span>Simpan Perubahan</span>
                        </button>
                        <button
                            type="submit"
                            formaction="{{ route('admin.about.preview.draft') }}"
                            formtarget="_blank"
                            formnovalidate
                            class="btn btn-outline-secondary fw-semibold"
                        >
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>Preview Sebelum Publish</span>
                        </button>
                    @else
                        <div class="alert alert-info small mb-0">Anda memiliki akses lihat saja untuk halaman ini.</div>
                    @endif
                </div>
            </div>

            <div class="mlm-card p-3 p-md-4">
                <h2 class="h5 fw-bold text-mlm-primary mb-3">Gambar Banner Halaman</h2>

                @if($bannerUrl)
                    <div class="mb-3">
                        <img
                            src="{{ $bannerUrl }}"
                            alt="Banner Tentang Kami"
                            class="w-100 rounded-3 border object-fit-cover mb-2"
                            style="max-height: 180px;"
                        >
                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="removeAboutBanner"
                                name="remove_banner_image"
                                value="1"
                                @checked(old('remove_banner_image'))
                            >
                            <label class="form-check-label small text-danger" for="removeAboutBanner">
                                Hapus banner saat ini
                            </label>
                        </div>
                    </div>
                @endif

                <div>
                    <label for="aboutBannerImage" class="form-label small fw-semibold">Unggah Banner Baru</label>
                    <input
                        type="file"
                        id="aboutBannerImage"
                        name="banner_image"
                        accept="image/jpeg,image/png,image/webp"
                        class="form-control @error('banner_image') is-invalid @enderror"
                    >
                    @error('banner_image')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text small">Format JPG, PNG, atau WEBP. Maksimal 2 MB.</div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
