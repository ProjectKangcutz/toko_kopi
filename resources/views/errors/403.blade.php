@extends('layouts.auth')

@section('title', '403 — Akses Ditolak')

@section('content')
<div class="mlm-card p-4 p-md-5 text-center">
    <div class="display-5 fw-bold text-danger mb-2">403</div>
    <h1 class="h5 fw-bold mb-2">Akses Tidak Diizinkan</h1>
    <p class="small text-mlm-muted mb-4">{{ isset($exception) && $exception->getMessage() ? $exception->getMessage() : 'Anda tidak memiliki hak akses untuk halaman ini.' }}</p>
    <a href="{{ route('storefront.home') }}" class="btn btn-primary">Kembali ke Beranda</a>
</div>
@endsection
