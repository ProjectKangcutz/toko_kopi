@extends('layouts.admin')

@section('title', 'Edit Halaman')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Halaman & Kebijakan', 'url' => route('admin.pages.index')],
    ['label' => $page->title],
]" />

@php
    $isBuiltIn = $page->isBuiltInPolicy();
@endphp

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 class="h3 fw-bold text-mlm-primary mb-0">Edit Halaman</h1>
            @if($isBuiltIn)
                <span class="badge bg-info-subtle text-info-emphasis px-3 py-2">Kebijakan Bawaan</span>
            @else
                <span class="badge bg-mlm-secondary text-mlm-primary px-3 py-2">Custom Page</span>
            @endif
            @if($page->is_published)
                <span class="badge rounded-pill bg-success-subtle text-success px-3 py-2">Enabled</span>
            @else
                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis px-3 py-2">Disabled</span>
            @endif
        </div>
        <p class="small text-mlm-muted mb-0 font-monospace">/{{ $page->slug }}</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        @if($admin->hasPermission('content.manage'))
            <form method="POST" action="{{ route('admin.pages.toggle', $page) }}" class="d-inline">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn {{ $page->is_published ? 'btn-outline-warning' : 'btn-outline-success' }}">
                    <i class="bi {{ $page->is_published ? 'bi-toggle-on' : 'bi-toggle-off' }}" aria-hidden="true"></i>
                    <span>{{ $page->is_published ? 'Disable Halaman' : 'Enable Halaman' }}</span>
                </button>
            </form>
        @endif

        <a href="{{ route('admin.pages.preview', $page) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary">
            <i class="bi bi-eye" aria-hidden="true"></i> Preview Tersimpan
        </a>

        @if($page->is_published)
            <a href="{{ route('storefront.page', $page->slug) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Lihat Storefront
            </a>
        @endif

        <a href="{{ route('admin.pages.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali
        </a>
    </div>
</div>

<form method="POST" action="{{ route('admin.pages.update', $page) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('admin.pages._form', ['page' => $page])
</form>

@if(! $isBuiltIn && $admin->hasPermission('content.manage'))
    <div class="mlm-card p-3 p-md-4 mt-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 border-danger-subtle">
        <div>
            <h2 class="h6 fw-bold text-danger mb-1">Hapus Halaman Custom</h2>
            <p class="small text-mlm-muted mb-0">Halaman custom akan dihapus dari storefront dan tautan footer akan segera dihilangkan.</p>
        </div>

        @php
            $deleteModalPayload = [
                'title' => 'Konfirmasi Hapus Halaman',
                'message' => 'Apakah Anda yakin ingin menghapus halaman custom "'.$page->title.'" (/' .$page->slug.')?',
                'confirmLabel' => 'Ya, Hapus Halaman',
                'confirmClass' => 'btn-danger',
                'formSelector' => '#delete-page-edit-'.$page->id,
            ];
        @endphp
        <button
            type="button"
            x-data
            @click="$store.confirmModal?.ask(@js($deleteModalPayload))"
            class="btn btn-outline-danger flex-shrink-0"
        >
            <i class="bi bi-trash" aria-hidden="true"></i> Hapus Halaman
        </button>
        <form
            id="delete-page-edit-{{ $page->id }}"
            method="POST"
            action="{{ route('admin.pages.destroy', $page) }}"
            class="d-none"
        >
            @csrf
            @method('DELETE')
        </form>
    </div>
@endif
@endsection
