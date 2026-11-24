@extends('layouts.admin')

@section('title', 'Kelola Halaman & Kebijakan')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Halaman & Kebijakan'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Halaman &amp; Kebijakan Toko</h1>
        <p class="small text-mlm-muted mb-0">Kelola empat halaman kebijakan bawaan dan buat halaman custom tambahan beserta tampilan footer storefront.</p>
    </div>

    @if($admin->hasPermission('content.manage'))
        <a href="{{ route('admin.pages.create') }}" class="btn btn-primary fw-semibold d-inline-flex align-items-center gap-2">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span>Tambah Halaman</span>
        </a>
    @endif
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('admin.pages.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
            <label for="pageSearch" class="form-label small fw-semibold">Cari Judul / Slug</label>
            <input
                type="text"
                id="pageSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                placeholder="Cari halaman..."
            >
        </div>

        <div class="col-12 col-md-3">
            <label for="pageType" class="form-label small fw-semibold">Jenis Halaman</label>
            <select id="pageType" name="type" class="form-select">
                <option value="all" @selected($type === 'all')>Semua Jenis</option>
                <option value="policy" @selected($type === 'policy')>Kebijakan Bawaan</option>
                <option value="custom" @selected($type === 'custom')>Halaman Custom</option>
            </select>
        </div>

        <div class="col-12 col-md-2">
            <label for="pageStatus" class="form-label small fw-semibold">Status</label>
            <select id="pageStatus" name="status" class="form-select">
                <option value="all" @selected($status === 'all')>Semua</option>
                <option value="enabled" @selected($status === 'enabled')>Enabled</option>
                <option value="disabled" @selected($status === 'disabled')>Disabled</option>
            </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold">
                <i class="bi bi-search" aria-hidden="true"></i> Filter
            </button>
            @if($search !== '' || $type !== 'all' || $status !== 'all')
                <a href="{{ route('admin.pages.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-width: 44px;" aria-label="Reset filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($pages->isEmpty())
        <x-empty-state
            icon="bi-file-earmark-text"
            title="Halaman Tidak Ditemukan"
            message="Tidak ada halaman yang sesuai dengan filter saat ini atau tambahkan halaman custom baru."
            :action-label="$admin->hasPermission('content.manage') ? 'Tambah Halaman' : null"
            :action-url="$admin->hasPermission('content.manage') ? route('admin.pages.create') : null"
        />
    @else
        <x-table :headers="['Halaman &amp; URL', 'Jenis &amp; Footer', 'Status', 'Tanggal', 'Aksi']">
            @foreach($pages as $page)
                @php
                    $isBuiltIn = $page->isBuiltInPolicy();
                @endphp
                <tr>
                    <td data-label="Halaman &amp; URL">
                        <div class="fw-semibold text-mlm-primary">{{ $page->title }}</div>
                        <div class="small text-mlm-muted font-monospace">/{{ $page->slug }}</div>
                    </td>
                    <td data-label="Jenis &amp; Footer">
                        @if($isBuiltIn)
                            <span class="badge bg-info-subtle text-info-emphasis mb-1">Kebijakan Bawaan</span>
                        @else
                            <span class="badge bg-mlm-secondary text-mlm-primary mb-1">Custom Page</span>
                        @endif
                        <div class="small text-mlm-muted">
                            Urutan: #{{ $page->sort_order }}
                            @if($isBuiltIn || $page->show_in_footer)
                                &bull; <span class="text-success">Tampil di Footer</span>
                            @else
                                &bull; <span>Tersembunyi dari Footer</span>
                            @endif
                        </div>
                    </td>
                    <td data-label="Status">
                        @if($page->is_published)
                            <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Enabled</span>
                        @else
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Disabled</span>
                        @endif
                    </td>
                    <td data-label="Tanggal" class="small text-mlm-muted">
                        <div>Dibuat: {{ $page->created_at?->translatedFormat('d M Y') }}</div>
                        <div>Diubah: {{ $page->updated_at?->translatedFormat('d M Y H:i') }}</div>
                    </td>
                    <td data-label="Aksi">
                        <div class="d-flex flex-wrap gap-2 justify-content-end justify-content-md-start">
                            <a href="{{ route('admin.pages.preview', $page) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                <i class="bi bi-eye" aria-hidden="true"></i> Preview
                            </a>

                            @if($admin->hasPermission('content.manage'))
                                <form method="POST" action="{{ route('admin.pages.toggle', $page) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button
                                        type="submit"
                                        class="btn btn-sm {{ $page->is_published ? 'btn-outline-warning' : 'btn-outline-success' }} d-inline-flex align-items-center gap-1 px-3"
                                        style="min-height: 44px;"
                                    >
                                        <i class="bi {{ $page->is_published ? 'bi-toggle-on' : 'bi-toggle-off' }}" aria-hidden="true"></i>
                                        <span>{{ $page->is_published ? 'Disable' : 'Enable' }}</span>
                                    </button>
                                </form>

                                <a href="{{ route('admin.pages.edit', $page) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                                </a>

                                @if(! $isBuiltIn)
                                    @php
                                        $deleteModalPayload = [
                                            'title' => 'Konfirmasi Hapus Halaman',
                                            'message' => 'Apakah Anda yakin ingin menghapus halaman custom "'.$page->title.'" (/' .$page->slug.')? Tautan dan akses publik halaman ini akan langsung dihentikan.',
                                            'confirmLabel' => 'Ya, Hapus Halaman',
                                            'confirmClass' => 'btn-danger',
                                            'formSelector' => '#delete-page-'.$page->id,
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
                                        id="delete-page-{{ $page->id }}"
                                        method="POST"
                                        action="{{ route('admin.pages.destroy', $page) }}"
                                        class="d-none"
                                    >
                                        @csrf
                                        @method('DELETE')
                                    </form>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-table>

        <x-pagination :paginator="$pages" />
    @endif
</div>
@endsection
