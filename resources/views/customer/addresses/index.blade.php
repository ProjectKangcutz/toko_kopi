@extends('layouts.customer')

@section('title', 'Daftar Alamat Pengiriman')
@section('page_title', 'Daftar Alamat Pengiriman')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Akun Saya', 'url' => route('customer.dashboard')],
    ['label' => 'Alamat Pengiriman'],
]" />

<div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mb-4">
    <div>
        <h2 class="h4 fw-bold mb-1">Alamat Pengiriman Saya</h2>
        <p class="text-mlm-muted small mb-0">
            Kelola alamat pengiriman untuk mempermudah dan mempercepat proses checkout Anda.
        </p>
    </div>
    <a href="{{ route('customer.addresses.create') }}" class="btn btn-primary flex-shrink-0">
        <i class="bi bi-plus-lg"></i> Tambah Alamat
    </a>
</div>

@if($addresses->isEmpty())
    <x-empty-state
        icon="bi-geo-alt"
        title="Belum Ada Alamat Tersimpan"
        message="Tambahkan alamat pengiriman pertama Anda agar proses pemesanan dan perhitungan ongkos kirim berjalan lebih cepat."
        action-label="Tambah Alamat Pertama"
        :action-url="route('customer.addresses.create')"
    />
@else
    <div class="row g-3">
        @foreach($addresses as $address)
            <div class="col-12 col-xl-6">
                <article class="mlm-card mlm-address-card p-3 p-md-4 h-100 d-flex flex-column {{ $address->is_primary ? 'is-primary' : '' }}">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-bold fs-6">{{ $address->label }}</span>
                            @if($address->is_primary)
                                <span class="badge bg-mlm-secondary text-mlm-primary border border-success-subtle px-2 py-1">
                                    <i class="bi bi-check-circle-fill me-1"></i> Alamat Utama
                                </span>
                            @endif
                            @if($address->province_id === 'manual' || ! $address->subdistrict_id)
                                <span class="badge text-bg-light border text-mlm-muted px-2 py-1">
                                    Input Manual
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="mb-3 flex-grow-1">
                        <div class="fw-semibold text-body">{{ $address->recipient_name }}</div>
                        <div class="small text-mlm-muted mb-2">
                            <i class="bi bi-telephone me-1"></i>{{ $address->recipient_phone }}
                        </div>
                        <p class="mb-2 small lh-base">{{ $address->address_line }}</p>
                        <div class="small text-mlm-muted">
                            <i class="bi bi-geo-alt me-1 text-mlm-primary"></i>{{ $address->formattedRegion() }}
                        </div>
                    </div>

                    <div class="mlm-address-actions d-flex flex-wrap align-items-center gap-2 pt-3 border-top">
                        <a href="{{ route('customer.addresses.edit', $address) }}" class="btn btn-outline-primary btn-sm px-3">
                            <i class="bi bi-pencil-square"></i> Edit
                        </a>

                        @if(! $address->is_primary)
                            <form method="POST" action="{{ route('customer.addresses.set-primary', $address) }}" class="d-inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-outline-secondary btn-sm px-3">
                                    <i class="bi bi-star"></i> Jadikan Utama
                                </button>
                            </form>
                        @endif

                        <form
                            id="delete-address-form-{{ $address->id }}"
                            method="POST"
                            action="{{ route('customer.addresses.destroy', $address) }}"
                            class="d-inline ms-sm-auto"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm px-3"
                                x-data
                                @click="$store.confirmModal.ask({
                                    title: 'Hapus Alamat Pengiriman',
                                    message: @js('Apakah Anda yakin ingin menghapus alamat "' . $address->label . '" milik ' . $address->recipient_name . '?'),
                                    confirmLabel: 'Ya, Hapus Alamat',
                                    formSelector: '#delete-address-form-{{ $address->id }}'
                                })"
                            >
                                <i class="bi bi-trash"></i> Hapus
                            </button>
                        </form>
                    </div>
                </article>
            </div>
        @endforeach
    </div>
@endif
@endsection
