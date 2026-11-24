@extends('layouts.customer')

@section('title', 'Ubah Alamat Pengiriman')
@section('page_title', 'Ubah Alamat Pengiriman')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Akun Saya', 'url' => route('customer.dashboard')],
    ['label' => 'Alamat Pengiriman', 'url' => route('customer.addresses.index')],
    ['label' => 'Edit Alamat'],
]" />

<div class="mlm-card p-3 p-md-4">
    <div class="mb-4 pb-3 border-bottom">
        <h2 class="h5 fw-bold mb-1">Ubah Alamat: {{ $address->label }}</h2>
        <p class="small text-mlm-muted mb-0">
            Perbarui data penerima, wilayah pengiriman, atau status alamat utama.
        </p>
    </div>

    <form method="POST" action="{{ route('customer.addresses.update', $address) }}">
        @csrf
        @method('PUT')
        @include('customer.addresses._form', ['address' => $address, 'hasOtherAddresses' => $hasOtherAddresses])
    </form>
</div>
@endsection
