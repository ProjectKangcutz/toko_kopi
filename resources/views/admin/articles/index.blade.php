@extends('layouts.admin')

@section('title', 'Kelola Artikel')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Artikel'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Manajemen Artikel</h1>
        <p class="small text-mlm-muted mb-0">Kelola artikel cerita UMKM, panduan belanja, status publikasi, dan metadata SEO.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('storefront.articles') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
            <span>Lihat Storefront</span>
        </a>
        @if($admin->hasPermission('content.manage'))
            <a href="{{ route('admin.articles.create') }}" class="btn btn-primary fw-semibold d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span>Tulis Artikel</span>
            </a>
        @endif
    </div>
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('admin.articles.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-5">
            <label for="articleSearch" class="form-label small fw-semibold">Cari Judul / Slug / Ringkasan</label>
            <input
                type="text"
                id="articleSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                placeholder="Cari artikel..."
            >
        </div>

        <div class="col-12 col-md-4">
            <label for="articleStatus" class="form-label small fw-semibold">Status Publikasi</label>
            <select id="articleStatus" name="status" class="form-select">
                <option value="all" @selected($status === 'all')>Semua Status</option>
                <option value="published" @selected($status === 'published')>Published (Tayang)</option>
                <option value="draft" @selected($status === 'draft')>Draft (Belum Tayang)</option>
            </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold">
                <i class="bi bi-search" aria-hidden="true"></i> Filter
            </button>
            @if($search !== '' || $status !== 'all')
                <a href="{{ route('admin.articles.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-width: 44px;" aria-label="Reset filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($articles->isEmpty())
        <x-empty-state
            icon="bi-journal-richtext"
            title="Belum Ada Artikel"
            message="Mulai tulis artikel pertama Anda atau ubah filter pencarian saat ini."
            :action-label="$admin->hasPermission('content.manage') ? 'Tulis Artikel' : null"
            :action-url="$admin->hasPermission('content.manage') ? route('admin.articles.create') : null"
        />
    @else
        <x-table :headers="['Artikel', 'Penulis', 'Status &amp; Publikasi', 'Aksi']">
            @foreach($articles as $article)
                @php
                    $thumbUrl = \App\Services\Storefront\HomepageService::mediaUrl($article->featured_image_path);
                    $isScheduledFuture = $article->status === 'published' && $article->published_at && $article->published_at->isFuture();
                @endphp
                <tr>
                    <td data-label="Artikel">
                        <div class="d-flex align-items-center gap-3">
                            @if($thumbUrl)
                                <img src="{{ $thumbUrl }}" alt="{{ $article->featured_image_alt ?: $article->title }}" class="rounded-3 border object-fit-cover flex-shrink-0" style="width: 56px; height: 48px;">
                            @else
                                <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 56px; height: 48px;">
                                    <i class="bi bi-journal-text" aria-hidden="true"></i>
                                </span>
                            @endif
                            <div>
                                <div class="fw-semibold text-mlm-primary">{{ $article->title }}</div>
                                <div class="small text-mlm-muted font-monospace">/artikel/{{ $article->slug }}</div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Penulis" class="small">
                        {{ $article->author?->name ?? 'Admin' }}
                    </td>
                    <td data-label="Status &amp; Publikasi">
                        @if($article->status === 'published' && ! $isScheduledFuture)
                            <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Published</span>
                        @elseif($isScheduledFuture)
                            <span class="badge rounded-pill bg-info-subtle text-info-emphasis px-3 py-2">Terjadwal</span>
                        @else
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Draft</span>
                        @endif
                        <div class="small text-mlm-muted mt-1">
                            {{ ($article->published_at ?? $article->updated_at)?->translatedFormat('d M Y H:i') }}
                        </div>
                    </td>
                    <td data-label="Aksi">
                        <div class="d-flex flex-wrap gap-2 justify-content-end justify-content-md-start">
                            <a href="{{ route('admin.articles.preview', $article) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                <i class="bi bi-eye" aria-hidden="true"></i> Preview
                            </a>

                            @if($admin->hasPermission('content.manage'))
                                @if($article->status === 'published')
                                    <form method="POST" action="{{ route('admin.articles.unpublish', $article) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-outline-warning btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                            <i class="bi bi-pause-circle" aria-hidden="true"></i> Tarik Draft
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.articles.publish', $article) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn-outline-success btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                            <i class="bi bi-send-check" aria-hidden="true"></i> Publish
                                        </button>
                                    </form>
                                @endif

                                <a href="{{ route('admin.articles.edit', $article) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3" style="min-height: 44px;">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit
                                </a>

                                @php
                                    $deleteModalPayload = [
                                        'title' => 'Konfirmasi Hapus Artikel',
                                        'message' => 'Apakah Anda yakin ingin menghapus artikel "'.$article->title.'"? Artikel akan ditarik dari storefront dan diarsipkan.',
                                        'confirmLabel' => 'Ya, Hapus Artikel',
                                        'confirmClass' => 'btn-danger',
                                        'formSelector' => '#delete-article-'.$article->id,
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
                                    id="delete-article-{{ $article->id }}"
                                    method="POST"
                                    action="{{ route('admin.articles.destroy', $article) }}"
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

        <x-pagination :paginator="$articles" />
    @endif
</div>
@endsection
