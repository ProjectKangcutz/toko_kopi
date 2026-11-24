@php
    $isEdit = isset($page) && $page->exists;
    $isBuiltIn = $isEdit && $page->isBuiltInPolicy();
    $titleValue = old('title', $page->title ?? '');
    $slugValue = old('slug', $page->slug ?? '');
    $bannerUrl = isset($page) ? \App\Services\Storefront\HomepageService::mediaUrl($page->banner_image_path) : null;
@endphp

<div
    class="row g-4"
    x-data="articleFormHelper({
        title: @js($titleValue),
        slug: @js($slugValue),
        slugEditedManually: @js($isEdit || trim((string) $slugValue) !== ''),
    })"
>
    @if($isEdit)
        <input type="hidden" name="page_id" value="{{ $page->id }}">
    @endif

    <div class="col-12 col-xl-8">
        <div class="mlm-card p-3 p-md-4 mb-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Konten Halaman</h2>

            <div class="mb-3">
                <label for="pageTitle" class="form-label small fw-semibold">Judul Halaman <span class="text-danger">*</span></label>
                <input
                    type="text"
                    id="pageTitle"
                    name="title"
                    @if($isBuiltIn)
                        value="{{ $titleValue }}"
                    @else
                        x-model="title"
                        @input="onTitleInput()"
                    @endif
                    class="form-control @error('title') is-invalid @enderror"
                    placeholder="Contoh: Program Reseller UMKM"
                    required
                >
                @error('title')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label for="pageSlug" class="form-label small fw-semibold mb-1">
                        Slug URL
                        @if($isBuiltIn)
                            <span class="badge bg-info-subtle text-info-emphasis ms-1">Slug Bawaan Terkunci</span>
                        @endif
                    </label>
                    @unless($isBuiltIn)
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="regenerateSlug()">
                            Buat ulang dari judul
                        </button>
                    @endunless
                </div>
                <div class="input-group">
                    <span class="input-group-text small text-mlm-muted">/</span>
                    <input
                        type="text"
                        id="pageSlug"
                        name="slug"
                        @if($isBuiltIn)
                            value="{{ $page->slug }}"
                            readonly
                        @else
                            x-model="slug"
                            @input="onSlugInput()"
                        @endif
                        class="form-control font-monospace @error('slug') is-invalid @enderror"
                        placeholder="otomatis-dari-judul"
                    >
                    @error('slug')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-text small">
                    @if($isBuiltIn)
                        Slug kebijakan bawaan tidak dapat diubah agar tautan toko tetap konsisten.
                    @else
                        Gunakan huruf kecil, angka, dan tanda hubung (-). Slug sistem (produk, kategori, artikel, faq, kontak, tentang-kami, kebijakan bawaan) dilindungi.
                    @endif
                </div>
            </div>

            @include('admin.partials.cms-editor', [
                'id' => 'pageContent',
                'name' => 'content',
                'label' => 'Isi Halaman',
                'value' => old('content', $page->content ?? ''),
                'rows' => 14,
                'required' => true,
                'placeholder' => 'Tulis isi halaman lengkap untuk pengunjung storefront...',
            ])
        </div>

        <div class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Pengaturan SEO</h2>

            <div class="mb-3">
                <label for="pageSeoTitle" class="form-label small fw-semibold">SEO Title</label>
                <input
                    type="text"
                    id="pageSeoTitle"
                    name="seo_title"
                    value="{{ old('seo_title', $page->seo_title ?? '') }}"
                    class="form-control @error('seo_title') is-invalid @enderror"
                    maxlength="160"
                    placeholder="Opsional — default menggunakan judul halaman"
                >
                @error('seo_title')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="pageSeoDescription" class="form-label small fw-semibold">Meta Description</label>
                <textarea
                    id="pageSeoDescription"
                    name="seo_description"
                    rows="2"
                    class="form-control @error('seo_description') is-invalid @enderror"
                    maxlength="320"
                    placeholder="Opsional — deskripsi singkat untuk hasil mesin pencari..."
                >{{ old('seo_description', $page->seo_description ?? '') }}</textarea>
                @error('seo_description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="mlm-card p-3 p-md-4 mb-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Status &amp; Navigasi Footer</h2>

            <div class="form-check form-switch mb-3">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="pageIsPublished"
                    name="is_published"
                    value="1"
                    @checked(old('is_published', $page->is_published ?? true))
                >
                <label class="form-check-label fw-semibold" for="pageIsPublished">
                    Enabled (Halaman dapat diakses publik)
                </label>
                <div class="form-text small">Jika dinonaktifkan (Disabled), URL halaman mengembalikan 404 dan disembunyikan dari footer.</div>
            </div>

            @if($isBuiltIn)
                <input type="hidden" name="show_in_footer" value="1">
                <div class="p-3 rounded-3 bg-mlm-secondary small text-mlm-primary mb-3">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Halaman kebijakan bawaan otomatis tampil di bagian <strong>Kebijakan Toko</strong> pada footer selama berstatus <strong>Enabled</strong>.
                </div>
            @else
                <div class="form-check form-switch mb-3">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="pageShowInFooter"
                        name="show_in_footer"
                        value="1"
                        @checked(old('show_in_footer', $page->show_in_footer ?? false))
                    >
                    <label class="form-check-label fw-semibold" for="pageShowInFooter">
                        Tampilkan tautan di footer storefront
                    </label>
                    <div class="form-text small">Jika tidak dicentang, halaman tetap bisa diakses langsung lewat URL saat Enabled.</div>
                </div>
            @endif

            <div class="mb-4">
                <label for="pageSortOrder" class="form-label small fw-semibold">Urutan Tampil</label>
                <input
                    type="number"
                    id="pageSortOrder"
                    name="sort_order"
                    min="0"
                    max="10000"
                    value="{{ old('sort_order', $page->sort_order ?? ($nextOrder ?? 10)) }}"
                    class="form-control @error('sort_order') is-invalid @enderror"
                    required
                >
                @error('sort_order')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">Mengatur urutan tampilan di daftar admin dan footer storefront.</div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <span>{{ $isEdit ? 'Simpan Perubahan' : 'Simpan Halaman' }}</span>
                </button>
                <button
                    type="submit"
                    formaction="{{ route('admin.pages.preview.draft') }}"
                    formtarget="_blank"
                    formnovalidate
                    class="btn btn-outline-secondary fw-semibold"
                >
                    <i class="bi bi-eye" aria-hidden="true"></i>
                    <span>Preview Sebelum Publish</span>
                </button>
            </div>
        </div>

        <div class="mlm-card p-3 p-md-4 mb-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Banner Halaman (Opsional)</h2>

            @if($bannerUrl)
                <div class="mb-3">
                    <img
                        src="{{ $bannerUrl }}"
                        alt="{{ $page->title }}"
                        class="w-100 rounded-3 border object-fit-cover mb-2"
                        style="max-height: 180px;"
                    >
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="removePageBanner"
                            name="remove_banner_image"
                            value="1"
                            @checked(old('remove_banner_image'))
                        >
                        <label class="form-check-label small text-danger" for="removePageBanner">
                            Hapus banner saat ini
                        </label>
                    </div>
                </div>
            @endif

            <div>
                <label for="pageBannerImage" class="form-label small fw-semibold">Unggah Banner Baru</label>
                <input
                    type="file"
                    id="pageBannerImage"
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

        @if($isEdit)
            <div class="mlm-card p-3 p-md-4 small text-mlm-muted">
                <div class="fw-semibold text-mlm-primary mb-2">Informasi Halaman</div>
                <div class="mb-1">Dibuat: {{ $page->created_at?->translatedFormat('d M Y H:i') }}</div>
                <div class="mb-1">Diperbarui: {{ $page->updated_at?->translatedFormat('d M Y H:i') }}</div>
                @if($page->updater)
                    <div>Oleh: {{ $page->updater->name }}</div>
                @endif
            </div>
        @endif
    </div>
</div>
