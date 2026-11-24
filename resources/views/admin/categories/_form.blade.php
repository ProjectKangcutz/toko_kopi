@php
    $isEdit = isset($category) && $category->exists;
    $existingImageUrl = $isEdit ? \App\Services\Storefront\HomepageService::mediaUrl($category->image_path) : null;
@endphp

<div class="row g-3">
    <div class="col-12 col-md-6">
        <x-form.input
            name="name"
            label="Nama Kategori"
            :value="$isEdit ? $category->name : null"
            required
            placeholder="Contoh: Kopi & Minuman Nusantara"
        />
    </div>

    <div class="col-12 col-md-6">
        <x-form.input
            name="slug"
            label="Slug URL (Opsional)"
            :value="$isEdit ? $category->slug : null"
            placeholder="Otomatis dari nama kategori jika dikosongkan"
        />
    </div>

    <div class="col-12 col-md-6">
        <x-form.select
            name="parent_id"
            label="Kategori Induk (Opsional)"
            :options="$parentCategories"
            :selected="$isEdit ? $category->parent_id : null"
            placeholder="-- Kategori Utama (Tanpa Induk) --"
        />
    </div>

    <div class="col-12 col-md-6">
        <x-form.input
            name="sort_order"
            label="Urutan Tampilan"
            type="number"
            min="0"
            :value="$isEdit ? $category->sort_order : 0"
            required
        />
    </div>

    <div class="col-12">
        <x-form.textarea
            name="description"
            label="Deskripsi Kategori"
            :value="$isEdit ? $category->description : null"
            rows="3"
            placeholder="Deskripsi singkat kategori untuk pengunjung toko..."
        />
    </div>

    <div class="col-12 col-md-6">
        <label for="categoryImage" class="form-label fw-medium">Gambar Kategori (JPG/PNG/WEBP, maks 2 MB)</label>
        <input
            type="file"
            id="categoryImage"
            name="image"
            accept="image/jpeg,image/png,image/webp"
            class="form-control @error('image') is-invalid @enderror"
            style="min-height: 44px;"
        >
        @error('image')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror

        @if($existingImageUrl)
            <div class="mt-2 d-flex align-items-center gap-3">
                <img src="{{ $existingImageUrl }}" alt="{{ $category->name }}" class="rounded-3 border object-fit-cover" style="width: 72px; height: 72px;">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="removeImage" name="remove_image" value="1" @checked(old('remove_image'))>
                    <label class="form-check-label small text-danger" for="removeImage">Hapus gambar saat ini</label>
                </div>
            </div>
        @endif
    </div>

    <div class="col-12 col-md-6 d-flex align-items-center">
        <div class="form-check form-switch mt-md-3">
            <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                id="categoryIsActive"
                name="is_active"
                value="1"
                @checked(old('is_active', $isEdit ? $category->is_active : true))
            >
            <label class="form-check-label fw-medium" for="categoryIsActive">Aktif &amp; tampilkan di katalog storefront</label>
        </div>
    </div>
</div>
