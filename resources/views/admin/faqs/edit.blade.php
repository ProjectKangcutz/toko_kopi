@extends('layouts.admin')

@section('title', 'Edit FAQ')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'FAQ', 'url' => route('admin.faqs.index')],
    ['label' => 'Edit FAQ'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Edit FAQ</h1>
        <p class="small text-mlm-muted mb-0">Perbarui pertanyaan, jawaban, kategori, urutan tampil, atau status aktif FAQ.</p>
    </div>

    <a href="{{ route('admin.faqs.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali
    </a>
</div>

<form method="POST" action="{{ route('admin.faqs.update', $faq) }}">
    @csrf
    @method('PUT')
    @include('admin.faqs._form', ['faq' => $faq])
</form>
@endsection
