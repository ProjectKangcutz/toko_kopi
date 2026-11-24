@extends('layouts.admin')

@section('title', 'Kelola Pesanan & Pengiriman')

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Pesanan & Pengiriman'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Pesanan &amp; Pengiriman</h1>
        <p class="small text-mlm-muted mb-0">Kelola pemrosesan pesanan, input nomor resi manual, dan status pengiriman pelanggan.</p>
    </div>
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('admin.orders.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-5">
            <label for="orderSearch" class="form-label small fw-semibold">Cari Pesanan / Pelanggan</label>
            <input
                type="text"
                id="orderSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                style="min-height: 44px;"
                placeholder="No. order, nama, email, atau nomor HP..."
            >
        </div>

        <div class="col-12 col-md-4">
            <label for="orderStatusFilter" class="form-label small fw-semibold">Status Pesanan</label>
            <select id="orderStatusFilter" name="status" class="form-select" style="min-height: 44px;">
                <option value="">Semua Status</option>
                @foreach([
                    'pending' => 'Menunggu (Pending)',
                    'awaiting_payment' => 'Menunggu Pembayaran',
                    'paid' => 'Lunas (Siap Diproses)',
                    'processing' => 'Diproses',
                    'shipped' => 'Dikirim',
                    'delivered' => 'Terkirim',
                    'completed' => 'Selesai',
                    'cancelled' => 'Dibatalkan',
                    'refunded' => 'Refund',
                ] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold" style="min-height: 44px;">
                <i class="bi bi-search me-1" aria-hidden="true"></i> Filter
            </button>
            @if($search !== '' || ! empty($status))
                <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-height: 44px; min-width: 44px;" aria-label="Reset filter">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($orders->isEmpty())
        <x-empty-state
            icon="bi-receipt"
            title="Tidak Ada Pesanan Ditemukan"
            message="Belum ada pesanan yang sesuai dengan filter atau kata kunci pencarian saat ini."
        />
    @else
        <x-table :headers="['No. Order', 'Tanggal', 'Pelanggan', 'Total', 'Pembayaran', 'Status', 'Pengiriman', 'Aksi']">
            @foreach($orders as $order)
                <tr>
                    <td data-label="No. Order" class="fw-semibold text-mlm-primary">
                        {{ $order->order_number }}
                    </td>
                    <td data-label="Tanggal" class="small text-mlm-muted">
                        {{ $order->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                    </td>
                    <td data-label="Pelanggan">
                        <div class="fw-semibold">{{ $order->customer_name_snapshot }}</div>
                        <div class="small text-mlm-muted">{{ $order->customer_phone_snapshot }}</div>
                    </td>
                    <td data-label="Total" class="fw-semibold">
                        Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}
                    </td>
                    <td data-label="Pembayaran">
                        <x-status-badge :status="$order->payment_status" />
                    </td>
                    <td data-label="Status">
                        <x-status-badge :status="$order->order_status" />
                    </td>
                    <td data-label="Pengiriman" class="small">
                        @if($order->shipment)
                            <div class="fw-semibold">{{ $order->shipment->courier_name }}</div>
                            @if(! empty($order->shipment->tracking_number))
                                <div class="text-mlm-primary">Resi: {{ $order->shipment->tracking_number }}</div>
                            @else
                                <x-status-badge :status="$order->shipment_status" />
                            @endif
                        @else
                            <x-status-badge :status="$order->shipment_status" />
                        @endif
                    </td>
                    <td data-label="Aksi">
                        <a
                            href="{{ route('admin.orders.show', $order) }}"
                            class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3"
                            style="min-height: 44px;"
                        >
                            <i class="bi bi-box-seam" aria-hidden="true"></i>
                            <span>Kelola</span>
                        </a>
                    </td>
                </tr>
            @endforeach
        </x-table>

        <x-pagination :paginator="$orders" />
    @endif
</div>
@endsection
