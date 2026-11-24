@extends('layouts.admin')

@section('title', 'Edit Produk ' . $product->name)

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Produk', 'url' => route('admin.products.index')],
    ['label' => $product->name],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="small text-mlm-muted">Edit Produk Katalog</div>
        <h1 class="h3 fw-bold text-mlm-primary mb-0">{{ $product->name }}</h1>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('storefront.product', $product->slug) }}" target="_blank" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Lihat di Toko
        </a>
        <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali
        </a>
    </div>
</div>

<div class="row g-4 align-items-start">
    <div class="col-12 col-lg-8 d-flex flex-column gap-4">
        {{-- Form Informasi Produk --}}
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Informasi &amp; Harga Produk</h2>
            <form method="POST" action="{{ route('admin.products.update', $product) }}">
                @csrf
                @method('PUT')
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <x-form.input name="sku" label="SKU Produk" :value="$product->sku" required />
                    </div>
                    <div class="col-12 col-md-8">
                        <x-form.input name="name" label="Nama Produk" :value="$product->name" required />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="slug" label="Slug URL" :value="$product->slug" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.select name="primary_category_id" label="Kategori Utama" :options="$categories" :selected="$primaryCategoryId" required />
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-medium">Kategori Tambahan</label>
                        <div class="row g-2">
                            @foreach($categories as $catId => $catName)
                                <div class="col-6 col-md-4">
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="editCat{{ $catId }}"
                                            name="category_ids[]"
                                            value="{{ $catId }}"
                                            @checked(in_array((int) $catId, array_map('intval', (array) old('category_ids', $selectedCategoryIds)), true))
                                        >
                                        <label class="form-check-label small" for="editCat{{ $catId }}">{{ $catName }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-12">
                        <x-form.input name="short_description" label="Ringkasan Singkat" :value="$product->short_description" />
                    </div>
                    <div class="col-12">
                        <x-form.textarea name="description" label="Deskripsi Lengkap Produk" :value="$product->description" rows="5" required />
                    </div>
                    <div class="col-12">
                        <x-form.textarea name="specifications" label="Spesifikasi Tambahan" :value="$product->specifications" rows="3" />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="cost_price" label="Harga Modal (Rp)" type="number" step="1" min="0" :value="(int) $product->cost_price" />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="price" label="Harga Normal (Rp)" type="number" step="1" min="1" :value="(int) $product->price" required />
                    </div>
                    <div class="col-12 col-md-4">
                        <x-form.input name="promo_price" label="Harga Promo (Opsional)" type="number" step="1" min="1" :value="$product->promo_price !== null ? (int) $product->promo_price : null" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="promo_start_at" label="Mulai Promo" type="datetime-local" :value="$product->promo_start_at?->format('Y-m-d\TH:i')" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="promo_end_at" label="Akhir Promo" type="datetime-local" :value="$product->promo_end_at?->format('Y-m-d\TH:i')" />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="weight_grams" label="Berat (gram)" type="number" min="1" :value="$product->weight_grams" required />
                    </div>
                    <div class="col-12 col-md-6">
                        <x-form.input name="min_stock" label="Minimum Stok Peringatan" type="number" min="0" :value="$product->min_stock" required />
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-wrap gap-4 pt-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="editHasVariants" name="has_variants" value="1" @checked(old('has_variants', $product->has_variants))>
                                <label class="form-check-label fw-medium" for="editHasVariants">Gunakan Varian Produk</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="editIsActive" name="is_active" value="1" @checked(old('is_active', $product->is_active))>
                                <label class="form-check-label fw-medium" for="editIsActive">Aktif di Katalog</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="editIsFeatured" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured))>
                                <label class="form-check-label fw-medium" for="editIsFeatured">Produk Unggulan</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-3 mt-3 border-top">
                    <button type="submit" class="btn btn-primary fw-semibold px-4" style="min-height: 44px;">
                        <i class="bi bi-save me-1" aria-hidden="true"></i> Simpan Perubahan Produk
                    </button>
                </div>
            </form>
        </section>

        {{-- Manajemen Varian --}}
        <section class="mlm-card p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                <h2 class="h6 fw-bold text-mlm-primary mb-0">Varian Produk</h2>
                <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">{{ $product->variants->count() }} varian</span>
            </div>

            @if($product->variants->isNotEmpty())
                <div class="d-flex flex-column gap-3 mb-4">
                    @foreach($product->variants as $variant)
                        <div class="border rounded-3 p-3">
                            <form method="POST" action="{{ route('admin.products.variants.update', [$product, $variant]) }}">
                                @csrf
                                @method('PUT')
                                <div class="row g-2 align-items-end">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-semibold">SKU</label>
                                        <input type="text" name="sku" value="{{ $variant->sku }}" required class="form-control form-control-sm" style="min-height: 40px;">
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label small fw-semibold">Nama Varian</label>
                                        <input type="text" name="name" value="{{ $variant->name }}" required class="form-control form-control-sm" style="min-height: 40px;">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label small fw-semibold">Harga (Rp)</label>
                                        <input type="number" min="1" name="price" value="{{ (int) $variant->price }}" required class="form-control form-control-sm" style="min-height: 40px;">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small fw-semibold">Promo</label>
                                        <input type="number" min="1" name="promo_price" value="{{ $variant->promo_price !== null ? (int) $variant->promo_price : '' }}" class="form-control form-control-sm" style="min-height: 40px;">
                                    </div>
                                    <div class="col-4 col-md-2">
                                        <label class="form-label small fw-semibold">Berat (g)</label>
                                        <input type="number" min="1" name="weight_grams" value="{{ $variant->weight_grams }}" required class="form-control form-control-sm" style="min-height: 40px;">
                                    </div>
                                    <div class="col-4 col-md-2">
                                        <label class="form-label small fw-semibold">Min. Stok</label>
                                        <input type="number" min="0" name="min_stock" value="{{ $variant->min_stock }}" required class="form-control form-control-sm" style="min-height: 40px;">
                                    </div>
                                    <div class="col-4 col-md-3 small">
                                        <div class="text-mlm-muted">Stok Fisik / Res</div>
                                        <div class="fw-bold">{{ $variant->stock }} / {{ $variant->reserved_stock }}</div>
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="varActive{{ $variant->id }}" name="is_active" value="1" @checked($variant->is_active)>
                                            <label class="form-check-label small" for="varActive{{ $variant->id }}">Aktif</label>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 d-flex justify-content-end gap-1">
                                        <button type="submit" class="btn btn-outline-primary btn-sm" style="min-height: 40px;">Simpan</button>
                                    </div>
                                </div>
                            </form>
                            <div class="mt-2 pt-2 border-top d-flex justify-content-end">
                                @php
                                    $deleteVariantPayload = [
                                        'title' => 'Konfirmasi Hapus Varian',
                                        'message' => 'Apakah Anda yakin ingin menghapus varian "'.$variant->name.'"?',
                                        'confirmLabel' => 'Ya, Hapus Varian',
                                        'confirmClass' => 'btn-danger',
                                        'formSelector' => '#delete-variant-'.$variant->id,
                                    ];
                                @endphp
                                <button
                                    type="button"
                                    x-data
                                    @click="$store.confirmModal?.ask(@js($deleteVariantPayload))"
                                    class="btn btn-outline-danger btn-sm"
                                >
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i> Hapus Varian
                                </button>
                                <form id="delete-variant-{{ $variant->id }}" method="POST" action="{{ route('admin.products.variants.destroy', [$product, $variant]) }}" class="d-none">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.products.variants.store', $product) }}" class="border rounded-3 p-3 bg-light-subtle">
                @csrf
                <div class="small fw-bold text-mlm-primary mb-2">Tambah Varian Baru</div>
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">SKU Varian *</label>
                        <input type="text" name="sku" required class="form-control form-control-sm" style="min-height: 40px;">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">Nama Varian *</label>
                        <input type="text" name="name" required class="form-control form-control-sm" style="min-height: 40px;" placeholder="Misal: Kemasan 500g">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Harga Normal *</label>
                        <input type="number" min="1" name="price" required class="form-control form-control-sm" style="min-height: 40px;">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold">Promo</label>
                        <input type="number" min="1" name="promo_price" class="form-control form-control-sm" style="min-height: 40px;">
                    </div>
                    <div class="col-4 col-md-2">
                        <label class="form-label small fw-semibold">Berat (g) *</label>
                        <input type="number" min="1" name="weight_grams" value="{{ $product->weight_grams }}" required class="form-control form-control-sm" style="min-height: 40px;">
                    </div>
                    <div class="col-4 col-md-2">
                        <label class="form-label small fw-semibold">Stok Awal *</label>
                        <input type="number" min="0" name="initial_stock" value="0" required class="form-control form-control-sm" style="min-height: 40px;">
                    </div>
                    <div class="col-4 col-md-2">
                        <label class="form-label small fw-semibold">Min. Stok *</label>
                        <input type="number" min="0" name="min_stock" value="{{ $product->min_stock }}" required class="form-control form-control-sm" style="min-height: 40px;">
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="newVarActive" name="is_active" value="1" checked>
                            <label class="form-check-label small" for="newVarActive">Aktif</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 d-grid">
                        <button type="submit" class="btn btn-primary btn-sm fw-semibold" style="min-height: 40px;">
                            <i class="bi bi-plus-lg me-1"></i> Tambah Varian
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-lg-4 d-flex flex-column gap-4">
        {{-- Ringkasan Stok Read-Only --}}
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Ringkasan Stok (Audit Trail)</h2>
            @if(! $product->has_variants)
                <dl class="small mb-3">
                    <div class="d-flex justify-content-between py-1">
                        <dt class="fw-normal text-mlm-muted">Stok Fisik</dt>
                        <dd class="mb-0 fw-bold">{{ $product->stock }}</dd>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <dt class="fw-normal text-mlm-muted">Direservasi</dt>
                        <dd class="mb-0">{{ $product->reserved_stock }}</dd>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-top mt-1 pt-2">
                        <dt class="fw-semibold">Tersedia Dijual</dt>
                        <dd class="mb-0 fw-bold text-mlm-primary">{{ $product->availableStock() }}</dd>
                    </div>
                </dl>
            @else
                <p class="small text-mlm-muted mb-3">
                    Total stok tersedia seluruh varian aktif: <strong class="text-mlm-primary">{{ $product->availableStock() }}</strong> unit.
                </p>
            @endif
            <p class="small text-mlm-muted mb-3">
                Untuk menjaga jejak audit mutasi stok, perubahan jumlah stok dilakukan melalui modul Inventori &amp; Stok.
            </p>
            @if($admin->hasPermission('inventory.view'))
                <a href="{{ route('admin.inventory.index', ['search' => $product->sku]) }}" class="btn btn-outline-primary w-100 fw-semibold" style="min-height: 44px;">
                    <i class="bi bi-archive me-1" aria-hidden="true"></i> Sesuaikan Stok di Inventori
                </a>
            @endif
        </section>

        {{-- Galeri Gambar Produk --}}
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Galeri Gambar Produk</h2>

            @if($product->images->isNotEmpty())
                <div class="d-flex flex-column gap-3 mb-4">
                    @foreach($product->images as $img)
                        @php
                            $imgUrl = \App\Services\Storefront\HomepageService::mediaUrl($img->image_path);
                        @endphp
                        <div class="border rounded-3 p-2 d-flex align-items-center gap-3">
                            <img src="{{ $imgUrl }}" alt="{{ $img->alt_text ?? $product->name }}" class="rounded-2 object-fit-cover flex-shrink-0" style="width: 64px; height: 64px;">
                            <div class="flex-grow-1 small">
                                @if($img->is_primary)
                                    <span class="badge bg-success-subtle text-success mb-1">Gambar Utama</span>
                                @else
                                    <form method="POST" action="{{ route('admin.products.images.primary', [$product, $img]) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-outline-primary btn-sm py-1 px-2">Jadikan Utama</button>
                                    </form>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('admin.products.images.destroy', [$product, $img]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Hapus gambar">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="small text-mlm-muted mb-3">Belum ada gambar yang diunggah untuk produk ini.</p>
            @endif

            <form method="POST" action="{{ route('admin.products.images.store', $product) }}" enctype="multipart/form-data">
                @csrf
                <label for="uploadMoreImages" class="form-label small fw-semibold">Unggah Gambar Baru</label>
                <input
                    type="file"
                    id="uploadMoreImages"
                    name="images[]"
                    multiple
                    required
                    accept="image/jpeg,image/png,image/webp"
                    class="form-control mb-2"
                >
                <button type="submit" class="btn btn-primary w-100 fw-semibold" style="min-height: 44px;">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i> Unggah Gambar
                </button>
            </form>
        </section>
    </div>
</div>
@endsection
