@extends('layouts.admin')

@section('title', 'Pengaturan Toko & Integrasi')

@section('content')
@php
    $canManageStore = $admin->hasPermission('settings.manage');
    $canManageIntegrations = $admin->hasPermission('settings.integrations');
    $midtransWebhookUrl = route('webhooks.midtrans');
    $settingsConfig = [
        'initialTab' => $activeTab ?? 'toko',
        'midtransCheckUrl' => $canManageIntegrations ? route('admin.settings.midtrans.check') : '',
        'rajaongkirCheckUrl' => $canManageIntegrations ? route('admin.settings.rajaongkir.check') : '',
    ];
@endphp

<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Pengaturan Toko'],
]" />

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold text-mlm-primary mb-1">Pengaturan Toko</h1>
        <p class="small text-mlm-muted mb-0">Kelola profil toko, aturan operasional checkout &amp; pengiriman, serta konfigurasi &amp; uji koneksi Midtrans dan RajaOngkir.</p>
    </div>
</div>

<div x-data="settingsTabsPanel(@js($settingsConfig))">
    {{-- Navigasi Tab --}}
    <div class="mlm-card p-2 mb-4">
        <ul class="nav nav-pills flex-column flex-sm-row gap-2" role="tablist">
            <li class="nav-item flex-sm-fill" role="presentation">
                <button
                    type="button"
                    role="tab"
                    class="nav-link w-100 d-flex align-items-center justify-content-center gap-2 fw-semibold py-2 px-3"
                    style="min-height: 46px;"
                    :class="activeTab === 'toko' ? 'active bg-mlm-primary text-white' : 'text-mlm-primary bg-light-subtle'"
                    :aria-selected="activeTab === 'toko'"
                    @click="switchTab('toko')"
                >
                    <i class="bi bi-shop" aria-hidden="true"></i>
                    <span>Pengaturan Toko</span>
                    @if(! $canManageStore)
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Baca Saja</span>
                    @endif
                </button>
            </li>

            <li class="nav-item flex-sm-fill" role="presentation">
                <button
                    type="button"
                    role="tab"
                    class="nav-link w-100 d-flex align-items-center justify-content-center gap-2 fw-semibold py-2 px-3"
                    style="min-height: 46px;"
                    :class="activeTab === 'midtrans' ? 'active bg-mlm-primary text-white' : 'text-mlm-primary bg-light-subtle'"
                    :aria-selected="activeTab === 'midtrans'"
                    @click="switchTab('midtrans')"
                >
                    <i class="bi bi-credit-card-2-front" aria-hidden="true"></i>
                    <span>Integrasi Midtrans</span>
                    @if($integrationStatus['midtrans']['effective_is_active'])
                        <span class="badge bg-success-subtle text-success">
                            {{ $integrationStatus['midtrans']['effective_is_production'] ? 'Production' : 'Sandbox' }}
                        </span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Nonaktif</span>
                    @endif
                </button>
            </li>

            <li class="nav-item flex-sm-fill" role="presentation">
                <button
                    type="button"
                    role="tab"
                    class="nav-link w-100 d-flex align-items-center justify-content-center gap-2 fw-semibold py-2 px-3"
                    style="min-height: 46px;"
                    :class="activeTab === 'rajaongkir' ? 'active bg-mlm-primary text-white' : 'text-mlm-primary bg-light-subtle'"
                    :aria-selected="activeTab === 'rajaongkir'"
                    @click="switchTab('rajaongkir')"
                >
                    <i class="bi bi-truck" aria-hidden="true"></i>
                    <span>Integrasi RajaOngkir</span>
                    @if($integrationStatus['rajaongkir']['effective_is_active'])
                        <span class="badge bg-success-subtle text-success">Aktif</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Nonaktif</span>
                    @endif
                </button>
            </li>
        </ul>
    </div>

    {{-- TAB 1: PENGATURAN TOKO --}}
    <div x-show="activeTab === 'toko'" x-cloak role="tabpanel">
        <section class="mlm-card p-3 p-md-4">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 pb-3 mb-4 border-bottom">
                <div>
                    <h2 class="h5 fw-bold text-mlm-primary mb-1">Profil Toko &amp; Operasional</h2>
                    <p class="small text-mlm-muted mb-0">Atur identitas toko, alamat pengiriman asal, aturan berat/order, dan perilaku checkout.</p>
                </div>
                @if(! $canManageStore)
                    <span class="badge bg-secondary-subtle text-secondary-emphasis align-self-start">Hanya Baca</span>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.settings.store.update') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_tab" value="toko">

                <fieldset @disabled(! $canManageStore)>
                    {{-- Bagian 1: Identitas & Kontak --}}
                    <div class="mb-4">
                        <h3 class="h6 fw-bold text-mlm-primary mb-3">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i> Identitas &amp; Kontak Toko
                        </h3>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <x-form.input name="store_name" label="Nama Toko" :value="$storeForm['store_name']" required />
                            </div>
                            <div class="col-12 col-md-6">
                                <x-form.input name="store_tagline" label="Tagline Toko" :value="$storeForm['store_tagline']" />
                            </div>
                            <div class="col-12">
                                <x-form.textarea name="store_description" label="Deskripsi Toko" :value="$storeForm['store_description']" rows="3" />
                            </div>
                            <div class="col-12 col-md-4">
                                <x-form.input name="store_phone" label="Telepon Toko" :value="$storeForm['store_phone']" />
                            </div>
                            <div class="col-12 col-md-4">
                                <x-form.input name="store_whatsapp" label="Nomor WhatsApp" :value="$storeForm['store_whatsapp']" />
                            </div>
                            <div class="col-12 col-md-4">
                                <x-form.input name="store_email" label="Email Toko" type="email" :value="$storeForm['store_email']" />
                            </div>
                            <div class="col-12 col-md-6">
                                <x-form.input name="store_hours" label="Jam Operasional" :value="$storeForm['store_hours']" />
                            </div>
                            <div class="col-12 col-md-6">
                                <x-form.select
                                    name="store_status"
                                    label="Status Operasional Toko"
                                    :options="['open' => 'Buka (Menerima Pesanan)', 'closed' => 'Tutup Sementara']"
                                    :selected="$storeForm['store_status']"
                                    :placeholder="null"
                                    required
                                />
                            </div>
                            <div class="col-12">
                                <x-form.textarea name="store_address" label="Alamat Toko" :value="$storeForm['store_address']" rows="2" />
                            </div>
                        </div>
                    </div>

                    {{-- Bagian 2: Gudang, Berat & Aturan Order --}}
                    <div class="pt-4 mb-4 border-top">
                        <h3 class="h6 fw-bold text-mlm-primary mb-3">
                            <i class="bi bi-box-seam me-1" aria-hidden="true"></i> Gudang &amp; Aturan Transaksi
                        </h3>
                        <div class="row g-3">
                            <div class="col-12 col-md-5">
                                <x-form.input name="origin_city_name" label="Kota Asal Pengiriman" :value="$storeForm['origin_city_name']" />
                            </div>
                            <div class="col-12 col-md-7">
                                <x-form.textarea name="warehouse_address" label="Alamat Gudang Pengiriman" :value="$storeForm['warehouse_address']" rows="2" />
                            </div>

                            <div class="col-6 col-md-3">
                                <x-form.input name="min_weight_grams" label="Min. Berat (gram)" type="number" min="1" :value="$storeForm['min_weight_grams']" required />
                            </div>
                            <div class="col-6 col-md-3">
                                <x-form.input name="weight_rounding_grams" label="Pembulatan (gram)" type="number" min="1" :value="$storeForm['weight_rounding_grams']" required />
                            </div>
                            <div class="col-6 col-md-3">
                                <x-form.input name="min_order_amount" label="Min. Order (Rp)" type="number" min="0" :value="(int) $storeForm['min_order_amount']" required />
                            </div>
                            <div class="col-6 col-md-3">
                                <x-form.input name="tax_percentage" label="Pajak (%)" type="number" step="0.01" min="0" max="100" :value="$storeForm['tax_percentage']" required />
                            </div>

                            <div class="col-12 col-md-4">
                                <x-form.input name="payment_expiry_minutes" label="Batas Waktu Pembayaran (Menit)" type="number" min="15" max="10080" :value="$storeForm['payment_expiry_minutes']" required />
                            </div>
                        </div>
                    </div>

                    {{-- Bagian 3: Kontrol Checkout & Sistem --}}
                    <div class="pt-4 border-top">
                        <h3 class="h6 fw-bold text-mlm-primary mb-3">
                            <i class="bi bi-toggles me-1" aria-hidden="true"></i> Kontrol Checkout &amp; Otomasi
                        </h3>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="guestCheckout" name="guest_checkout_enabled" value="1" @checked(old('guest_checkout_enabled', $storeForm['guest_checkout_enabled']))>
                                        <label class="form-check-label fw-semibold" for="guestCheckout">Izinkan Guest Checkout</label>
                                    </div>
                                    <div class="small text-mlm-muted">Pembeli dapat berbelanja tanpa mendaftar akun terlebih dahulu.</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="customerReg" name="customer_registration_enabled" value="1" @checked(old('customer_registration_enabled', $storeForm['customer_registration_enabled']))>
                                        <label class="form-check-label fw-semibold" for="customerReg">Aktifkan Registrasi Pelanggan</label>
                                    </div>
                                    <div class="small text-mlm-muted">Membuka halaman pendaftaran akun pelanggan baru di storefront.</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="stockRes" name="stock_reservation_enabled" value="1" @checked(old('stock_reservation_enabled', $storeForm['stock_reservation_enabled']))>
                                        <label class="form-check-label fw-semibold" for="stockRes">Aktifkan Reservasi Stok Checkout</label>
                                    </div>
                                    <div class="small text-mlm-muted">Mengunci stok produk sementara hingga pembayaran selesai atau kedaluwarsa.</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="autoCancel" name="auto_cancel_expired" value="1" @checked(old('auto_cancel_expired', $storeForm['auto_cancel_expired']))>
                                        <label class="form-check-label fw-semibold" for="autoCancel">Batal Otomatis Saat Kedaluwarsa</label>
                                    </div>
                                    <div class="small text-mlm-muted">Melepas stok reservasi dan membatalkan pesanan yang melewati batas waktu bayar.</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="maintenanceMode" name="maintenance_mode" value="1" @checked(old('maintenance_mode', $storeForm['maintenance_mode']))>
                                        <label class="form-check-label fw-semibold text-danger" for="maintenanceMode">Mode Pemeliharaan (Maintenance)</label>
                                    </div>
                                    <div class="small text-mlm-muted">Menonaktifkan sementara akses belanja storefront saat pemeliharaan berlangsung.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                @if($canManageStore)
                    <div class="pt-3 mt-4 border-top d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary fw-semibold px-4" style="min-height: 44px;">
                            <i class="bi bi-save me-1" aria-hidden="true"></i> Simpan Pengaturan Toko
                        </button>
                    </div>
                @endif
            </form>
        </section>
    </div>

    {{-- TAB 2: INTEGRASI MIDTRANS --}}
    <div x-show="activeTab === 'midtrans'" x-cloak role="tabpanel">
        <div class="d-flex flex-column gap-4">
            {{-- Panel Status & Uji Koneksi Midtrans --}}
            <section class="mlm-card p-3 p-md-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-3 border-bottom">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <h2 class="h5 fw-bold text-mlm-primary mb-0">Midtrans Payment Gateway</h2>
                            @if($integrationStatus['midtrans']['effective_is_active'])
                                <span class="badge bg-success-subtle text-success">
                                    Aktif ({{ $integrationStatus['midtrans']['effective_is_production'] ? 'Production' : 'Sandbox' }})
                                </span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Nonaktif</span>
                            @endif
                        </div>
                        <p class="small text-mlm-muted mb-0">Uji koneksi server-side ke Midtrans API tanpa membuat transaksi dummy.</p>
                    </div>

                    @if($canManageIntegrations)
                        <button
                            type="button"
                            class="btn btn-outline-primary fw-semibold d-inline-flex align-items-center gap-2 align-self-start align-self-md-auto"
                            style="min-height: 44px;"
                            :disabled="checks.midtrans.loading"
                            @click="checkProvider('midtrans')"
                        >
                            <span x-show="!checks.midtrans.loading"><i class="bi bi-plug" aria-hidden="true"></i></span>
                            <span x-show="checks.midtrans.loading" x-cloak class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span x-text="checks.midtrans.loading ? 'Memeriksa Koneksi...' : 'Cek Integrasi Midtrans'"></span>
                        </button>
                    @endif
                </div>

                <div class="row g-3 small mb-3">
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-mlm-muted mb-1">Status Server Key</div>
                            @if($integrationStatus['midtrans']['has_db_server_key'])
                                <span class="badge bg-success-subtle text-success">Tersimpan di DB (Terenkripsi)</span>
                            @elseif($integrationStatus['midtrans']['has_env_server_key'])
                                <span class="badge bg-info-subtle text-info-emphasis">Fallback .env Tersedia</span>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis">Belum Diisi</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-mlm-muted mb-1">Status Client Key</div>
                            @if($integrationStatus['midtrans']['has_db_client_key'])
                                <span class="badge bg-success-subtle text-success">Override DB Aktif</span>
                            @elseif($integrationStatus['midtrans']['has_env_client_key'])
                                <span class="badge bg-info-subtle text-info-emphasis">Fallback .env Tersedia</span>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis">Belum Diisi</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-mlm-muted mb-1">Webhook Notification URL</div>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace small" value="{{ $midtransWebhookUrl }}" readonly aria-label="Midtrans Webhook URL">
                                <button type="button" class="btn btn-outline-secondary" @click="copyText(@js($midtransWebhookUrl), 'Webhook URL Midtrans')" aria-label="Salin Webhook URL">
                                    <i class="bi bi-clipboard" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Hasil Uji Integrasi Midtrans --}}
                <div x-show="checks.midtrans.result !== null" x-cloak class="mt-3">
                    <div
                        class="alert mb-0"
                        :class="checks.midtrans.result?.success ? 'alert-success' : 'alert-danger'"
                        role="status"
                    >
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-1">
                            <div class="fw-bold d-flex align-items-center gap-2">
                                <i class="bi" :class="checks.midtrans.result?.success ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'" aria-hidden="true"></i>
                                <span x-text="checks.midtrans.result?.message || ''"></span>
                            </div>
                            <span class="small text-nowrap" x-show="checks.midtrans.result?.details?.checked_at">
                                Dicek: <span x-text="checks.midtrans.result?.details?.checked_at"></span>
                            </span>
                        </div>
                        <div class="small d-flex flex-wrap gap-3 mt-2">
                            <span x-show="checks.midtrans.result?.details?.environment">
                                Mode: <strong x-text="checks.midtrans.result?.details?.environment"></strong>
                            </span>
                            <span x-show="checks.midtrans.result?.details?.http_status">
                                HTTP Status: <strong x-text="checks.midtrans.result?.details?.http_status"></strong>
                            </span>
                            <span x-show="checks.midtrans.result?.details?.latency_ms !== undefined">
                                Latensi: <strong x-text="checks.midtrans.result?.details?.latency_ms + ' ms'"></strong>
                            </span>
                        </div>
                        <template x-if="(checks.midtrans.result?.details?.warnings || []).length > 0">
                            <ul class="small mb-0 mt-2 ps-3">
                                <template x-for="(warn, idx) in checks.midtrans.result.details.warnings" :key="idx">
                                    <li x-text="warn"></li>
                                </template>
                            </ul>
                        </template>
                    </div>
                </div>
            </section>

            {{-- Form Pengaturan Midtrans --}}
            <section class="mlm-card p-3 p-md-4">
                <h3 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Konfigurasi Kredensial Midtrans</h3>

                @if(! $canManageIntegrations)
                    <x-alert type="warning">
                        <div class="small">
                            Anda tidak memiliki izin <code>settings.integrations</code> untuk mengubah kredensial atau konfigurasi Midtrans.
                        </div>
                    </x-alert>
                @else
                    <x-alert type="info" class="mb-4">
                        <div class="small">
                            <strong>Prioritas Konfigurasi Database vs <code>.env</code>:</strong>
                            Nilai kredensial yang disimpan di database (terenkripsi AES-256) akan menimpa <code>.env</code>. <strong>Server Key</strong> tidak pernah ditampilkan kembali di layar; biarkan kolom Server Key kosong agar kunci yang sudah tersimpan tidak tertimpa. Simpan perubahan terlebih dahulu sebelum menjalankan <strong>Cek Integrasi Midtrans</strong>.
                        </div>
                    </x-alert>

                    <form method="POST" action="{{ route('admin.settings.midtrans.update') }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_tab" value="midtrans">

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="midtransActive" name="midtrans_is_active" value="1" @checked(old('midtrans_is_active', $integrationStatus['midtrans']['effective_is_active']))>
                                        <label class="form-check-label fw-semibold" for="midtransActive">Aktifkan Midtrans</label>
                                    </div>
                                    <div class="small text-mlm-muted">Mengaktifkan tombol pembayaran Midtrans Snap pada halaman checkout &amp; detail pesanan.</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input" type="checkbox" role="switch" id="midtransProd" name="midtrans_is_production" value="1" @checked(old('midtrans_is_production', $integrationStatus['midtrans']['effective_is_production']))>
                                        <label class="form-check-label fw-semibold" for="midtransProd">Gunakan Mode Production</label>
                                    </div>
                                    <div class="small text-mlm-muted">Matikan untuk pengujian Sandbox. Aktifkan hanya saat menggunakan kredensial Live Production.</div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <x-form.input
                                    name="midtrans_merchant_id"
                                    label="Merchant ID (Opsional / Override DB)"
                                    :value="$integrationStatus['midtrans']['db_merchant_id']"
                                    placeholder="Kosongkan untuk menggunakan nilai .env"
                                />
                            </div>
                            <div class="col-12 col-md-6">
                                <x-form.input
                                    name="midtrans_client_key"
                                    label="Client Key (Opsional / Override DB)"
                                    :value="$integrationStatus['midtrans']['db_client_key']"
                                    placeholder="Kosongkan untuk menggunakan nilai .env"
                                />
                            </div>
                            <div class="col-12">
                                <label for="midtrans_server_key" class="form-label fw-medium">Server Key Baru (Opsional / Terenkripsi AES-256)</label>
                                <div class="input-group">
                                    <input
                                        :type="showMidtransKey ? 'text' : 'password'"
                                        id="midtrans_server_key"
                                        name="midtrans_server_key"
                                        autocomplete="new-password"
                                        class="form-control @error('midtrans_server_key') is-invalid @enderror"
                                        style="min-height: 44px;"
                                        placeholder="Isi hanya jika ingin mengganti / menimpa Server Key"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        @click="showMidtransKey = !showMidtransKey"
                                        aria-label="Tampilkan atau sembunyikan input Server Key"
                                    >
                                        <i class="bi" :class="showMidtransKey ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="form-text small">Biarkan kosong untuk mempertahankan Server Key yang sudah tersimpan.</div>
                                @error('midtrans_server_key')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="clearMidtransDb" name="clear_midtrans_db_credentials" value="1">
                            <label class="form-check-label small text-muted" for="clearMidtransDb">Hapus override kredensial Midtrans di DB (kembali gunakan <code>.env</code>)</label>
                        </div>

                        <div class="pt-3 mt-4 border-top d-flex flex-wrap justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary fw-semibold px-4" style="min-height: 44px;">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Simpan Pengaturan Midtrans
                            </button>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </div>

    {{-- TAB 3: INTEGRASI RAJAONGKIR --}}
    <div x-show="activeTab === 'rajaongkir'" x-cloak role="tabpanel">
        <div class="d-flex flex-column gap-4">
            {{-- Panel Status & Uji Koneksi RajaOngkir --}}
            <section class="mlm-card p-3 p-md-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-3 border-bottom">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <h2 class="h5 fw-bold text-mlm-primary mb-0">RajaOngkir / Komerce Shipping</h2>
                            @if($integrationStatus['rajaongkir']['effective_is_active'])
                                <span class="badge bg-success-subtle text-success">Aktif</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Nonaktif (Tarif Cadangan)</span>
                            @endif
                        </div>
                        <p class="small text-mlm-muted mb-0">Uji koneksi endpoint pencarian wilayah dan validitas API Key RajaOngkir / Komerce.</p>
                    </div>

                    @if($canManageIntegrations)
                        <button
                            type="button"
                            class="btn btn-outline-primary fw-semibold d-inline-flex align-items-center gap-2 align-self-start align-self-md-auto"
                            style="min-height: 44px;"
                            :disabled="checks.rajaongkir.loading"
                            @click="checkProvider('rajaongkir')"
                        >
                            <span x-show="!checks.rajaongkir.loading"><i class="bi bi-plug" aria-hidden="true"></i></span>
                            <span x-show="checks.rajaongkir.loading" x-cloak class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span x-text="checks.rajaongkir.loading ? 'Memeriksa Koneksi...' : 'Cek Integrasi RajaOngkir'"></span>
                        </button>
                    @endif
                </div>

                <div class="row g-3 small mb-3">
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-mlm-muted mb-1">Status API Key</div>
                            @if($integrationStatus['rajaongkir']['has_db_api_key'])
                                <span class="badge bg-success-subtle text-success">Tersimpan di DB (Terenkripsi)</span>
                            @elseif($integrationStatus['rajaongkir']['has_env_api_key'])
                                <span class="badge bg-info-subtle text-info-emphasis">Fallback .env Tersedia</span>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis">Belum Diisi</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-12 col-md-5">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-mlm-muted mb-1">Base URL Aktif</div>
                            <div class="font-monospace text-break text-mlm-primary">
                                {{ $integrationStatus['rajaongkir']['effective_base_url'] ?: '-' }}
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="text-mlm-muted mb-1">Origin ID Aktif</div>
                            <div class="font-monospace fw-semibold text-mlm-primary">
                                {{ $integrationStatus['rajaongkir']['effective_origin_id'] ?: 'Belum diisi' }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Hasil Uji Integrasi RajaOngkir --}}
                <div x-show="checks.rajaongkir.result !== null" x-cloak class="mt-3">
                    <div
                        class="alert mb-0"
                        :class="checks.rajaongkir.result?.success ? 'alert-success' : 'alert-danger'"
                        role="status"
                    >
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-1">
                            <div class="fw-bold d-flex align-items-center gap-2">
                                <i class="bi" :class="checks.rajaongkir.result?.success ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'" aria-hidden="true"></i>
                                <span x-text="checks.rajaongkir.result?.message || ''"></span>
                            </div>
                            <span class="small text-nowrap" x-show="checks.rajaongkir.result?.details?.checked_at">
                                Dicek: <span x-text="checks.rajaongkir.result?.details?.checked_at"></span>
                            </span>
                        </div>
                        <div class="small d-flex flex-wrap gap-3 mt-2">
                            <span x-show="checks.rajaongkir.result?.details?.provider">
                                Provider: <strong x-text="checks.rajaongkir.result?.details?.provider"></strong>
                            </span>
                            <span x-show="checks.rajaongkir.result?.details?.http_status">
                                HTTP Status: <strong x-text="checks.rajaongkir.result?.details?.http_status"></strong>
                            </span>
                            <span x-show="checks.rajaongkir.result?.details?.latency_ms !== undefined">
                                Latensi: <strong x-text="checks.rajaongkir.result?.details?.latency_ms + ' ms'"></strong>
                            </span>
                        </div>
                        <template x-if="(checks.rajaongkir.result?.details?.warnings || []).length > 0">
                            <ul class="small mb-0 mt-2 ps-3">
                                <template x-for="(warn, idx) in checks.rajaongkir.result.details.warnings" :key="idx">
                                    <li x-text="warn"></li>
                                </template>
                            </ul>
                        </template>
                    </div>
                </div>
            </section>

            {{-- Form Pengaturan RajaOngkir --}}
            <section class="mlm-card p-3 p-md-4">
                <h3 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Konfigurasi Kredensial RajaOngkir</h3>

                @if(! $canManageIntegrations)
                    <x-alert type="warning">
                        <div class="small">
                            Anda tidak memiliki izin <code>settings.integrations</code> untuk mengubah kredensial atau konfigurasi RajaOngkir.
                        </div>
                    </x-alert>
                @else
                    <x-alert type="info" class="mb-4">
                        <div class="small">
                            <strong>Prioritas Konfigurasi Database vs <code>.env</code>:</strong>
                            Nilai kredensial yang disimpan di database (terenkripsi AES-256) akan menimpa <code>.env</code>. <strong>API Key</strong> tidak pernah ditampilkan kembali di layar; biarkan kolom API Key kosong agar kunci yang sudah tersimpan tidak tertimpa. Simpan perubahan terlebih dahulu sebelum menjalankan <strong>Cek Integrasi RajaOngkir</strong>.
                        </div>
                    </x-alert>

                    <form method="POST" action="{{ route('admin.settings.rajaongkir.update') }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_tab" value="rajaongkir">

                        <div class="border rounded-3 p-3 mb-4">
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="rajaOngkirActive" name="rajaongkir_is_active" value="1" @checked(old('rajaongkir_is_active', $integrationStatus['rajaongkir']['db_is_active']))>
                                <label class="form-check-label fw-semibold" for="rajaOngkirActive">Aktifkan Cek Ongkir Live RajaOngkir</label>
                            </div>
                            <div class="small text-mlm-muted">Jika nonaktif, checkout otomatis menggunakan tabel tarif pengiriman cadangan lokal.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <x-form.input
                                    name="rajaongkir_base_url"
                                    label="Base URL API (Opsional / Override DB)"
                                    :value="$integrationStatus['rajaongkir']['db_base_url']"
                                    placeholder="{{ config('services.rajaongkir.base_url') }}"
                                />
                            </div>
                            <div class="col-12 col-md-7">
                                <x-form.input
                                    name="rajaongkir_origin_id"
                                    label="Origin ID Pengiriman (Opsional / Override DB)"
                                    :value="$integrationStatus['rajaongkir']['db_origin_id']"
                                    placeholder="Kosongkan untuk pakai .env"
                                />
                            </div>
                            <div class="col-12 col-md-5">
                                <x-form.input
                                    name="rajaongkir_timeout"
                                    label="Timeout API (Detik)"
                                    type="number"
                                    min="2"
                                    max="30"
                                    :value="$integrationStatus['rajaongkir']['db_timeout']"
                                    required
                                />
                            </div>
                            <div class="col-12">
                                <label for="rajaongkir_api_key" class="form-label fw-medium">API Key Baru (Opsional / Terenkripsi AES-256)</label>
                                <div class="input-group">
                                    <input
                                        :type="showRajaOngkirKey ? 'text' : 'password'"
                                        id="rajaongkir_api_key"
                                        name="rajaongkir_api_key"
                                        autocomplete="new-password"
                                        class="form-control @error('rajaongkir_api_key') is-invalid @enderror"
                                        style="min-height: 44px;"
                                        placeholder="Isi hanya jika ingin mengganti / menimpa API Key"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        @click="showRajaOngkirKey = !showRajaOngkirKey"
                                        aria-label="Tampilkan atau sembunyikan input API Key"
                                    >
                                        <i class="bi" :class="showRajaOngkirKey ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="form-text small">Biarkan kosong untuk mempertahankan API Key yang sudah tersimpan.</div>
                                @error('rajaongkir_api_key')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="clearRajaOngkirDb" name="clear_rajaongkir_db_credentials" value="1">
                            <label class="form-check-label small text-muted" for="clearRajaOngkirDb">Hapus override kredensial RajaOngkir di DB (kembali gunakan <code>.env</code>)</label>
                        </div>

                        <div class="pt-3 mt-4 border-top d-flex flex-wrap justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary fw-semibold px-4" style="min-height: 44px;">
                                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i> Simpan Pengaturan RajaOngkir
                            </button>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
