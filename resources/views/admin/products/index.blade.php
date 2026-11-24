@extends('layouts.admin')

@section('title', 'Kelola Produk')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Produk'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Produk Katalog</h1>
        <p class="small text-mlm-muted mb-0">Kelola daftar produk UMKM, harga, promo, kategori, gambar, dan varian produk.</p>
    </div>

    @if($admin->hasPermission('products.create'))
        <a href="{{ route('admin.products.create') }}" class="btn btn-primary fw-semibold d-inline-flex align-items-center gap-2" style="min-height: 44px;">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>Tambah Produk</span>
        </a>
    @endif
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('admin.products.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
            <label for="prodSearch" class="form-label small fw-semibold">Cari Nama / SKU</label>
            <input
                type="text"
                id="prodSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                style="min-height: 44px;"
                placeholder="Nama produk atau SKU..."
            >
        </div>

        <div class="col-12 col-md-3">
            <label for="prodCategory" class="form-label small fw-semibold">Kategori</label>
            <select id="prodCategory" name="category_id" class="form-select" style="min-height: 44px;">
                <option value="">Semua Kategori</option>
                @foreach($categories as $catId => $catName)
                    <option value="{{ $catId }}" @selected($categoryId === (int) $catId)>{{ $catName }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-12 col-md-2">
            <label for="prodStatus" class="form-label small fw-semibold">Status</label>
            <select id="prodStatus" name="status" class="form-select" style="min-height: 44px;">
                <option value="">Semua</option>
                <option value="active" @selected($status === 'active')>Aktif</option>
                <option value="inactive" @selected($status === 'inactive')>Nonaktif</option>
                <option value="low_stock" @selected($status === 'low_stock')>Stok Menipis</option>
            </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold" style="min-height: 44px;">
                <i class="bi bi-search me-1" aria-hidden="true"></i> Filter
            </button>
            @if($search !== '' || $categoryId > 0 || $status !== '')
                <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-height: 44px; min-width: 44px;" aria-label="Reset filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($products->isEmpty())
        <x-empty-state
            icon="bi-box-seam"
            title="Belum Ada Produk"
            message="Tambahkan produk UMKM pertama Anda atau ubah filter pencarian saat ini."
            :action-label="$admin->hasPermission('products.create') ? 'Tambah Produk' : null"
            :action-url="$admin->hasPermission('products.create') ? route('admin.products.create') : null"
        />
    @else
        <x-table :headers="['Produk', 'Kategori', 'Harga', 'Stok Tersedia', 'Status', 'Aksi']">
            @foreach($products as $product)
                @php
                    $primaryImg = $product->images->first();
                    $thumbUrl = \App\Services\Storefront\HomepageService::mediaUrl($primaryImg?->image_path);
                    $pricing = $product->displayPricing();
                    $available = $product->availableStock();
                @endphp
                <tr>
                    <td data-label="Produk">
                        <div class="d-flex align-items-center gap-3">
                            @if($thumbUrl)
                                <img src="{{ $thumbUrl }}" alt="{{ $product->name }}" class="rounded-3 border object-fit-cover flex-shrink-0" style="width: 48px; height: 48px;">
                            @else
                                <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;">
                                    <i class="bi bi-image" aria-hidden="true"></i>
                                </span>
                            @endif
                            <div>
                                <div class="fw-semibold text-mlm-primary">{{ $product->name }}</div>
                                <div class="small text-mlm-muted">
                                    <span class="font-monospace">{{ $product->sku }}</span>
                                    @if($product->has_variants)
                                        • <span class="badge bg-info-subtle text-info-emphasis">{{ $product->variants->count() }} Varian</span>
                                    @endif
                                    @if($product->is_featured)
                                        • <span class="badge bg-warning-subtle text-warning-emphasis">Unggulan</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Kategori" class="small">
                        {{ $product->categories->pluck('name')->implode(', ') ?: '-' }}
                    </td>
                    <td data-label="Harga">
                        <div class="fw-semibold text-mlm-primary">
                            @if($pricing['starts_from'])Mulai @endif
                            Rp {{ number_format((float) $pricing['effective_price'], 0, ',', '.') }}
                        </div>
                        @if($pricing['is_promo'])
                            <div class="small text-mlm-muted text-decoration-line-through">
                                Rp {{ number_format((float) $pricing['regular_price'], 0, ',', '.') }}
                            </div>
                        @endif
                    </td>
                    <td data-label="Stok Tersedia">
                        @if($product->has_variants)
                            <span class="fw-semibold {{ $available <= 0 ? 'text-danger' : 'text-mlm-primary' }}">
                                {{ $available }}
                            </span>
                            <div class="small text-mlm-muted">Total semua varian aktif</div>
                        @else
                            <span class="fw-semibold {{ $available <= $product->min_stock ? 'text-danger' : 'text-mlm-primary' }}">
                                {{ $available }}
                            </span>
                            <div class="small text-mlm-muted">Fisik: {{ $product->stock }} • Res: {{ $product->reserved_stock }}</div>
                        @endif
                    </td>
                    <td data-label="Status">
                        @if($product->is_active)
                            <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Aktif</span>
                        @else
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Nonaktif</span>
                        @endif
                    </td>
                    <td data-label="Aksi">
                        <div class="d-flex flex-wrap gap-2 justify-content-end justify-content-md-start">
                            @if($admin->hasPermission('products.update'))
                                <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                                </a>
                            @endif
                            @if($admin->hasPermission('products.delete'))
                                @php
                                    $deleteModalPayload = [
                                        'title' => 'Konfirmasi Hapus Produk',
                                        'message' => 'Apakah Anda yakin ingin menghapus produk "'.$product->name.'"? Jika produk sudah memiliki riwayat transaksi atau stok, produk akan dinonaktifkan dan diarsipkan secara aman.',
                                        'confirmLabel' => 'Ya, Hapus Produk',
                                        'confirmClass' => 'btn-danger',
                                        'formSelector' => '#delete-product-'.$product->id,
                                    ];
                                @endphp
                                <button
                                    type="button"
                                    x-data
                                    @click="$store.confirmModal?.ask(@js($deleteModalPayload))"
                                    class="btn btn-outline-danger btn-sm d-inline-flex align-items-center gap-1 px-3"
                                    style="min-height: 44px;"
                                >
                                    <i class="bi bi-trash" aria-hidden="true"></i> Hapus
                                </button>
                                <form
                                    id="delete-product-{{ $product->id }}"
                                    method="POST"
                                    action="{{ route('admin.products.destroy', $product) }}"
                                    class="d-none"
                                >
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>

        <x-pagination :paginator="$products" />
    @endif
</div>
@endsection
