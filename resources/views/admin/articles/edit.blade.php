@extends('layouts.admin')

@section('title', 'Edit Artikel')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Artikel', 'url' => route('admin.articles.index')],
    ['label' => $article->title],
]" />

@php
    $isPubliclyVisible = $article->status === 'published' && (! $article->published_at || $article->published_at->lessThanOrEqualTo(now()));
@endphp

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h3 fw-bold text-mlm-primary mb-0">Edit Artikel</h1>
            @if($isPubliclyVisible)
                <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Published</span>
            @elseif($article->status === 'published')
                <span class="badge rounded-pill bg-info-subtle text-info-emphasis px-3 py-2">Terjadwal</span>
            @else
                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Draft</span>
            @endif
        </div>
        <p class="small text-mlm-muted mb-0 font-monospace">/artikel/{{ $article->slug }}</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.articles.preview', $article) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">
            <i class="bi bi-eye" aria-hidden="true"></i> Preview Tersimpan
        </a>
        @if($isPubliclyVisible)
            <a href="{{ route('storefront.article', $article->slug) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Lihat di Storefront
            </a>
        @endif
        <a href="{{ route('admin.articles.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali
        </a>
    </div>
</div>

<form method="POST" action="{{ route('admin.articles.update', $article) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('admin.articles._form', ['article' => $article])
</form>

@if($admin->hasPermission('content.manage'))
    <div class="mlm-card p-3 p-md-4 mt-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 border-danger-subtle">
        <div>
            <h2 class="h6 fw-bold text-danger mb-1">Arsipkan / Hapus Artikel</h2>
            <p class="small text-mlm-muted mb-0">Artikel akan ditarik dari storefront agar tidak lagi dapat diakses publik.</p>
        </div>

        @php
            $deleteModalPayload = [
                'title' => 'Konfirmasi Hapus Artikel',
                'message' => 'Apakah Anda yakin ingin menghapus artikel "'.$article->title.'"? Artikel akan ditarik dari storefront dan diarsipkan.',
                'confirmLabel' => 'Ya, Hapus Artikel',
                'confirmClass' => 'btn-danger',
                'formSelector' => '#delete-article-edit-'.$article->id,
            ];
        @endphp
        <button
            type="button"
            x-data
            @click="$store.confirmModal?.ask(@js($deleteModalPayload))"
            class="btn btn-outline-danger flex-shrink-0"
        >
            <i class="bi bi-trash" aria-hidden="true"></i> Hapus Artikel
        </button>
        <form
            id="delete-article-edit-{{ $article->id }}"
            method="POST"
            action="{{ route('admin.articles.destroy', $article) }}"
            class="d-none"
        >
            @csrf
            @method('DELETE')
        </form>
    </div>
@endif
@endsection
