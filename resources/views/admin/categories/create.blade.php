@extends('layouts.admin')

@section('title', 'Tambah Kategori')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Kategori Produk', 'url' => route('admin.categories.index')],
    ['label' => 'Tambah Kategori'],
]" />

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 fw-bold text-mlm-primary mb-0">Tambah Kategori Baru</h1>
</div>

<div class="mlm-card p-3 p-md-4">
    <form method="POST" action="{{ route('admin.categories.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.categories._form')

        <div class="d-flex flex-wrap gap-2 pt-3 mt-3 border-top">
            <button type="submit" class="btn btn-primary fw-semibold px-4" style="min-height: 44px;">
                <i class="bi bi-save me-1" aria-hidden="true"></i> Simpan Kategori
            </button>
            <a href="{{ route('admin.categories.index') }}" class="btn btn-outline-secondary px-4 d-inline-flex align-items-center" style="min-height: 44px;">
                Batal
            </a>
        </div>
    </form>
</div>
@endsection
