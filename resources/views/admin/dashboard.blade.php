@extends('layouts.admin')

@section('title', 'Ringkasan Admin')

@section('content')
<x-breadcrumb :items="[['label' => 'Admin Dashboard']]" />

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Total Penjualan</div>
            <div class="h5 fw-bold text-mlm-primary mb-0">Rp {{ number_format($metrics['total_sales'], 0, ',', '.') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Penjualan Hari Ini</div>
            <div class="h5 fw-bold mb-0">Rp {{ number_format($metrics['today_sales'], 0, ',', '.') }}</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Pesanan Baru / Pending</div>
            <div class="h5 fw-bold text-warning mb-0">{{ $metrics['new_orders'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-12 col-xl-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Total Pesanan</div>
            <div class="h5 fw-bold mb-0">{{ $metrics['total_orders'] }}</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="mlm-card p-3 p-md-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 fw-bold mb-0">Transaksi Terbaru</h2>
                @if($admin && $admin->hasPermission('orders.view'))
                    <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center" style="min-height: 44px;">
                        Kelola Semua Pesanan
                    </a>
                @endif
            </div>
            @if($recentOrders->isEmpty())
                <x-empty-state icon="bi-receipt" title="Belum Ada Transaksi" message="Pesanan pelanggan yang masuk akan tampil di sini secara otomatis." />
            @else
                <x-table :headers="['No. Order', 'Pelanggan', 'Total', 'Pembayaran', 'Status', 'Aksi']">
                    @foreach($recentOrders as $order)
                        <tr>
                            <td data-label="No. Order" class="fw-semibold">{{ $order->order_number }}</td>
                            <td data-label="Pelanggan">{{ $order->customer_name_snapshot }}</td>
                            <td data-label="Total">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</td>
                            <td data-label="Pembayaran"><x-status-badge :status="$order->payment_status" /></td>
                            <td data-label="Status"><x-status-badge :status="$order->order_status" /></td>
                            <td data-label="Aksi">
                                @if($admin && $admin->hasPermission('orders.view'))
                                    <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center" style="min-height: 44px;">
                                        Detail
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="mlm-card p-3 p-md-4 h-100">
            <h2 class="h6 fw-bold mb-3">Aktivitas Audit Terbaru</h2>
            @if($recentActivities->isEmpty())
                <x-empty-state icon="bi-activity" title="Belum Ada Aktivitas" message="Log audit login dan perubahan data akan tercatat di sini." />
            @else
                <ul class="list-group list-group-flush small">
                    @foreach($recentActivities as $log)
                        <li class="list-group-item px-0">
                            <div class="fw-semibold">{{ $log->description }}</div>
                            <div class="text-mlm-muted">{{ $log->action }} • {{ $log->created_at?->diffForHumans() }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

@if($admin && $admin->hasPermission('inventory.view'))
    <div class="mlm-card p-3 p-md-4 mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 fw-bold mb-0">Peringatan Stok Menipis</h2>
            <a href="{{ route('admin.inventory.index', ['filter' => 'low']) }}" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center" style="min-height: 44px;">
                Kelola Inventori &amp; Stok
            </a>
        </div>
        @if($lowStockProducts->isEmpty())
            <p class="small text-mlm-muted mb-0">Seluruh produk aktif saat ini memiliki stok di atas batas minimum.</p>
        @else
            <x-table :headers="['SKU', 'Nama Produk', 'Stok Fisik', 'Reservasi', 'Min. Stok']">
                @foreach($lowStockProducts as $lowProduct)
                    <tr>
                        <td data-label="SKU" class="font-monospace small">{{ $lowProduct->sku }}</td>
                        <td data-label="Nama Produk" class="fw-semibold">{{ $lowProduct->name }}</td>
                        <td data-label="Stok Fisik" class="text-danger fw-bold">{{ $lowProduct->stock }}</td>
                        <td data-label="Reservasi">{{ $lowProduct->reserved_stock }}</td>
                        <td data-label="Min. Stok">{{ $lowProduct->min_stock }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </div>
@endif
@endsection
