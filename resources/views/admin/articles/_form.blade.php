@php
    $isEdit = isset($article) && $article->exists;
    $titleValue = old('title', $article->title ?? '');
    $slugValue = old('slug', $article->slug ?? '');
    $publishedAtValue = old(
        'published_at',
        isset($article) && $article->published_at ? $article->published_at->format('Y-m-d\TH:i') : ''
    );
    $featuredImageUrl = isset($article) ? \App\Services\Storefront\HomepageService::mediaUrl($article->featured_image_path) : null;
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
        <input type="hidden" name="article_id" value="{{ $article->id }}">
    @endif

    <div class="col-12 col-xl-8">
        <div class="mlm-card p-3 p-md-4 mb-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Konten Artikel</h2>

            <div class="mb-3">
                <label for="articleTitle" class="form-label small fw-semibold">Judul Artikel <span class="text-danger">*</span></label>
                <input
                    type="text"
                    id="articleTitle"
                    name="title"
                    x-model="title"
                    @input="onTitleInput()"
                    class="form-control @error('title') is-invalid @enderror"
                    placeholder="Contoh: Mengenal Kopi Arabika Gayo Pilihan Petani Lokal"
                    required
                >
                @error('title')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label for="articleSlug" class="form-label small fw-semibold mb-1">Slug URL</label>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="regenerateSlug()">
                        Buat ulang dari judul
                    </button>
                </div>
                <div class="input-group">
                    <span class="input-group-text small text-mlm-muted">/artikel/</span>
                    <input
                        type="text"
                        id="articleSlug"
                        name="slug"
                        x-model="slug"
                        @input="onSlugInput()"
                        class="form-control font-monospace @error('slug') is-invalid @enderror"
                        placeholder="otomatis-dari-judul"
                    >
                    @error('slug')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="form-text small">Gunakan huruf kecil, angka, dan tanda hubung (-). Kosongkan untuk membuat slug unik otomatis.</div>
            </div>

            <div class="mb-3">
                <label for="articleExcerpt" class="form-label small fw-semibold">Ringkasan (Excerpt)</label>
                <textarea
                    id="articleExcerpt"
                    name="excerpt"
                    rows="3"
                    class="form-control @error('excerpt') is-invalid @enderror"
                    placeholder="Ringkasan singkat yang tampil di kartu artikel dan daftar artikel..."
                >{{ old('excerpt', $article->excerpt ?? '') }}</textarea>
                @error('excerpt')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div>
                @include('admin.partials.cms-editor', [
                    'id' => 'articleContent',
                    'name' => 'content',
                    'label' => 'Isi Artikel',
                    'value' => old('content', $article->content ?? ''),
                    'rows' => 14,
                    'required' => true,
                    'placeholder' => 'Tulis isi artikel lengkap untuk pembaca storefront...',
                ])
            </div>
        </div>

        <div class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Pengaturan SEO</h2>

            <div class="mb-3">
                <label for="articleSeoTitle" class="form-label small fw-semibold">SEO Title</label>
                <input
                    type="text"
                    id="articleSeoTitle"
                    name="seo_title"
                    value="{{ old('seo_title', $article->seo_title ?? '') }}"
                    class="form-control @error('seo_title') is-invalid @enderror"
                    maxlength="160"
                    placeholder="Opsional — default menggunakan judul artikel"
                >
                @error('seo_title')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="articleSeoDescription" class="form-label small fw-semibold">Meta Description</label>
                <textarea
                    id="articleSeoDescription"
                    name="seo_description"
                    rows="2"
                    class="form-control @error('seo_description') is-invalid @enderror"
                    maxlength="320"
                    placeholder="Opsional — deskripsi singkat untuk hasil mesin pencari..."
                >{{ old('seo_description', $article->seo_description ?? '') }}</textarea>
                @error('seo_description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="articleCanonicalUrl" class="form-label small fw-semibold">Canonical URL</label>
                <input
                    type="url"
                    id="articleCanonicalUrl"
                    name="canonical_url"
                    value="{{ old('canonical_url', $article->canonical_url ?? '') }}"
                    class="form-control @error('canonical_url') is-invalid @enderror"
                    placeholder="https://... (opsional)"
                >
                @error('canonical_url')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="mlm-card p-3 p-md-4 mb-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Publikasi &amp; Penulis</h2>

            <div class="mb-3">
                <label for="articleStatus" class="form-label small fw-semibold">Status <span class="text-danger">*</span></label>
                <select id="articleStatus" name="status" class="form-select @error('status') is-invalid @enderror" required>
                    <option value="draft" @selected(old('status', $article->status ?? 'draft') === 'draft')>Draft (Disembunyikan)</option>
                    <option value="published" @selected(old('status', $article->status ?? 'draft') === 'published')>Published (Tayang di Storefront)</option>
                </select>
                @error('status')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="articlePublishedAt" class="form-label small fw-semibold">Tanggal Publikasi</label>
                <input
                    type="datetime-local"
                    id="articlePublishedAt"
                    name="published_at"
                    value="{{ $publishedAtValue }}"
                    class="form-control @error('published_at') is-invalid @enderror"
                >
                @error('published_at')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">Jika tanggal di masa depan, artikel belum tampil di storefront hingga waktu tersebut tiba.</div>
            </div>

            <div class="mb-4">
                <label for="articleAuthor" class="form-label small fw-semibold">Penulis</label>
                <select id="articleAuthor" name="author_id" class="form-select @error('author_id') is-invalid @enderror">
                    @foreach($authors as $author)
                        <option value="{{ $author->id }}" @selected((int) old('author_id', $article->author_id ?? $admin->id) === (int) $author->id)>
                            {{ $author->name }}
                        </option>
                    @endforeach
                </select>
                @error('author_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <span>{{ $isEdit ? 'Simpan Perubahan' : 'Simpan Artikel' }}</span>
                </button>
                <button
                    type="submit"
                    formaction="{{ route('admin.articles.preview.draft') }}"
                    formtarget="_blank"
                    formnovalidate
                    class="btn btn-outline-secondary fw-semibold"
                >
                    <i class="bi bi-eye" aria-hidden="true"></i>
                    <span>Preview Sebelum Publish</span>
                </button>
            </div>
        </div>

        <div class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Gambar Utama</h2>

            @if($featuredImageUrl)
                <div class="mb-3">
                    <img
                        src="{{ $featuredImageUrl }}"
                        alt="{{ $article->featured_image_alt ?: $article->title }}"
                        class="w-100 rounded-3 border object-fit-cover mb-2"
                        style="max-height: 200px;"
                    >
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="removeFeaturedImage"
                            name="remove_featured_image"
                            value="1"
                            @checked(old('remove_featured_image'))
                        >
                        <label class="form-check-label small text-danger" for="removeFeaturedImage">
                            Hapus gambar utama saat ini
                        </label>
                    </div>
                </div>
            @endif

            <div class="mb-3">
                <label for="articleFeaturedImage" class="form-label small fw-semibold">Unggah Gambar Baru</label>
                <input
                    type="file"
                    id="articleFeaturedImage"
                    name="featured_image"
                    accept="image/jpeg,image/png,image/webp"
                    class="form-control @error('featured_image') is-invalid @enderror"
                >
                @error('featured_image')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">Format JPG, PNG, atau WEBP. Maksimal 2 MB.</div>
            </div>

            <div>
                <label for="articleFeaturedImageAlt" class="form-label small fw-semibold">Teks Alt Gambar</label>
                <input
                    type="text"
                    id="articleFeaturedImageAlt"
                    name="featured_image_alt"
                    value="{{ old('featured_image_alt', $article->featured_image_alt ?? '') }}"
                    class="form-control @error('featured_image_alt') is-invalid @enderror"
                    placeholder="Deskripsi singkat gambar untuk SEO dan aksesibilitas"
                >
                @error('featured_image_alt')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </div>
</div>
