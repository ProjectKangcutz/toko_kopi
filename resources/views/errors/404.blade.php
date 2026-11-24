@extends('layouts.auth')

@section('title', '404 — Halaman Tidak Ditemukan')

@section('content')
<div class="mlm-card p-4 p-md-5 text-center">
    <div class="display-5 fw-bold text-mlm-primary mb-2">404</div>
    <h1 class="h5 fw-bold mb-2">Halaman Tidak Ditemukan</h1>
    <p class="small text-mlm-muted mb-4">Tautan yang Anda tuju tidak tersedia atau telah dipindahkan.</p>
    <a href="{{ route('storefront.home') }}" class="btn btn-primary">Kembali ke Beranda</a>
</div>
@endsection
