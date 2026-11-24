@extends('layouts.admin')

@section('title', 'Kelola Pesanan ' . $order->order_number)

@section('content')
<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Pesanan & Pengiriman', 'url' => route('admin.orders.index')],
    ['label' => $order->order_number],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="small text-mlm-muted">Detail &amp; Fulfillment Pesanan</div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">{{ $order->order_number }}</h1>
        <div class="small text-mlm-muted">
            Dibuat pada {{ $order->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
            @if($order->payment_due_at)
                • Batas bayar: {{ $order->payment_due_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
            @endif
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
            <div class="fw-bold mb-1">Pesanan Dibatalkan</div>
            <div>
                Dibatalkan pada {{ $order->cancelled_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') ?? '-' }} WIB.
                @if(! empty($order->cancellation_reason))
                    Alasan: {{ $order->cancellation_reason }}
                @endif
            </div>
        </div>
    </x-alert>
@endif

<div class="row g-4 align-items-start">
    <div class="col-12 col-lg-8 d-flex flex-column gap-4">
        {{-- Produk --}}
        <section class="mlm-card p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                <h2 class="h5 fw-bold text-mlm-primary mb-0">Item Pesanan</h2>
                <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">
                    {{ number_format((int) $order->items->sum('quantity'), 0, ',', '.') }} item
                </span>
            </div>

            <div class="d-flex flex-column gap-3">
                @foreach($order->items as $item)
                    <div class="d-flex justify-content-between align-items-start gap-3 pb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                        <div>
                            <div class="fw-bold">{{ $item->product_name_snapshot }}</div>
                            @if(! empty($item->variant_name_snapshot))
                                <div class="small text-mlm-primary">Varian: {{ $item->variant_name_snapshot }}</div>
                            @endif
                            <div class="small text-mlm-muted">
                                SKU: {{ $item->sku_snapshot }} • {{ number_format((int) $item->quantity, 0, ',', '.') }} x Rp {{ number_format((float) $item->unit_price_snapshot, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="fw-bold text-mlm-primary flex-shrink-0">
                            Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Pelanggan & Alamat --}}
        <div class="row g-4">
            <div class="col-12 col-md-6">
                <section class="mlm-card p-3 p-md-4 h-100">
                    <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Pelanggan &amp; Alamat Tujuan</h2>
                    <div class="fw-semibold">{{ $order->customer_name_snapshot }}</div>
                    <div class="small text-mlm-muted mb-2">{{ $order->customer_email_snapshot }} • {{ $order->customer_phone_snapshot }}</div>

                    @if($order->address)
                        <div class="border-top pt-2 mt-2 small">
                            <div class="fw-semibold">Penerima: {{ $order->address->recipient_name }} ({{ $order->address->recipient_phone }})</div>
                            <p class="mb-1">{{ $order->address->address_line }}</p>
                            <div class="text-mlm-muted">
                                {{ collect([
                                    $order->address->subdistrict_name,
                                    $order->address->district_name,
                                    $order->address->city_name,
                                    $order->address->province_name,
                                ])->filter()->implode(', ') }} {{ $order->address->postal_code }}
                            </div>
                        </div>
                    @endif

                    @if(! empty($order->customer_notes))
                        <div class="border-top pt-2 mt-2 small">
                            <span class="fw-semibold">Catatan Pelanggan:</span> {{ $order->customer_notes }}
                        </div>
                    @endif
                </section>
            </div>

            <div class="col-12 col-md-6">
                <section class="mlm-card p-3 p-md-4 h-100">
                    <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Informasi Pengiriman</h2>
                    @if($order->shipment)
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="fw-semibold">{{ $order->shipment->courier_name }}</div>
                                <div class="small text-mlm-primary">{{ $order->shipment->service_name }} ({{ $order->shipment->service_code }})</div>
                            </div>
                            <x-status-badge :status="$order->shipment->status" />
                        </div>

                        <dl class="small mb-0 mt-3">
                            <div class="d-flex justify-content-between py-1">
                                <dt class="fw-normal text-mlm-muted">Nomor Resi</dt>
                                <dd class="mb-0 fw-bold text-mlm-primary">{{ $order->shipment->tracking_number ?: 'Belum diinput' }}</dd>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <dt class="fw-normal text-mlm-muted">Berat Tagih</dt>
                                <dd class="mb-0">{{ number_format((int) $order->shipment->weight_grams, 0, ',', '.') }} gram</dd>
                            </div>
                            @if($order->shipment->shipped_at)
                                <div class="d-flex justify-content-between py-1">
                                    <dt class="fw-normal text-mlm-muted">Dikirim Pada</dt>
                                    <dd class="mb-0">{{ $order->shipment->shipped_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</dd>
                                </div>
                            @endif
                            @if($order->shipment->delivered_at)
                                <div class="d-flex justify-content-between py-1">
                                    <dt class="fw-normal text-mlm-muted">Diterima Pada</dt>
                                    <dd class="mb-0">{{ $order->shipment->delivered_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</dd>
                                </div>
                            @endif
                        </dl>
                    @else
                        <p class="small text-mlm-muted mb-0">Data shipment belum tersedia.</p>
                    @endif
                </section>
            </div>
        </div>

        {{-- Riwayat Pengiriman & Tracking Manual --}}
        @if($order->shipment && ($order->shipment->histories->isNotEmpty() || $order->shipment->trackingEvents->isNotEmpty()))
            <section class="mlm-card p-3 p-md-4">
                <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Riwayat Pengiriman &amp; Tracking Manual</h2>

                @if($order->shipment->trackingEvents->isNotEmpty())
                    <h3 class="small fw-bold text-uppercase text-mlm-muted mb-2">Catatan Aktivitas Pengiriman</h3>
                    <ul class="list-group list-group-flush small mb-3">
                        @foreach($order->shipment->trackingEvents as $event)
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold">{{ $event->description }}</span>
                                    <span class="text-mlm-muted flex-shrink-0">{{ $event->event_time?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</span>
                                </div>
                                @if(! empty($event->location))
                                    <div class="text-mlm-muted">Lokasi: {{ $event->location }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($order->shipment->histories->isNotEmpty())
                    <h3 class="small fw-bold text-uppercase text-mlm-muted mb-2">Log Perubahan Status Shipment</h3>
                    <ul class="list-group list-group-flush small">
                        @foreach($order->shipment->histories as $history)
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between gap-2">
                                    <span>
                                        <strong>{{ $history->from_status ?? 'awal' }} &rarr; {{ $history->to_status }}</strong>
                                        @if(! empty($history->tracking_number))
                                            (Resi: {{ $history->tracking_number }})
                                        @endif
                                    </span>
                                    <span class="text-mlm-muted flex-shrink-0">{{ $history->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</span>
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
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Riwayat Status Pesanan</h2>
            @if($order->statusHistories->isEmpty())
                <p class="small text-mlm-muted mb-0">Belum ada riwayat perubahan status.</p>
            @else
                <ul class="list-group list-group-flush small">
                    @foreach($order->statusHistories as $history)
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between gap-2">
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
                            <div class="text-mlm-muted">Aktor: {{ $history->actor_type }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Riwayat Pembayaran Midtrans & Webhook --}}
        @if($admin->hasPermission('payments.view') || $admin->hasPermission('orders.view'))
            <section class="mlm-card p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                    <h2 class="h6 fw-bold text-mlm-primary mb-0">Riwayat Pembayaran Midtrans &amp; Webhook</h2>
                    <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">
                        {{ $order->payments->count() }} sesi pembayaran
                    </span>
                </div>

                @if($order->payments->isEmpty())
                    <p class="small text-mlm-muted mb-0">Belum ada riwayat inisiasi pembayaran Midtrans untuk pesanan ini.</p>
                @else
                    <div class="d-flex flex-column gap-4">
                        @foreach($order->payments as $payment)
                            <div class="border rounded-3 p-3 bg-light-subtle">
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 pb-2 mb-2 border-bottom">
                                    <div>
                                        <div class="small text-mlm-muted">Order ID Midtrans</div>
                                        <div class="fw-bold text-mlm-primary font-monospace small">{{ $payment->midtrans_order_id }}</div>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <x-status-badge :status="$payment->status" />
                                        <span class="fw-semibold small">Rp {{ number_format((float) $payment->gross_amount, 0, ',', '.') }}</span>
                                    </div>
                                </div>

                                <div class="row g-2 small mb-3">
                                    <div class="col-6 col-md-4">
                                        <div class="text-mlm-muted">Gateway / Metode</div>
                                        <div class="fw-semibold">
                                            {{ strtoupper((string) $payment->payment_gateway) }}
                                            @if(! empty($payment->payment_type))
                                                • {{ strtoupper(str_replace('_', ' ', (string) $payment->payment_type)) }}
                                            @endif
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <div class="text-mlm-muted">ID Transaksi Midtrans</div>
                                        <div class="fw-semibold font-monospace text-break">{{ $payment->midtrans_transaction_id ?: '-' }}</div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <div class="text-mlm-muted">Waktu Bayar / Kedaluwarsa</div>
                                        <div class="fw-semibold">
                                            @if($payment->paid_at)
                                                Lunas: {{ $payment->paid_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                                            @elseif($payment->expired_at)
                                                Batas: {{ $payment->expired_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                                            @else
                                                -
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                @if($payment->transactions->isNotEmpty())
                                    <div class="mt-2">
                                        <div class="small fw-bold text-uppercase text-mlm-muted mb-2">Log Transaksi Gateway</div>
                                        <ul class="list-group list-group-flush small">
                                            @foreach($payment->transactions as $tx)
                                                <li class="list-group-item bg-transparent px-0">
                                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                                        <div>
                                                            <span class="badge bg-light text-dark border me-1">{{ strtoupper((string) $tx->event_type) }}</span>
                                                            <strong>{{ strtoupper((string) $tx->status) }}</strong>
                                                            @if(! empty($tx->payment_type))
                                                                <span class="text-mlm-muted">({{ $tx->payment_type }})</span>
                                                            @endif
                                                            <span class="ms-1">• Rp {{ number_format((float) $tx->gross_amount, 0, ',', '.') }}</span>
                                                        </div>
                                                        <span class="text-mlm-muted flex-shrink-0">
                                                            {{ $tx->occurred_at?->timezone('Asia/Jakarta')->format('d M Y, H:i:s') ?? $tx->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i:s') }} WIB
                                                        </span>
                                                    </div>
                                                    <div class="text-mlm-muted mt-1">
                                                        Ref Transaksi: <span class="font-monospace">{{ $tx->transaction_id ?: '-' }}</span>
                                                        • Signature:
                                                        @if($tx->signature_verified)
                                                            <span class="text-success fw-semibold">Terverifikasi</span>
                                                        @else
                                                            <span class="text-warning fw-semibold">Belum/Tidak Terverifikasi</span>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if($payment->webhookLogs->isNotEmpty())
                                    <div class="mt-3 pt-2 border-top">
                                        <div class="small fw-bold text-uppercase text-mlm-muted mb-2">Riwayat Webhook Masuk</div>
                                        <ul class="list-group list-group-flush small">
                                            @foreach($payment->webhookLogs as $webhook)
                                                <li class="list-group-item bg-transparent px-0">
                                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                                        <div>
                                                            <span class="fw-semibold">{{ strtoupper((string) ($webhook->transaction_status ?? '-')) }}</span>
                                                            <span class="text-mlm-muted">(HTTP {{ $webhook->status_code ?? '-' }})</span>
                                                            @if($webhook->signature_valid)
                                                                <span class="badge bg-success-subtle text-success ms-1">Signature Valid</span>
                                                            @else
                                                                <span class="badge bg-danger-subtle text-danger ms-1">Signature Invalid</span>
                                                            @endif
                                                            @if($webhook->processed)
                                                                <span class="badge bg-info-subtle text-info-emphasis ms-1">Diproses</span>
                                                            @else
                                                                <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Diabaikan</span>
                                                            @endif
                                                        </div>
                                                        <span class="text-mlm-muted flex-shrink-0">
                                                            {{ $webhook->received_at?->timezone('Asia/Jakarta')->format('d M Y, H:i:s') }} WIB
                                                        </span>
                                                    </div>
                                                    <div class="text-mlm-muted mt-1">
                                                        Ref Webhook: <span class="font-monospace">{{ $webhook->transaction_id ?: '-' }}</span>
                                                        @if(! empty($webhook->processing_error))
                                                            <div class="text-danger mt-1">Catatan: {{ $webhook->processing_error }}</div>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
    </div>

    {{-- Panel Ringkasan & Aksi Fulfillment Admin --}}
    <div class="col-12 col-lg-4 d-flex flex-column gap-4">
        <aside class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary pb-3 mb-3 border-bottom">Ringkasan Pembayaran</h2>
            <dl class="small mb-3">
                <div class="d-flex justify-content-between py-1">
                    <dt class="fw-normal text-mlm-muted">Subtotal</dt>
                    <dd class="mb-0">Rp {{ number_format((float) $order->subtotal, 0, ',', '.') }}</dd>
                </div>
                <div class="d-flex justify-content-between py-1">
                    <dt class="fw-normal text-mlm-muted">Ongkos Kirim</dt>
                    <dd class="mb-0">Rp {{ number_format((float) $order->shipping_cost, 0, ',', '.') }}</dd>
                </div>
                @if((float) $order->tax_amount > 0)
                    <div class="d-flex justify-content-between py-1">
                        <dt class="fw-normal text-mlm-muted">Pajak</dt>
                        <dd class="mb-0">Rp {{ number_format((float) $order->tax_amount, 0, ',', '.') }}</dd>
                    </div>
                @endif
                <div class="d-flex justify-content-between pt-2 mt-2 border-top fw-bold fs-6">
                    <dt>Total</dt>
                    <dd class="mb-0 text-mlm-primary">Rp {{ number_format((float) $order->grand_total, 0, ',', '.') }}</dd>
                </div>
            </dl>

            @if($order->paid_at)
                <div class="small text-success fw-semibold mb-2">
                    <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
                    Lunas pada {{ $order->paid_at->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                </div>
            @endif

            @if(! empty($order->admin_internal_notes))
                <div class="rounded-3 bg-mlm-secondary p-3 small mt-2">
                    <div class="fw-semibold text-mlm-primary">Catatan Internal Admin:</div>
                    <div>{{ $order->admin_internal_notes }}</div>
                </div>
            @endif
        </aside>

        {{-- Aksi Fulfillment --}}
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary pb-3 mb-3 border-bottom">Aksi Fulfillment Admin</h2>

            @if($order->order_status === 'paid' && $order->payment_status === 'paid' && $admin->hasPermission('orders.update_status'))
                <form method="POST" action="{{ route('admin.orders.processing', $order) }}" class="d-flex flex-column gap-3 mb-3">
                    @csrf
                    <div>
                        <label for="internalNotes" class="form-label small fw-semibold">Catatan Internal (Opsional)</label>
                        <textarea
                            id="internalNotes"
                            name="internal_notes"
                            rows="2"
                            maxlength="500"
                            class="form-control"
                            placeholder="Contoh: Pesanan sedang dikemas di gudang..."
                        >{{ old('internal_notes', $order->admin_internal_notes) }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-box2-heart me-1" aria-hidden="true"></i> Proses Pesanan (Processing)
                    </button>
                </form>
            @endif

            @if($order->order_status === 'processing' && $order->payment_status === 'paid' && $admin->hasPermission('shipments.update'))
                <form method="POST" action="{{ route('admin.orders.shipped', $order) }}" class="d-flex flex-column gap-3 mb-3">
                    @csrf
                    <div>
                        <label for="trackingNumber" class="form-label small fw-semibold">Nomor Resi Pengiriman <span class="text-danger">*</span></label>
                        <input
                            type="text"
                            id="trackingNumber"
                            name="tracking_number"
                            value="{{ old('tracking_number', $order->shipment?->tracking_number) }}"
                            required
                            maxlength="100"
                            class="form-control"
                            style="min-height: 44px;"
                            placeholder="Masukkan nomor resi kurir"
                        >
                    </div>
                    <div>
                        <label for="courierName" class="form-label small fw-semibold">Nama Kurir</label>
                        <input
                            type="text"
                            id="courierName"
                            name="courier_name"
                            value="{{ old('courier_name', $order->shipment?->courier_name) }}"
                            maxlength="100"
                            class="form-control"
                            style="min-height: 44px;"
                        >
                    </div>
                    <div>
                        <label for="serviceName" class="form-label small fw-semibold">Layanan Pengiriman</label>
                        <input
                            type="text"
                            id="serviceName"
                            name="service_name"
                            value="{{ old('service_name', $order->shipment?->service_name) }}"
                            maxlength="120"
                            class="form-control"
                            style="min-height: 44px;"
                        >
                    </div>
                    <div>
                        <label for="shipNotes" class="form-label small fw-semibold">Catatan Pengiriman (Opsional)</label>
                        <input
                            type="text"
                            id="shipNotes"
                            name="notes"
                            value="{{ old('notes') }}"
                            maxlength="255"
                            class="form-control"
                            style="min-height: 44px;"
                            placeholder="Contoh: Diserahkan ke agen JNE"
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-truck me-1" aria-hidden="true"></i> Kirim &amp; Simpan Resi (Shipped)
                    </button>
                </form>
            @endif

            @if($order->order_status === 'shipped' && $admin->hasPermission('shipments.update'))
                <form method="POST" action="{{ route('admin.orders.tracking.store', $order) }}" class="d-flex flex-column gap-2 pb-3 mb-3 border-bottom">
                    @csrf
                    <div class="small fw-semibold text-mlm-primary">Catat Tracking Pengiriman Manual</div>
                    <input
                        type="text"
                        name="location"
                        maxlength="255"
                        class="form-control"
                        style="min-height: 44px;"
                        placeholder="Lokasi / Hub (opsional)"
                    >
                    <input
                        type="text"
                        name="description"
                        required
                        maxlength="500"
                        class="form-control"
                        style="min-height: 44px;"
                        placeholder="Status perjalanan paket (misal: Paket tiba di hub sortir)"
                    >
                    <button type="submit" class="btn btn-outline-primary w-100 fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-geo me-1" aria-hidden="true"></i> Tambah Log Tracking Manual
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.orders.delivered', $order) }}" class="d-flex flex-column gap-3 mb-3">
                    @csrf
                    <div>
                        <label for="deliveredNotes" class="form-label small fw-semibold">Catatan Penerimaan (Opsional)</label>
                        <input
                            type="text"
                            id="deliveredNotes"
                            name="notes"
                            maxlength="255"
                            class="form-control"
                            style="min-height: 44px;"
                            placeholder="Contoh: Diterima oleh penerima langsung"
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-house-check me-1" aria-hidden="true"></i> Tandai Sudah Sampai (Delivered)
                    </button>
                </form>
            @endif

            @if($order->order_status === 'delivered' && $admin->hasPermission('orders.update_status'))
                <form method="POST" action="{{ route('admin.orders.completed', $order) }}" class="d-flex flex-column gap-3 mb-3">
                    @csrf
                    <div>
                        <label for="completedNotes" class="form-label small fw-semibold">Catatan Penyelesaian (Opsional)</label>
                        <input
                            type="text"
                            id="completedNotes"
                            name="notes"
                            maxlength="255"
                            class="form-control"
                            style="min-height: 44px;"
                            placeholder="Catatan selesai..."
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-check2-all me-1" aria-hidden="true"></i> Selesaikan Pesanan (Completed)
                    </button>
                </form>
            @endif

            @if(
                in_array($order->order_status, ['pending', 'awaiting_payment'], true)
                && ! in_array($order->payment_status, ['paid', 'refunded', 'partially_refunded'], true)
                && $order->paid_at === null
                && $admin->hasPermission('orders.cancel')
            )
                <div x-data="{ confirmCancel: false }" class="pt-2 border-top">
                    <button
                        type="button"
                        x-show="!confirmCancel"
                        @click="confirmCancel = true"
                        class="btn btn-outline-danger w-100 fw-semibold"
                        style="min-height: 44px;"
                    >
                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i> Batalkan Pesanan
                    </button>

                    <div x-show="confirmCancel" x-cloak>
                        <form
                            method="POST"
                            action="{{ route('admin.orders.cancel', $order) }}"
                            class="d-flex flex-column gap-2 mt-2"
                        >
                            @csrf
                            <label for="adminCancelReason" class="form-label small fw-semibold text-danger mb-0">Alasan Pembatalan</label>
                            <input
                                type="text"
                                id="adminCancelReason"
                                name="reason"
                                maxlength="255"
                                class="form-control"
                                style="min-height: 44px;"
                                placeholder="Alasan pembatalan oleh admin..."
                            >
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-danger flex-grow-1 fw-semibold" style="min-height: 44px;">
                                    Konfirmasi Batal
                                </button>
                                <button type="button" @click="confirmCancel = false" class="btn btn-outline-secondary" style="min-height: 44px;">
                                    Tutup
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @elseif(in_array($order->order_status, ['pending', 'awaiting_payment'], true))
                <p class="small text-mlm-muted mb-0">Pesanan masih menunggu pembayaran dari pelanggan.</p>
            @elseif(in_array($order->order_status, ['completed', 'cancelled', 'refunded'], true))
                <p class="small text-mlm-muted mb-0">Pesanan berada pada status akhir dan tidak memiliki aksi fulfillment lanjutan.</p>
            @endif

            <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-primary w-100 mt-3 d-inline-flex align-items-center justify-content-center gap-1" style="min-height: 44px;">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Kembali ke Daftar Pesanan
            </a>
        </section>
    </div>
</div>
@endsection
