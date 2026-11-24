@extends('layouts.admin')

@section('title', 'Tambah Halaman Custom')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Halaman & Kebijakan', 'url' => route('admin.pages.index')],
    ['label' => 'Tambah Halaman'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Tambah Halaman Baru</h1>
        <p class="small text-mlm-muted mb-0">Buat halaman informasi tambahan baru dengan slug URL unik, status Enabled/Disabled, dan opsi footer.</p>
    </div>

    <a href="{{ route('admin.pages.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali
    </a>
</div>

<form method="POST" action="{{ route('admin.pages.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.pages._form')
</form>
@endsection
