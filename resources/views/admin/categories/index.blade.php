@extends('layouts.admin')

@section('title', 'Kelola Kategori Produk')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Kategori Produk'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Kategori Produk</h1>
        <p class="small text-mlm-muted mb-0">Kelola struktur kategori, induk kategori, urutan tampilan, dan visibilitas di storefront.</p>
    </div>

    @if($admin->hasPermission('categories.create'))
        <a href="{{ route('admin.categories.create') }}" class="btn btn-primary fw-semibold d-inline-flex align-items-center gap-2" style="min-height: 44px;">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>Tambah Kategori</span>
        </a>
    @endif
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('admin.categories.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-5">
            <label for="catSearch" class="form-label small fw-semibold">Cari Kategori</label>
            <input
                type="text"
                id="catSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                style="min-height: 44px;"
                placeholder="Nama kategori atau slug..."
            >
        </div>

        <div class="col-12 col-md-4">
            <label for="catStatus" class="form-label small fw-semibold">Status Aktif</label>
            <select id="catStatus" name="status" class="form-select" style="min-height: 44px;">
                <option value="">Semua Status</option>
                <option value="active" @selected($status === 'active')>Aktif</option>
                <option value="inactive" @selected($status === 'inactive')>Nonaktif</option>
            </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold" style="min-height: 44px;">
                <i class="bi bi-search me-1" aria-hidden="true"></i> Filter
            </button>
            @if($search !== '' || $status !== '')
                <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-height: 44px; min-width: 44px;" aria-label="Reset filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($categories->isEmpty())
        <x-empty-state
            icon="bi-tags"
            title="Belum Ada Kategori"
            message="Tambahkan kategori produk untuk mengelompokkan katalog toko Anda."
            :action-label="$admin->hasPermission('categories.create') ? 'Tambah Kategori' : null"
            :action-url="$admin->hasPermission('categories.create') ? route('admin.categories.create') : null"
        />
    @else
        <x-table :headers="['Kategori', 'Induk', 'Jumlah Produk', 'Urutan', 'Status', 'Aksi']">
            @foreach($categories as $category)
                @php
                    $thumbUrl = \App\Services\Storefront\HomepageService::mediaUrl($category->image_path);
                @endphp
                <tr>
                    <td data-label="Kategori">
                        <div class="d-flex align-items-center gap-3">
                            @if($thumbUrl)
                                <img src="{{ $thumbUrl }}" alt="{{ $category->name }}" class="rounded-3 border object-fit-cover flex-shrink-0" style="width: 44px; height: 44px;">
                            @else
                                <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                                    <i class="bi bi-tag" aria-hidden="true"></i>
                                </span>
                            @endif
                            <div>
                                <div class="fw-semibold text-mlm-primary">{{ $category->name }}</div>
                                <div class="small text-mlm-muted font-monospace">{{ $category->slug }}</div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Induk" class="small">
                        {{ $category->parent?->name ?? 'Kategori Utama' }}
                    </td>
                    <td data-label="Jumlah Produk">
                        <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">{{ $category->products_count }} produk</span>
                    </td>
                    <td data-label="Urutan">{{ $category->sort_order }}</td>
                    <td data-label="Status">
                        @if($category->is_active)
                            <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Aktif</span>
                        @else
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Nonaktif</span>
                        @endif
                    </td>
                    <td data-label="Aksi">
                        <div class="d-flex flex-wrap gap-2 justify-content-end justify-content-md-start">
                            @if($admin->hasPermission('categories.update'))
                                <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                                </a>
                            @endif
                            @if($admin->hasPermission('categories.delete'))
                                @php
                                    $deleteCategoryPayload = [
                                        'title' => 'Konfirmasi Hapus Kategori',
                                        'message' => 'Apakah Anda yakin ingin menghapus kategori "'.$category->name.'"?',
                                        'confirmLabel' => 'Ya, Hapus Kategori',
                                        'confirmClass' => 'btn-danger',
                                        'formSelector' => '#delete-category-'.$category->id,
                                    ];
                                @endphp
                                <button
                                    type="button"
                                    x-data
                                    @click="$store.confirmModal?.ask(@js($deleteCategoryPayload))"
                                    class="btn btn-outline-danger btn-sm d-inline-flex align-items-center gap-1 px-3"
                                    style="min-height: 44px;"
                                >
                                    <i class="bi bi-trash" aria-hidden="true"></i> Hapus
                                </button>
                                <form
                                    id="delete-category-{{ $category->id }}"
                                    method="POST"
                                    action="{{ route('admin.categories.destroy', $category) }}"
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

        <x-pagination :paginator="$categories" />
    @endif
</div>
@endsection
