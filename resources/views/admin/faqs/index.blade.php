@extends('layouts.admin')

@section('title', 'Kelola FAQ')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'FAQ'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Pertanyaan Umum (FAQ)</h1>
        <p class="small text-mlm-muted mb-0">Kelola daftar pertanyaan dan jawaban yang tampil di halaman FAQ storefront.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('storefront.faq') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
            <span>Lihat Storefront</span>
        </a>
        @if($admin->hasPermission('content.manage'))
            <a href="{{ route('admin.faqs.create') }}" class="btn btn-primary fw-semibold d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span>Tambah FAQ</span>
            </a>
        @endif
    </div>
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('admin.faqs.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-4">
            <label for="faqSearch" class="form-label small fw-semibold">Cari Pertanyaan / Jawaban</label>
            <input
                type="text"
                id="faqSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                placeholder="Kata kunci FAQ..."
            >
        </div>

        <div class="col-12 col-md-3">
            <label for="faqCategory" class="form-label small fw-semibold">Kategori</label>
            <select id="faqCategory" name="category" class="form-select">
                <option value="">Semua Kategori</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}" @selected($category === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-12 col-md-2">
            <label for="faqStatus" class="form-label small fw-semibold">Status</label>
            <select id="faqStatus" name="status" class="form-select">
                <option value="all" @selected($status === 'all')>Semua</option>
                <option value="active" @selected($status === 'active')>Aktif</option>
                <option value="inactive" @selected($status === 'inactive')>Nonaktif</option>
            </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold">
                <i class="bi bi-search" aria-hidden="true"></i> Filter
            </button>
            @if($search !== '' || $category !== '' || $status !== 'all')
                <a href="{{ route('admin.faqs.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-width: 44px;" aria-label="Reset filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($faqs->isEmpty())
        <x-empty-state
            icon="bi-question-circle"
            title="Belum Ada FAQ"
            message="Tambahkan pertanyaan yang sering diajukan pelanggan agar tampil di halaman FAQ."
            :action-label="$admin->hasPermission('content.manage') ? 'Tambah FAQ' : null"
            :action-url="$admin->hasPermission('content.manage') ? route('admin.faqs.create') : null"
        />
    @else
        <x-table :headers="['Urutan', 'Pertanyaan &amp; Kategori', 'Status', 'Aksi']">
            @foreach($faqs as $faq)
                <tr>
                    <td data-label="Urutan" class="fw-semibold text-mlm-primary">
                        #{{ $faq->sort_order }}
                    </td>
                    <td data-label="Pertanyaan &amp; Kategori">
                        <span class="badge bg-mlm-secondary text-mlm-primary mb-1">{{ $faq->category ?: 'Umum' }}</span>
                        <div class="fw-semibold text-mlm-primary">{{ $faq->question }}</div>
                        <div class="small text-mlm-muted">{{ \Illuminate\Support\Str::limit(strip_tags((string) $faq->answer), 110) }}</div>
                    </td>
                    <td data-label="Status">
                        @if($faq->is_active)
                            <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Aktif</span>
                        @else
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Nonaktif</span>
                        @endif
                    </td>
                    <td data-label="Aksi">
                        <div class="d-flex flex-wrap gap-2 justify-content-end justify-content-md-start">
                            @if($admin->hasPermission('content.manage'))
                                <form method="POST" action="{{ route('admin.faqs.toggle', $faq) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $faq->is_active ? 'btn-outline-warning' : 'btn-outline-success' }} d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                        <i class="bi {{ $faq->is_active ? 'bi-eye-slash' : 'bi-check-circle' }}" aria-hidden="true"></i>
                                        <span>{{ $faq->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</span>
                                    </button>
                                </form>

                                <a href="{{ route('admin.faqs.edit', $faq) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                                </a>

                                @php
                                    $deleteModalPayload = [
                                        'title' => 'Konfirmasi Hapus FAQ',
                                        'message' => 'Apakah Anda yakin ingin menghapus FAQ "'.\Illuminate\Support\Str::limit($faq->question, 80).'"?',
                                        'confirmLabel' => 'Ya, Hapus FAQ',
                                        'confirmClass' => 'btn-danger',
                                        'formSelector' => '#delete-faq-'.$faq->id,
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
                                    id="delete-faq-{{ $faq->id }}"
                                    method="POST"
                                    action="{{ route('admin.faqs.destroy', $faq) }}"
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

        <x-pagination :paginator="$faqs" />
    @endif
</div>
@endsection
