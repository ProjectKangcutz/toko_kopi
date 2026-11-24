@extends('layouts.customer')

@section('title', 'Tambah Alamat Baru')
@section('page_title', 'Tambah Alamat Baru')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Akun Saya', 'url' => route('customer.dashboard')],
    ['label' => 'Alamat Pengiriman', 'url' => route('customer.addresses.index')],
    ['label' => 'Tambah Alamat'],
]" />

<div class="mlm-card p-3 p-md-4">
    <div class="mb-4 pb-3 border-bottom">
        <h2 class="h5 fw-bold mb-1">Tambah Alamat Pengiriman</h2>
        <p class="small text-mlm-muted mb-0">
            Lengkapi detail penerima dan pilih wilayah pengiriman Anda.
        </p>
    </div>

    <form method="POST" action="{{ route('customer.addresses.store') }}">
        @csrf
        @include('customer.addresses._form', ['address' => null, 'hasAddresses' => $hasAddresses])
    </form>
</div>
@endsection
