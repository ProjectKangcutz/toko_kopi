@extends('layouts.customer')

@section('title', 'Detail Pesanan ' . $order->order_number)
@section('page_title', 'Detail Pesanan')

@section('content')
<x-breadcrumb :items="$breadcrumbs" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="small text-mlm-muted">Nomor Pesanan</div>
        <h2 class="h3 fw-bold text-mlm-primary mb-1">{{ $order->order_number }}</h2>
        <div class="small text-mlm-muted">
            Dibuat pada {{ $order->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <x-status-badge :status="$order->order_status" />
        <x-status-badge :status="$order->payment_status" />
        <x-status-badge :status="$order->shipment_status" />
    </div>
</div>

@if($order->order_status === 'cancelled')
    <x-alert type="warning" class="mb-4">
        <div class="small">
            <div class="fw-bold mb-1">
                <i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i>
                Pesanan Dibatalkan
            </div>
            <p class="mb-0">
                Dibatalkan pada {{ $order->cancelled_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') ?? '-' }} WIB.
                @if(! empty($order->cancellation_reason))
                    Alasan: {{ $order->cancellation_reason }}
                @endif
            </p>
        </div>
    </x-alert>
@elseif($order->order_status === 'completed')
    <x-alert type="success" class="mb-4">
        <div class="small">
            <div class="fw-bold mb-1">
                <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
                Pesanan Selesai
            </div>
            <p class="mb-0">
                Pesanan telah selesai pada {{ $order->completed_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') ?? '-' }} WIB. Terima kasih telah berbelanja!
            </p>
        </div>
    </x-alert>
@endif

@if($order->payment_status !== 'paid' && in_array($order->order_status, ['pending', 'awaiting_payment'], true))
    @if(! empty($paymentUi['is_expired']))
        <x-alert type="warning" class="mb-4">
            <div class="small">
                <div class="fw-bold mb-1">
                    <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                    Batas Waktu Pembayaran Berakhir
                </div>
                <p class="mb-0">
                    Batas waktu pembayaran ({{ $order->payment_due_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB) telah terlewati sehingga pembayaran baru tidak dapat dimulai.
                </p>
            </div>
        </x-alert>
    @elseif(! empty($paymentUi['can_pay']))
        <x-alert type="info" class="mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div class="small">
                    <div class="fw-bold mb-1">
                        <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                        Pesanan Menunggu Pembayaran
                    </div>
                    <p class="mb-0">
                        Selesaikan pembayaran online sebelum
                        <strong>{{ $order->payment_due_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</strong>
                        agar reservasi stok produk Anda tetap aktif.
                    </p>
                </div>
                <span class="badge bg-mlm-secondary text-mlm-primary border border-success-subtle px-3 py-2 flex-shrink-0">
                    Siap Dibayar via Midtrans
                </span>
            </div>
        </x-alert>
    @else
        <x-alert type="info" class="mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div class="small">
                    <div class="fw-bold mb-1">
                        <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
                        Pesanan Berhasil Dibuat &amp; Stok Telah Direservasi
                    </div>
                    <p class="mb-0">
                        Selesaikan proses pembayaran sebelum
                        <strong>{{ $order->payment_due_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</strong>.
                        Kanal pembayaran online sedang tidak aktif saat ini.
                    </p>
                </div>
                <span class="badge bg-mlm-secondary text-mlm-primary border border-success-subtle px-3 py-2 flex-shrink-0">
                    Pembayaran Belum Aktif
                </span>
            </div>
        </x-alert>
    @endif
@endif

<div class="row g-4 align-items-start">
    <div class="col-12 col-lg-8 d-flex flex-column gap-4">
        {{-- Daftar Item Snapshot Pesanan --}}
        <section class="mlm-card p-3 p-md-4" aria-labelledby="order-items-heading">
            <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                <h2 id="order-items-heading" class="h5 fw-bold text-mlm-primary mb-0">Produk yang Dipesan</h2>
                <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">
                    {{ number_format((int) $order->items->sum('quantity'), 0, ',', '.') }} item
                </span>
            </div>

            <div class="d-flex flex-column gap-3">
                @foreach($order->items as $item)
                    <article class="d-flex gap-3 pb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                        <div class="mlm-checkout-item-thumb">
                            @if(! empty($item->product_image_snapshot))
                                <img
                                    src="{{ \App\Services\Storefront\HomepageService::mediaUrl($item->product_image_snapshot) }}"
                                    alt="{{ $item->product_name_snapshot }}"
                                    class="w-100 h-100 object-fit-cover"
                                    loading="lazy"
                                >
                            @else
                                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-mlm-primary">
                                    <i class="bi bi-image fs-5 opacity-50" aria-hidden="true"></i>
                                </div>
                            @endif
                        </div>

                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-2">
                                <div class="min-w-0">
                                    <h3 class="h6 fw-bold mb-1">{{ $item->product_name_snapshot }}</h3>

                                    @if(! empty($item->variant_name_snapshot))
                                        <div class="small text-mlm-primary fw-medium">
                                            Varian: {{ $item->variant_name_snapshot }}
                                        </div>
                                    @endif

                                    <div class="small text-mlm-muted mt-1">
                                        SKU: {{ $item->sku_snapshot }}
                                        • {{ number_format((int) $item->weight_grams_snapshot * (int) $item->quantity, 0, ',', '.') }} gram
                                    </div>

                                    <div class="small text-mlm-muted mt-1">
                                        {{ number_format((int) $item->quantity, 0, ',', '.') }} x Rp {{ number_format((float) $item->unit_price_snapshot, 0, ',', '.') }}
                                        @if($item->is_promo_snapshot && $item->regular_price_snapshot !== null)
                                            <span class="text-decoration-line-through ms-1">
                                                Rp {{ number_format((float) $item->regular_price_snapshot, 0, ',', '.') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div class="fw-bold text-mlm-primary text-sm-end flex-shrink-0">
                                    Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Alamat Pengiriman & Informasi Ekspedisi --}}
        <div class="row g-4">
            <div class="col-12 col-md-6">
                <section class="mlm-card p-3 p-md-4 h-100" aria-labelledby="order-address-heading">
                    <h2 id="order-address-heading" class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">
                        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i> Alamat Pengiriman
                    </h2>
                    @if($order->address)
                        <div class="fw-semibold">{{ $order->address->recipient_name }}</div>
                        <div class="small text-mlm-muted mb-2">{{ $order->address->recipient_phone }}</div>
                        <p class="small mb-2">{{ $order->address->address_line }}</p>
                        <div class="small text-mlm-muted">
                            {{ collect([
                                $order->address->subdistrict_name,
                                $order->address->district_name,
                                $order->address->city_name,
                                $order->address->province_name,
                            ])->filter()->implode(', ') }}
                            {{ $order->address->postal_code }}
                        </div>
                    @endif
                </section>
            </div>

            <div class="col-12 col-md-6">
                <section class="mlm-card p-3 p-md-4 h-100" aria-labelledby="order-shipment-heading">
                    <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom gap-2">
                        <h2 id="order-shipment-heading" class="h6 fw-bold text-mlm-primary mb-0">
                            <i class="bi bi-truck me-1" aria-hidden="true"></i> Layanan Pengiriman
                        </h2>
                        @if($order->shipment)
                            <x-status-badge :status="$order->shipment->status" />
                        @endif
                    </div>
                    @if($order->shipment)
                        <div class="fw-semibold">{{ $order->shipment->courier_name }}</div>
                        <div class="small text-mlm-primary fw-medium mb-2">
                            {{ $order->shipment->service_name }} ({{ $order->shipment->service_code }})
                        </div>
                        <div class="rounded-3 bg-mlm-secondary border border-success-subtle px-3 py-2 small mb-2">
                            <span class="text-mlm-muted">Nomor Resi:</span>
                            <span class="fw-bold text-mlm-primary ms-1">
                                {{ $order->shipment->tracking_number ?: 'Menunggu proses pengiriman' }}
                            </span>
                        </div>
                        @if(! empty($order->shipment->etd))
                            <div class="small text-mlm-muted mb-1">
                                Estimasi tiba: {{ $order->shipment->etd }}
                            </div>
                        @endif
                        @if($order->shipment->shipped_at)
                            <div class="small text-mlm-muted mb-1">
                                Dikirim pada: {{ $order->shipment->shipped_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                            </div>
                        @endif
                        @if($order->shipment->delivered_at)
                            <div class="small text-mlm-muted mb-1">
                                Diterima pada: {{ $order->shipment->delivered_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                            </div>
                        @endif
                        <div class="small text-mlm-muted mb-1">
                            Berat aktual produk: {{ number_format((int) $order->total_weight_grams, 0, ',', '.') }} gram
                        </div>
                        <div class="small text-mlm-muted">
                            Berat tagih ekspedisi: {{ number_format((int) $order->shipment->weight_grams, 0, ',', '.') }} gram
                        </div>
                    @endif
                </section>
            </div>
        </div>

        @if(! empty($order->customer_notes))
            <section class="mlm-card p-3 p-md-4" aria-labelledby="order-notes-heading">
                <h2 id="order-notes-heading" class="h6 fw-bold text-mlm-primary mb-2">Catatan Pesanan</h2>
                <p class="small mb-0">{{ $order->customer_notes }}</p>
            </section>
        @endif

        {{-- Riwayat Pengiriman & Tracking --}}
        @if($order->shipment && ($order->shipment->trackingEvents->isNotEmpty() || $order->shipment->histories->isNotEmpty()))
            <section class="mlm-card p-3 p-md-4" aria-labelledby="order-tracking-heading">
                <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom gap-2">
                    <h2 id="order-tracking-heading" class="h6 fw-bold text-mlm-primary mb-0">
                        <i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i> Riwayat Pengiriman
                    </h2>
                    <span class="small text-mlm-muted">Pembaruan dari toko</span>
                </div>

                @if($order->shipment->trackingEvents->isNotEmpty())
                    <ul class="list-group list-group-flush small mb-3">
                        @foreach($order->shipment->trackingEvents as $event)
                            <li class="list-group-item px-0">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-1">
                                    <span class="fw-semibold">{{ $event->description }}</span>
                                    <span class="text-mlm-muted flex-shrink-0">
                                        {{ $event->event_time?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                                    </span>
                                </div>
                                @if(! empty($event->location))
                                    <div class="text-mlm-muted">Lokasi: {{ $event->location }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($order->shipment->histories->isNotEmpty())
                    <ul class="list-group list-group-flush small">
                        @foreach($order->shipment->histories as $history)
                            <li class="list-group-item px-0">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-1">
                                    <span>
                                        Status pengiriman: <strong class="text-mlm-primary">{{ $history->to_status }}</strong>
                                        @if(! empty($history->tracking_number))
                                            • Resi: <span class="fw-semibold">{{ $history->tracking_number }}</span>
                                        @endif
                                    </span>
                                    <span class="text-mlm-muted flex-shrink-0">
                                        {{ $history->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                                    </span>
                                </div>
                                @if(! empty($history->notes))
                                    <div class="text-mlm-muted">{{ $history->notes }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- Riwayat Status Pesanan --}}
        @if($order->statusHistories->isNotEmpty())
            <section class="mlm-card p-3 p-md-4" aria-labelledby="order-history-heading">
                <h2 id="order-history-heading" class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">
                    <i class="bi bi-clock-history me-1" aria-hidden="true"></i> Riwayat Status Pesanan
                </h2>
                <ul class="list-group list-group-flush small">
                    @foreach($order->statusHistories as $history)
                        <li class="list-group-item px-0">
                            <div class="d-flex flex-column flex-sm-row justify-content-between gap-1">
                                <span>
                                    <span class="badge bg-light text-dark border me-1">{{ strtoupper($history->status_type) }}</span>
                                    <strong>{{ $history->from_status ?? 'awal' }} &rarr; {{ $history->to_status }}</strong>
                                </span>
                                <span class="text-mlm-muted flex-shrink-0">
                                    {{ $history->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                                </span>
                            </div>
                            @if(! empty($history->notes))
                                <div class="text-mlm-muted mt-1">{{ $history->notes }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    <div class="col-12 col-lg-4">
        <aside class="mlm-card p-3 p-md-4" aria-label="Ringkasan Pembayaran">
            <h2 class="h5 fw-bold text-mlm-primary pb-3 mb-3 border-bottom">Ringkasan Biaya</h2>

            <dl class="small mb-3">
                <div class="d-flex justify-content-between py-2">
                    <dt class="fw-normal text-mlm-muted">Subtotal Produk</dt>
                    <dd class="mb-0 fw-semibold">Rp {{ number_format((float) $order->subtotal, 0, ',', '.') }}</dd>
                </div>

                @if((float) $order->discount_amount > 0)
                    <div class="d-flex justify-content-between py-2">
                        <dt class="fw-normal text-mlm-muted">Diskon</dt>
                        <dd class="mb-0 fw-semibold text-success">- Rp {{ number_format((float) $order->discount_amount, 0, ',', '.') }}</dd>
                    </div>
                @endif

                @if((float) $order->tax_amount > 0)
                    <div class="d-flex justify-content-between py-2">
                        <dt class="fw-normal text-mlm-muted">Pajak</dt>
                        <dd class="mb-0 fw-semibold">Rp {{ number_format((float) $order->tax_amount, 0, ',', '.') }}</dd>
                    </div>
                @endif

                <div class="d-flex justify-content-between py-2">
                    <dt class="fw-normal text-mlm-muted">Ongkos Kirim</dt>
                    <dd class="mb-0 fw-semibold">Rp {{ number_format((float) $order->shipping_cost, 0, ',', '.') }}</dd>
                </div>

                @if((float) $order->payment_fee > 0)
                    <div class="d-flex justify-content-between py-2">
                        <dt class="fw-normal text-mlm-muted">Biaya Layanan</dt>
                        <dd class="mb-0 fw-semibold">Rp {{ number_format((float) $order->payment_fee, 0, ',', '.') }}</dd>
                    </div>
                @endif
            </dl>

            <div class="border-top pt-3 mb-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold">Total Pembayaran</span>
                <span class="fs-4 fw-bold text-mlm-primary">
                    Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}
                </span>
            </div>

            @if($order->latestPayment)
                <div class="rounded-3 bg-mlm-secondary border border-success-subtle p-3 small mb-3">
                    <div class="fw-semibold text-mlm-primary mb-1">
                        <i class="bi bi-shield-check me-1" aria-hidden="true"></i> Referensi Pembayaran
                    </div>
                    <div class="text-mlm-muted">
                        Kode Transaksi: <span class="fw-semibold text-body">{{ $order->latestPayment->midtrans_order_id }}</span>
                    </div>
                    @if($order->latestPayment->expired_at)
                        <div class="text-mlm-muted mt-1">
                            Berlaku hingga: {{ $order->latestPayment->expired_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                        </div>
                    @endif
                </div>
            @endif

            @if(! empty($paymentUi['can_pay']) && ! empty($paymentUi['js_config']))
                <div x-data="orderPaymentPanel(@js($paymentUi['js_config']))" class="mb-3">
                    <div
                        x-show="feedbackMessage"
                        x-cloak
                        class="alert small mb-3"
                        :class="feedbackClass"
                        role="alert"
                        aria-live="polite"
                    >
                        <div x-text="feedbackMessage"></div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-primary w-100 fw-semibold"
                        style="min-height: 44px;"
                        :disabled="loading || refreshing || checkingStatus"
                        @click="startPayment()"
                    >
                        <span x-show="!loading">
                            <i class="bi bi-credit-card me-1" aria-hidden="true"></i>
                            <span>Bayar Sekarang</span>
                        </span>
                        <span x-show="loading" x-cloak>
                            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            <span>Menyiapkan Pembayaran...</span>
                        </span>
                    </button>

                    @if($order->latestPayment !== null)
                        <button
                            type="button"
                            class="btn btn-outline-primary w-100 fw-semibold mt-2"
                            style="min-height: 44px;"
                            :disabled="loading || refreshing || checkingStatus"
                            @click="checkPaymentStatus()"
                        >
                            <span x-show="!checkingStatus">
                                <span class="d-inline-flex align-items-center justify-content-center gap-1">
                                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                    <span>Sudah Bayar? Cek Status Pembayaran</span>
                                </span>
                            </span>
                            <span x-show="checkingStatus" x-cloak>
                                <span class="d-inline-flex align-items-center justify-content-center gap-1">
                                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                    <span>Memeriksa Status...</span>
                                </span>
                            </span>
                        </button>
                    @endif

                    <p class="small text-mlm-muted mt-2 mb-0">
                        Status pembayaran resmi selalu diverifikasi ulang oleh server setelah Anda menyelesaikan transaksi di jendela Midtrans.
                    </p>
                </div>
            @endif

            @if(! empty($canComplete))
                <form method="POST" action="{{ route('customer.orders.complete', $order) }}" class="mb-3">
                    @csrf
                    <button
                        type="submit"
                        class="btn btn-primary w-100 fw-semibold d-flex align-items-center justify-content-center gap-2"
                        style="min-height: 44px;"
                    >
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <span>Pesanan Diterima &amp; Selesai</span>
                    </button>
                </form>
            @endif

            @if(! empty($canCancel))
                <div x-data="{ confirmCancel: false }" class="mb-3">
                    <button
                        type="button"
                        x-show="!confirmCancel"
                        @click="confirmCancel = true"
                        class="btn btn-outline-danger w-100 fw-semibold"
                        style="min-height: 44px;"
                    >
                        <span class="d-inline-flex align-items-center justify-content-center gap-2">
                            <i class="bi bi-x-circle" aria-hidden="true"></i>
                            <span>Batalkan Pesanan</span>
                        </span>
                    </button>

                    <div x-show="confirmCancel" x-cloak>
                        <form
                            method="POST"
                            action="{{ route('customer.orders.cancel', $order) }}"
                            class="rounded-3 border border-danger-subtle p-3 d-flex flex-column gap-2"
                        >
                            @csrf
                            <label for="customerCancelReason" class="form-label small fw-semibold text-danger mb-0">
                                Alasan Pembatalan (Opsional)
                            </label>
                            <input
                                type="text"
                                id="customerCancelReason"
                                name="reason"
                                maxlength="255"
                                class="form-control"
                                style="min-height: 44px;"
                                placeholder="Contoh: Ingin mengubah alamat/pesanan"
                            >
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-danger flex-grow-1 fw-semibold" style="min-height: 44px;">
                                    Konfirmasi Batal
                                </button>
                                <button type="button" @click="confirmCancel = false" class="btn btn-outline-secondary" style="min-height: 44px;">
                                    Batal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <div class="d-flex flex-column gap-2">
                <a
                    href="{{ route('customer.dashboard') }}"
                    class="btn {{ ! empty($paymentUi['can_pay']) ? 'btn-outline-primary' : 'btn-primary' }} w-100 d-flex align-items-center justify-content-center gap-2"
                    style="min-height: 44px;"
                >
                    <i class="bi bi-house-door" aria-hidden="true"></i>
                    <span>Kembali ke Ringkasan Akun</span>
                </a>
                <a
                    href="{{ route('storefront.catalog') }}"
                    class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center gap-2"
                    style="min-height: 44px;"
                >
                    <i class="bi bi-bag" aria-hidden="true"></i>
                    <span>Lanjut Belanja</span>
                </a>
            </div>
        </aside>
    </div>
</div>
@endsection

@if(! empty($paymentUi['can_pay']) && ! empty($paymentUi['snap_js_url']) && ! empty($paymentUi['client_key']))
@push('scripts')
<script
    src="{{ $paymentUi['snap_js_url'] }}"
    data-client-key="{{ $paymentUi['client_key'] }}"
></script>
@endpush
@endif
