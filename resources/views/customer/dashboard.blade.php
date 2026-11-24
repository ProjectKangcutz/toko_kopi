@extends('layouts.customer')

@section('title', 'Ringkasan Akun')

@section('content')
<x-breadcrumb :items="[['label' => 'Akun Saya']]" />

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Total Pesanan</div>
            <div class="h4 fw-bold text-mlm-primary mb-0">{{ $orderStats['total'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Menunggu Bayar</div>
            <div class="h4 fw-bold text-warning mb-0">{{ $orderStats['awaiting_payment'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Diproses</div>
            <div class="h4 fw-bold mb-0">{{ $orderStats['processing'] }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mlm-card p-3 h-100">
            <div class="small text-mlm-muted">Dikirim</div>
            <div class="h4 fw-bold mb-0">{{ $orderStats['shipped'] }}</div>
        </div>
    </div>
</div>

<div class="mlm-card p-3 p-md-4">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
        <h2 class="h6 fw-bold mb-0">Pesanan Terbaru</h2>
        <a
            href="{{ route('customer.orders.index') }}"
            class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1"
            style="min-height: 44px;"
        >
            <i class="bi bi-bag-check" aria-hidden="true"></i>
            <span>Lihat Semua Pesanan</span>
        </a>
    </div>
    @if($recentOrders->isEmpty())
        <x-empty-state
            icon="bi-bag"
            title="Belum Ada Transaksi"
            message="Anda belum memiliki pesanan. Jelajahi katalog toko untuk mulai berbelanja."
            action-label="Lihat Toko"
            :action-url="route('storefront.home')"
        />
    @else
        <x-table :headers="['No. Order', 'Tanggal', 'Total', 'Pembayaran', 'Status Pesanan', 'Aksi']">
            @foreach($recentOrders as $order)
                @php
                    $paymentState = $orderPaymentStates[$order->id] ?? ['can_pay' => false];
                    $canPay = ! empty($paymentState['can_pay']) && ! empty($paymentState['js_config']);
                @endphp
                <tr>
                    <td data-label="No. Order" class="fw-semibold">{{ $order->order_number }}</td>
                    <td data-label="Tanggal">{{ $order->created_at->format('d M Y') }}</td>
                    <td data-label="Total">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</td>
                    <td data-label="Pembayaran"><x-status-badge :status="$order->payment_status" /></td>
                    <td data-label="Status Pesanan"><x-status-badge :status="$order->order_status" /></td>
                    <td data-label="Aksi">
                        @if($canPay)
                            <div
                                x-data="orderPaymentPanel(@js($paymentState['js_config']))"
                                class="d-flex flex-column align-items-end align-items-md-start gap-2"
                            >
                                <div class="d-flex flex-wrap justify-content-end justify-content-md-start gap-2">
                                    <a
                                        href="{{ route('customer.orders.show', $order) }}"
                                        class="btn btn-outline-primary btn-sm px-3"
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
                                    class="btn btn-outline-primary btn-sm px-3"
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
