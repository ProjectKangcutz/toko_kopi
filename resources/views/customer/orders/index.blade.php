@extends('layouts.customer')

@section('title', 'Pesanan Saya')
@section('page_title', 'Pesanan Saya')

@section('content')
<x-breadcrumb :items="$breadcrumbs" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Pesanan Saya</h1>
        <p class="small text-mlm-muted mb-0">Pantau seluruh riwayat transaksi, status pembayaran, dan pelacakan pengiriman Anda.</p>
    </div>
</div>

<div class="mlm-card p-3 p-md-4 mb-4">
    <form method="GET" action="{{ route('customer.orders.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-5">
            <label for="customerOrderSearch" class="form-label small fw-semibold">Cari Nomor Pesanan</label>
            <input
                type="text"
                id="customerOrderSearch"
                name="search"
                value="{{ $search }}"
                class="form-control"
                style="min-height: 44px;"
                placeholder="Contoh: PLN-2026..."
            >
        </div>

        <div class="col-12 col-md-4">
            <label for="customerOrderStatus" class="form-label small fw-semibold">Status Pesanan</label>
            <select id="customerOrderStatus" name="status" class="form-select" style="min-height: 44px;">
                <option value="">Semua Status</option>
                @foreach([
                    'pending' => 'Menunggu (Pending)',
                    'awaiting_payment' => 'Menunggu Pembayaran',
                    'paid' => 'Lunas',
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
            @if($search !== '' || $status !== '')
                <a
                    href="{{ route('customer.orders.index') }}"
                    class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center"
                    style="min-height: 44px; min-width: 44px;"
                    aria-label="Reset filter"
                >
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="mlm-card p-3 p-md-4">
    @if($orders->isEmpty())
        <x-empty-state
            icon="bi-bag"
            title="Belum Ada Pesanan Ditemukan"
            message="{{ ($search !== '' || $status !== '') ? 'Tidak ada pesanan yang sesuai dengan filter saat ini.' : 'Anda belum memiliki riwayat pesanan. Mulai belanja produk UMKM pilihan kami.' }}"
            :action-label="($search !== '' || $status !== '') ? 'Reset Filter' : 'Belanja Sekarang'"
            :action-url="($search !== '' || $status !== '') ? route('customer.orders.index') : route('storefront.home')"
        />
    @else
        <x-table :headers="['No. Order', 'Tanggal', 'Total', 'Pembayaran', 'Status Pesanan', 'Pengiriman', 'Aksi']">
            @foreach($orders as $order)
                @php
                    $paymentState = $orderPaymentStates[$order->id] ?? ['can_pay' => false];
                    $canPay = ! empty($paymentState['can_pay']) && ! empty($paymentState['js_config']);
                @endphp
                <tr>
                    <td data-label="No. Order" class="fw-semibold text-mlm-primary">
                        {{ $order->order_number }}
                    </td>
                    <td data-label="Tanggal" class="small text-mlm-muted">
                        {{ $order->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                    </td>
                    <td data-label="Total" class="fw-semibold">
                        Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}
                    </td>
                    <td data-label="Pembayaran">
                        <x-status-badge :status="$order->payment_status" />
                    </td>
                    <td data-label="Status Pesanan">
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
                        @if($canPay)
                            <div
                                x-data="orderPaymentPanel(@js($paymentState['js_config']))"
                                class="d-flex flex-column align-items-end align-items-md-start gap-2"
                            >
                                <div class="d-flex flex-wrap justify-content-end justify-content-md-start gap-2">
                                    <a
                                        href="{{ route('customer.orders.show', $order) }}"
                                        class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3"
                                        style="min-height: 44px;"
                                    >
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                        <span>Detail Pesanan</span>
                                    </a>
                                    <button
                                        type="button"
                                        class="btn btn-primary btn-sm px-3"
                                        style="min-height: 44px;"
                                        :disabled="loading || refreshing"
                                        @click="startPayment()"
                                    >
                                        <span x-show="!loading">
                                            <span class="d-inline-flex align-items-center gap-1">
                                                <i class="bi bi-credit-card" aria-hidden="true"></i>
                                                <span>Bayar Sekarang</span>
                                            </span>
                                        </span>
                                        <span x-show="loading" x-cloak>
                                            <span class="d-inline-flex align-items-center gap-1">
                                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                                <span>Memproses...</span>
                                            </span>
                                        </span>
                                    </button>
                                </div>
                                <div
                                    x-show="feedbackMessage"
                                    x-cloak
                                    class="alert small py-2 px-3 mb-0 text-start"
                                    :class="feedbackClass"
                                    role="alert"
                                    aria-live="polite"
                                >
                                    <div x-text="feedbackMessage"></div>
                                </div>
                            </div>
                        @else
                            <div class="d-flex flex-wrap justify-content-end justify-content-md-start gap-2">
                                <a
                                    href="{{ route('customer.orders.show', $order) }}"
                                    class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3"
                                    style="min-height: 44px;"
                                >
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                    <span>Detail Pesanan</span>
                                </a>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>

        <x-pagination :paginator="$orders" />
    @endif
</div>
@endsection

@if(! empty($snapPaymentUi['snap_js_url']) && ! empty($snapPaymentUi['client_key']))
@push('scripts')
<script
    src="{{ $snapPaymentUi['snap_js_url'] }}"
    data-client-key="{{ $snapPaymentUi['client_key'] }}"
></script>
@endpush
@endif
