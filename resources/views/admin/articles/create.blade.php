@extends('layouts.admin')

@section('title', 'Tulis Artikel Baru')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Artikel', 'url' => route('admin.articles.index')],
    ['label' => 'Tulis Artikel'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Tulis Artikel Baru</h1>
        <p class="small text-mlm-muted mb-0">Buat artikel baru sebagai draf untuk ditinjau atau langsung publikasikan ke storefront.</p>
    </div>

    <a href="{{ route('admin.articles.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali ke Daftar
    </a>
</div>

<form method="POST" action="{{ route('admin.articles.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.articles._form')
</form>
@endsection
