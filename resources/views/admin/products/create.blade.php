@extends('layouts.admin')

@section('title', 'Tambah Produk Baru')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Produk', 'url' => route('admin.products.index')],
    ['label' => 'Tambah Produk'],
]" />

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold text-mlm-primary mb-0">Tambah Produk Baru</h1>
</div>

<form
    method="POST"
    action="{{ route('admin.products.store') }}"
    enctype="multipart/form-data"
    x-data="{
        hasVariants: {{ old('has_variants') ? 'true' : 'false' }},
        variants: @js(old('variants', []))
    }"
>
    @csrf

    <div class="row g-4 align-items-start">
        <div class="col-12 col-lg-8 d-flex flex-column gap-4">
            <section class="mlm-card p-3 p-md-4">
                <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Informasi Utama Produk</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <x-form.input name="sku" label="SKU Produk" required placeholder="KOP-001" />
                    </div>
                    <div class="col-12 col-md-8">
                        <x-form.input name="name" label="Nama Produk" required placeholder="Contoh: Kopi Arabika Gayo 250g" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="slug" label="Slug URL (Opsional)" placeholder="Otomatis dari nama produk" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.select name="primary_category_id" label="Kategori Utama" :options="$categories" required />
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-medium">Kategori Tambahan (Opsional)</label>
                        <div class="row g-2">
                            @foreach($categories as $catId => $catName)
                                <div class="col-6 col-md-4">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="extraCat{{ $catId }}"
                                            name="category_ids[]"
                                            value="{{ $catId }}"
                                            @checked(in_array((int) $catId, array_map('intval', (array) old('category_ids', [])), true))
                                        >
                                        <label class="form-check-label small" for="extraCat{{ $catId }}">{{ $catName }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-12">
                        <x-form.input name="short_description" label="Ringkasan Singkat" placeholder="Kalimat singkat keunggulan produk..." />
                    </div>
                    <div class="col-12">
                        <x-form.textarea name="description" label="Deskripsi Lengkap Produk" rows="5" required placeholder="Jelaskan detail produk, bahan, cara penyimpanan, dll." />
                    </div>
                    <div class="col-12">
                        <x-form.textarea name="specifications" label="Spesifikasi Tambahan (Opsional)" rows="3" placeholder="Kemasan, izin edar P-IRT/Halal, asal daerah..." />
                    </div>
                </div>
            </section>

            <section class="mlm-card p-3 p-md-4">
                <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Harga, Berat &amp; Stok Awal</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <x-form.input name="cost_price" label="Harga Modal (Rp)" type="number" step="1" min="0" value="0" />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="price" label="Harga Normal (Rp)" type="number" step="1" min="1" required />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="promo_price" label="Harga Promo (Opsional)" type="number" step="1" min="1" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="promo_start_at" label="Mulai Promo (Opsional)" type="datetime-local" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="promo_end_at" label="Akhir Promo (Opsional)" type="datetime-local" />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="weight_grams" label="Berat (gram)" type="number" min="1" value="250" required />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="min_stock" label="Minimum Stok Peringatan" type="number" min="0" value="5" required />
                    </div>
                    <div class="col-12 col-md-4" x-show="!hasVariants">
                        <x-form.input name="initial_stock" label="Stok Awal Produk" type="number" min="0" value="0" />
                    </div>
                </div>

                <div class="form-check form-switch mt-2">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="hasVariantsSwitch"
                        name="has_variants"
                        value="1"
                        x-model="hasVariants"
                    >
                    <label class="form-check-label fw-medium" for="hasVariantsSwitch">Produk ini memiliki beberapa varian (ukuran / rasa / kemasan)</label>
                </div>

                <div x-show="hasVariants" x-cloak class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h3 class="h6 fw-bold mb-0">Varian Awal Produk</h3>
                            <div class="small text-mlm-muted">Tambahkan satu atau beberapa varian produk beserta stok awalnya.</div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm"
                            style="min-height: 44px;"
                            @click="variants.push({ sku: '', name: '', price: '', promo_price: '', weight_grams: 250, initial_stock: 0, min_stock: 5, is_active: true })"
                        >
                            <i class="bi bi-plus-lg me-1"></i> Tambah Baris Varian
                        </button>
                    </div>

                    <template x-for="(variant, index) in variants" :key="index">
                        <div class="border rounded-3 p-3 mb-3 bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-bold text-mlm-primary" x-text="'Varian #' + (index + 1)"></span>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="variants.splice(index, 1)">Hapus Baris</button>
                            </div>
                            <div class="row g-2">
                                <div class="col-6 col-md-4">
                                    <label class="form-label small fw-semibold">SKU Varian *</label>
                                    <input type="text" class="form-control" :name="'variants[' + index + '][sku]'" x-model="variant.sku" :required="hasVariants">
                                </div>
                                <div class="col-6 col-md-8">
                                    <label class="form-label small fw-semibold">Nama Varian *</label>
                                    <input type="text" class="form-control" :name="'variants[' + index + '][name]'" x-model="variant.name" placeholder="Misal: 500 gram / Pedas" :required="hasVariants">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold">Harga *</label>
                                    <input type="number" min="1" class="form-control" :name="'variants[' + index + '][price]'" x-model="variant.price" :required="hasVariants">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-semibold">Harga Promo</label>
                                    <input type="number" min="1" class="form-control" :name="'variants[' + index + '][promo_price]'" x-model="variant.promo_price">
                                </div>
                                <div class="col-4 col-md-2">
                                    <label class="form-label small fw-semibold">Berat (g) *</label>
                                    <input type="number" min="1" class="form-control" :name="'variants[' + index + '][weight_grams]'" x-model="variant.weight_grams" :required="hasVariants">
                                </div>
                                <div class="col-4 col-md-2">
                                    <label class="form-label small fw-semibold">Stok Awal *</label>
                                    <input type="number" min="0" class="form-control" :name="'variants[' + index + '][initial_stock]'" x-model="variant.initial_stock" :required="hasVariants">
                                </div>
                                <div class="col-4 col-md-2">
                                    <label class="form-label small fw-semibold">Min. Stok *</label>
                                    <input type="number" min="0" class="form-control" :name="'variants[' + index + '][min_stock]'" x-model="variant.min_stock" :required="hasVariants">
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>

        <div class="col-12 col-lg-4 d-flex flex-column gap-4">
            <section class="mlm-card p-3 p-md-4">
                <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Gambar Produk</h2>
                <label for="productImages" class="form-label small fw-medium">Unggah Gambar (maks 10 file, masing-masing maks 2 MB)</label>
                <input
                    type="file"
                    id="productImages"
                    name="images[]"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    class="form-control @error('images.*') is-invalid @enderror"
                >
                <div class="form-text small">Gambar pertama otomatis menjadi gambar utama.</div>
                @error('images.*')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </section>

            <section class="mlm-card p-3 p-md-4">
                <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Status Publikasi</h2>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="isActive" name="is_active" value="1" @checked(old('is_active', true))>
                    <label class="form-check-label fw-medium" for="isActive">Aktif di katalog toko</label>
                </div>
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="isFeatured" name="is_featured" value="1" @checked(old('is_featured'))>
                    <label class="form-check-label fw-medium" for="isFeatured">Tandai sebagai Produk Unggulan</label>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-save me-1" aria-hidden="true"></i> Simpan Produk
                    </button>
                    <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-height: 44px;">
                        Batal
                    </a>
                </div>
            </section>
        </div>
    </div>
</form>
@endsection
