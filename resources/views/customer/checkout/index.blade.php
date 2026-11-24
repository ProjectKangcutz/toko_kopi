@extends('layouts.storefront')

@section('title', $seo['title'])
@section('meta_description', $seo['description'])
@section('canonical', $seo['canonical'])
@section('robots', $seo['robots'])
@section('og_title', $seo['title'])
@section('og_description', $seo['description'])

@section('content')
<div class="container py-3 py-md-4">
    <x-breadcrumb :items="$breadcrumbs" />

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h2 fw-bold text-mlm-primary mb-1">Checkout</h1>
            <p class="small text-mlm-muted mb-0">
                Pilih alamat tujuan dan layanan pengiriman resmi sebelum menyelesaikan pesanan Anda.
            </p>
        </div>

        <a href="{{ route('storefront.cart') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Kembali ke Keranjang</span>
        </a>
    </div>

    @if(! $canCheckout)
        <x-empty-state
            icon="bi-bag-x"
            title="Keranjang Belanja Kosong atau Belum Siap"
            message="Anda belum memiliki produk aktif di keranjang belanja. Tambahkan produk terlebih dahulu sebelum melanjutkan proses checkout."
            action-label="Jelajahi Katalog Produk"
            :action-url="route('storefront.catalog')"
        />

        @if(! empty($cart['has_unavailable_items']))
            <div class="text-center mt-2">
                <a href="{{ route('storefront.cart') }}" class="btn btn-outline-primary">
                    <i class="bi bi-cart" aria-hidden="true"></i> Periksa Item di Keranjang
                </a>
            </div>
        @endif
    @else
        @if(! empty($cart['has_unavailable_items']) && ! empty($cart['warnings']))
            <x-alert type="warning" class="mb-3">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 small">
                    <div>
                        <div class="fw-semibold mb-1">
                            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                            Sebagian item di keranjang tidak tersedia dan tidak diikutsertakan dalam checkout:
                        </div>
                        <ul class="mb-0 ps-3">
                            @foreach($cart['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <a href="{{ route('storefront.cart') }}" class="btn btn-sm btn-outline-primary flex-shrink-0">
                        Kelola Keranjang
                    </a>
                </div>
            </x-alert>
        @endif

        <div
            x-data="checkoutPage(@js($checkoutConfig))"
            class="row g-4 align-items-start"
        >
            {{-- Kolom Kiri: Alamat, Layanan Pengiriman, dan Daftar Item --}}
            <div class="col-12 col-lg-7 col-xl-8 d-flex flex-column gap-4">
                {{-- 1. Pemilihan Alamat Pengiriman --}}
                <section class="mlm-card p-3 p-md-4" aria-labelledby="checkout-address-heading">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-3 mb-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-bold px-2 py-1">1</span>
                            <h2 id="checkout-address-heading" class="h5 fw-bold mb-0 text-mlm-primary">Alamat Pengiriman</h2>
                        </div>

                        @if(count($addresses) > 0)
                            <div class="d-flex flex-wrap gap-2">
                                <a href="{{ route('customer.addresses.index') }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-geo-alt" aria-hidden="true"></i> Kelola Alamat
                                </a>
                                <a href="{{ route('customer.addresses.create') }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Tambah Alamat
                                </a>
                            </div>
                        @endif
                    </div>

                    @if(count($addresses) === 0)
                        <x-empty-state
                            icon="bi-geo-alt"
                            title="Belum Ada Alamat Pengiriman"
                            message="Tambahkan alamat pengiriman aktif terlebih dahulu agar sistem dapat menghitung ongkos kirim ke lokasi Anda."
                            action-label="Tambah Alamat Pengiriman"
                            :action-url="route('customer.addresses.create')"
                        />
                    @else
                        {{-- Alamat Terpilih Saat Ini --}}
                        <div class="mlm-region-summary mb-3" aria-live="polite">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <span class="small text-uppercase fw-bold text-mlm-primary">Dikirim ke</span>
                                        <span class="fw-bold text-body" x-text="selectedAddress ? selectedAddress.label : @js($selectedAddress?->label ?? '')">
                                            {{ $selectedAddress?->label }}
                                        </span>
                                        <span
                                            x-show="selectedAddress && selectedAddress.is_primary"
                                            @if(! $selectedAddress?->is_primary) x-cloak @endif
                                            class="badge bg-white text-mlm-primary border border-success-subtle"
                                        >
                                            Alamat Utama
                                        </span>
                                    </div>
                                    <div class="fw-semibold small text-body">
                                        <span x-text="selectedAddress ? selectedAddress.recipient_name : @js($selectedAddress?->recipient_name ?? '')">{{ $selectedAddress?->recipient_name }}</span>
                                        <span class="text-mlm-muted fw-normal">•</span>
                                        <span class="text-mlm-muted fw-normal" x-text="selectedAddress ? selectedAddress.recipient_phone : @js($selectedAddress?->recipient_phone ?? '')">{{ $selectedAddress?->recipient_phone }}</span>
                                    </div>
                                    <p class="small text-body mb-1 mt-1" x-text="selectedAddress ? selectedAddress.address_line : @js($selectedAddress?->address_line ?? '')">
                                        {{ $selectedAddress?->address_line }}
                                    </p>
                                    <div class="small text-mlm-muted">
                                        <i class="bi bi-geo-alt-fill text-mlm-primary me-1" aria-hidden="true"></i>
                                        <span x-text="selectedAddress ? selectedAddress.formatted_region : @js($selectedAddress?->formattedRegion() ?? '')">{{ $selectedAddress?->formattedRegion() }}</span>
                                    </div>
                                </div>

                                @if(count($addresses) > 1)
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm flex-shrink-0"
                                        @click="addressListExpanded = !addressListExpanded"
                                        :aria-expanded="addressListExpanded ? 'true' : 'false'"
                                    >
                                        <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                                        <span x-text="addressListExpanded ? 'Tutup Pilihan Alamat' : 'Ganti Alamat'">Ganti Alamat</span>
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- Daftar Alamat Aktif Milik Customer --}}
                        <div x-show="addressListExpanded || addresses.length <= 1">
                            <div
                                role="radiogroup"
                                aria-label="Pilih alamat pengiriman"
                                class="d-flex flex-column gap-2"
                            >
                                @foreach($addresses as $address)
                                    <button
                                        type="button"
                                        role="radio"
                                        class="mlm-checkout-option {{ $selectedAddress && (int) $selectedAddress->id === (int) $address['id'] ? 'is-selected' : '' }}"
                                        :class="{ 'is-selected': Number(selectedAddressId) === {{ (int) $address['id'] }} }"
                                        :aria-checked="Number(selectedAddressId) === {{ (int) $address['id'] }} ? 'true' : 'false'"
                                        @click="selectAddress({{ (int) $address['id'] }})"
                                    >
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="mlm-checkout-radio-dot mt-1" aria-hidden="true"></span>
                                            <div class="flex-grow-1 min-w-0">
                                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                                        <span class="fw-bold">{{ $address['label'] }}</span>
                                                        @if($address['is_primary'])
                                                            <span class="badge bg-mlm-secondary text-mlm-primary border border-success-subtle">
                                                                Alamat Utama
                                                            </span>
                                                        @endif
                                                        @if(! $address['has_valid_region'])
                                                            <span class="badge text-bg-warning">
                                                                Perlu Pilih Wilayah
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <span class="small fw-semibold text-mlm-primary" x-show="Number(selectedAddressId) === {{ (int) $address['id'] }}">
                                                        <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Dipilih
                                                    </span>
                                                </div>
                                                <div class="small fw-medium text-body">
                                                    {{ $address['recipient_name'] }} ({{ $address['recipient_phone'] }})
                                                </div>
                                                <div class="small text-mlm-muted mt-1">{{ $address['address_line'] }}</div>
                                                <div class="small text-mlm-muted">{{ $address['formatted_region'] }}</div>
                                            </div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>

                {{-- 2. Pemilihan Kurir & Layanan Pengiriman --}}
                @if(count($addresses) > 0)
                    <section class="mlm-card p-3 p-md-4" aria-labelledby="checkout-shipping-heading">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-3 mb-3 border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-bold px-2 py-1">2</span>
                                <h2 id="checkout-shipping-heading" class="h5 fw-bold mb-0 text-mlm-primary">Layanan Pengiriman</h2>
                            </div>

                            <button
                                type="button"
                                class="btn btn-outline-primary btn-sm"
                                :disabled="quoteLoading || !selectedAddressId"
                                @click="retryQuote()"
                            >
                                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                <span>Perbarui Tarif</span>
                            </button>
                        </div>

                        {{-- Peringatan Alamat Manual / Belum Ada Wilayah Tujuan --}}
                        <div
                            x-show="selectedAddress && !selectedAddress.has_valid_region && !quoteLoading && !quoteError"
                            x-cloak
                            class="alert alert-warning mb-3"
                            role="alert"
                        >
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 small">
                                <div>
                                    <div class="fw-semibold mb-1">Alamat ini belum memiliki ID wilayah tujuan pengiriman</div>
                                    <p class="mb-0">
                                        Pilih wilayah melalui fitur pencarian wilayah pada halaman edit alamat agar ongkos kirim dapat dihitung otomatis.
                                    </p>
                                </div>
                                <a
                                    :href="selectedAddress ? selectedAddress.edit_url : '#'"
                                    class="btn btn-sm btn-outline-primary flex-shrink-0"
                                >
                                    Lengkapi Alamat
                                </a>
                            </div>
                        </div>

                        {{-- State Loading --}}
                        <div x-show="quoteLoading" x-cloak class="text-center py-4" aria-live="polite">
                            <div class="spinner-border text-mlm-primary mb-2" role="status">
                                <span class="visually-hidden">Memuat ongkos kirim...</span>
                            </div>
                            <div class="fw-semibold text-mlm-primary">Menghitung tarif pengiriman...</div>
                            <p class="small text-mlm-muted mb-0">Mengambil pilihan kurir resmi berdasarkan berat paket dan alamat tujuan.</p>
                        </div>

                        {{-- State Error --}}
                        <div x-show="!quoteLoading && quoteError" x-cloak class="alert alert-danger mb-0" role="alert" aria-live="polite">
                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                                <div class="small">
                                    <div class="fw-bold mb-1">
                                        <i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i>
                                        Gagal Memuat Tarif Pengiriman
                                    </div>
                                    <div x-text="quoteError"></div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                                    <a
                                        x-show="selectedAddress && !selectedAddress.has_valid_region"
                                        :href="selectedAddress ? selectedAddress.edit_url : '#'"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Edit Alamat
                                    </a>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        :disabled="quoteLoading"
                                        @click="retryQuote()"
                                    >
                                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                        <span>Coba Lagi</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- State Empty (Tidak ada layanan tersedia) --}}
                        <div
                            x-show="!quoteLoading && !quoteError && quoteFetched && rates.length === 0"
                            x-cloak
                            class="text-center py-4 px-3 border rounded-3 bg-light-subtle"
                            aria-live="polite"
                        >
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-mlm-secondary text-mlm-primary mb-2" style="width: 52px; height: 52px;">
                                <i class="bi bi-truck fs-4" aria-hidden="true"></i>
                            </div>
                            <h3 class="h6 fw-bold mb-1">Belum Ada Layanan Pengiriman Tersedia</h3>
                            <p class="small text-mlm-muted mx-auto mb-3" style="max-width: 440px;">
                                Tidak ditemukan layanan ekspedisi yang mendukung rute atau berat paket untuk alamat ini. Anda dapat memilih alamat lain atau mencoba cek ulang.
                            </p>
                            <button
                                type="button"
                                class="btn btn-outline-primary btn-sm"
                                :disabled="quoteLoading"
                                @click="retryQuote()"
                            >
                                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                                <span>Coba Cek Ulang</span>
                            </button>
                        </div>

                        {{-- Daftar Layanan dari Quote Server --}}
                        <div x-show="!quoteLoading && !quoteError && rates.length > 0" x-cloak>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 small text-mlm-muted">
                                <span>
                                    Berat aktual <strong class="text-body" x-text="formatWeight(quoteData?.actual_weight_grams ?? serverSummary.total_weight_grams)"></strong>
                                    • Berat tagih ekspedisi <strong class="text-mlm-primary" x-text="formatWeight(quoteData?.billable_weight_grams ?? serverSummary.total_weight_grams)"></strong>
                                </span>
                                <span class="badge bg-mlm-secondary text-mlm-primary">
                                    <span x-text="rates.length"></span> layanan tersedia
                                </span>
                            </div>

                            <div class="d-flex flex-column gap-2" role="radiogroup" aria-label="Pilih layanan pengiriman">
                                <template x-for="rate in rates" :key="rateKey(rate)">
                                    <button
                                        type="button"
                                        role="radio"
                                        class="mlm-checkout-option"
                                        :class="{ 'is-selected': selectedRateKey === rateKey(rate) }"
                                        :aria-checked="selectedRateKey === rateKey(rate) ? 'true' : 'false'"
                                        @click="selectRate(rate)"
                                    >
                                        <div class="d-flex align-items-start justify-content-between gap-3">
                                            <div class="d-flex align-items-start gap-3 min-w-0">
                                                <span class="mlm-checkout-radio-dot mt-1" aria-hidden="true"></span>
                                                <div class="min-w-0">
                                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                                        <span class="fw-bold text-body" x-text="rate.courier_name"></span>
                                                        <span class="badge bg-mlm-secondary text-mlm-primary fw-semibold" x-text="rate.service_name"></span>
                                                        <span class="small text-mlm-muted" x-show="rate.service_code && rate.service_code !== rate.service_name" x-text="'(' + rate.service_code + ')'"></span>
                                                    </div>
                                                    <div class="small text-mlm-muted mt-1" x-show="rate.description" x-text="rate.description"></div>
                                                    <div class="small text-mlm-primary fw-medium mt-1" x-show="rate.etd">
                                                        <i class="bi bi-clock me-1" aria-hidden="true"></i>
                                                        <span>Estimasi: <span x-text="rate.etd"></span></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="text-end flex-shrink-0">
                                                <div class="fw-bold text-mlm-primary fs-6" x-text="formatRupiah(rate.cost)"></div>
                                            </div>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </div>

                        {{-- State Awal sebelum JS aktif (SSR Fallback) --}}
                        <div x-show="!hydrated" class="small text-mlm-muted py-2">
                            Menyiapkan permintaan tarif pengiriman ke server...
                        </div>
                    </section>
                @endif

                {{-- 3. Daftar Produk dalam Keranjang --}}
                <section class="mlm-card p-3 p-md-4" aria-labelledby="checkout-items-heading">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-3 mb-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-bold px-2 py-1">{{ count($addresses) > 0 ? '3' : '2' }}</span>
                            <h2 id="checkout-items-heading" class="h5 fw-bold mb-0 text-mlm-primary">Ringkasan Produk</h2>
                        </div>

                        <a href="{{ route('storefront.cart') }}" class="btn btn-outline-primary btn-sm">
                            Ubah Keranjang
                        </a>
                    </div>

                    <div class="d-flex flex-column gap-3">
                        @foreach($items as $item)
                            <article class="d-flex gap-3 pb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                                <div class="mlm-checkout-item-thumb">
                                    @if(! empty($item['image_url']))
                                        <img
                                            src="{{ $item['image_url'] }}"
                                            alt="{{ $item['name'] }}"
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
                                            <h3 class="h6 fw-bold mb-1">
                                                @if(! empty($item['product_url']))
                                                    <a href="{{ $item['product_url'] }}" class="text-decoration-none text-reset">
                                                        {{ $item['name'] }}
                                                    </a>
                                                @else
                                                    {{ $item['name'] }}
                                                @endif
                                            </h3>

                                            @if(! empty($item['variant_name']))
                                                <div class="small text-mlm-primary fw-medium">
                                                    Varian: {{ $item['variant_name'] }}
                                                </div>
                                            @endif

                                            @if(! empty($item['variant_attributes']))
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    @foreach($item['variant_attributes'] as $attr)
                                                        <span class="badge bg-mlm-secondary text-mlm-primary fw-normal">
                                                            {{ $attr['name'] }}: {{ $attr['value'] }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="small text-mlm-muted mt-1">
                                                {{ number_format($item['quantity'], 0, ',', '.') }} x Rp {{ number_format($item['unit_price'], 0, ',', '.') }}
                                                @if($item['is_promo'])
                                                    <span class="text-decoration-line-through ms-1">
                                                        Rp {{ number_format($item['regular_price'], 0, ',', '.') }}
                                                    </span>
                                                @endif
                                                <span class="ms-2">• {{ number_format($item['line_weight_grams'], 0, ',', '.') }} g</span>
                                            </div>
                                        </div>

                                        <div class="fw-bold text-mlm-primary text-sm-end flex-shrink-0">
                                            Rp {{ number_format($item['line_subtotal'], 0, ',', '.') }}
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                @if(count($addresses) > 0)
                    {{-- 4. Catatan Pesanan Opsional --}}
                    <section class="mlm-card p-3 p-md-4" aria-labelledby="checkout-notes-heading">
                        <div class="d-flex align-items-center gap-2 pb-2 mb-3 border-bottom">
                            <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-bold px-2 py-1">4</span>
                            <h2 id="checkout-notes-heading" class="h5 fw-bold mb-0 text-mlm-primary">Catatan Pesanan (Opsional)</h2>
                        </div>

                        <label for="checkout-customer-notes" class="form-label small fw-semibold">
                            Catatan untuk Penjual atau Pengemasan
                        </label>
                        <textarea
                            id="checkout-customer-notes"
                            rows="2"
                            maxlength="500"
                            x-model="customerNotes"
                            @input="refreshIdempotencyKey()"
                            :disabled="submitting"
                            class="form-control"
                            placeholder="Contoh: Mohon dikemas rapi, kirim pada jam kerja..."
                        ></textarea>
                        <div class="d-flex justify-content-between small text-mlm-muted mt-1">
                            <span>Opsional, maksimal 500 karakter.</span>
                            <span x-text="(customerNotes || '').length + '/500'">0/500</span>
                        </div>
                    </section>
                @endif
            </div>

            {{-- Kolom Kanan: Ringkasan Checkout (Preview Total Sementara) --}}
            <div class="col-12 col-lg-5 col-xl-4">
                <aside class="mlm-card p-3 p-md-4 mlm-cart-summary-sticky" aria-label="Ringkasan Checkout">
                    <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                        <h2 class="h5 fw-bold mb-0 text-mlm-primary">Ringkasan Checkout</h2>
                        <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-semibold">
                            {{ number_format((int) $serverSummary['valid_quantity'], 0, ',', '.') }} item
                        </span>
                    </div>

                    <dl class="mb-3 small">
                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <dt class="fw-normal text-mlm-muted">Alamat Tujuan</dt>
                            <dd class="mb-0 fw-semibold text-end">
                                <span x-show="selectedAddress" x-text="selectedAddress ? selectedAddress.label : ''">
                                    {{ $selectedAddress?->label }}
                                </span>
                                <span x-show="!selectedAddress" @if($selectedAddress) x-cloak @endif class="text-mlm-muted">
                                    Belum ada alamat
                                </span>
                            </dd>
                        </div>

                        <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                            <dt class="fw-normal text-mlm-muted">Layanan Pengiriman</dt>
                            <dd class="mb-0 fw-semibold text-end">
                                <span x-show="selectedRate" x-cloak class="text-mlm-primary" x-text="selectedRate ? (selectedRate.courier_name + ' - ' + selectedRate.service_name) : ''"></span>
                                <span x-show="!selectedRate" class="text-mlm-muted">Belum dipilih</span>
                            </dd>
                        </div>

                        <div class="d-flex justify-content-between align-items-center py-2">
                            <dt class="fw-normal text-mlm-muted">Berat Total Produk</dt>
                            <dd class="mb-0 fw-semibold">
                                @php $grams = (int) $serverSummary['total_weight_grams']; @endphp
                                {{ $grams >= 1000 ? number_format($grams / 1000, 2, ',', '.').' kg' : number_format($grams, 0, ',', '.').' gram' }}
                            </dd>
                        </div>

                        <div class="d-flex justify-content-between align-items-center py-2">
                            <dt class="fw-normal text-mlm-muted">Subtotal Produk</dt>
                            <dd class="mb-0 fw-semibold">
                                Rp {{ number_format((float) $serverSummary['subtotal'], 0, ',', '.') }}
                            </dd>
                        </div>

                        @if((float) $serverSummary['discount_amount'] > 0)
                            <div class="d-flex justify-content-between align-items-center py-2">
                                <dt class="fw-normal text-mlm-muted">Diskon</dt>
                                <dd class="mb-0 fw-semibold text-success">
                                    - Rp {{ number_format((float) $serverSummary['discount_amount'], 0, ',', '.') }}
                                </dd>
                            </div>
                        @endif

                        @if((float) $serverSummary['tax_amount'] > 0)
                            <div class="d-flex justify-content-between align-items-center py-2">
                                <dt class="fw-normal text-mlm-muted">
                                    Pajak ({{ rtrim(rtrim(number_format((float) $serverSummary['tax_percentage'], 2, ',', '.'), '0'), ',') }}%)
                                </dt>
                                <dd class="mb-0 fw-semibold">
                                    Rp {{ number_format((float) $serverSummary['tax_amount'], 0, ',', '.') }}
                                </dd>
                            </div>
                        @endif

                        <div class="d-flex justify-content-between align-items-center py-2">
                            <dt class="fw-normal text-mlm-muted">Ongkos Kirim</dt>
                            <dd class="mb-0 fw-semibold">
                                <span x-show="selectedRate" x-cloak class="text-mlm-primary" x-text="selectedRate ? formatRupiah(selectedRate.cost) : ''"></span>
                                <span x-show="!selectedRate" class="text-mlm-muted">Pilih layanan</span>
                            </dd>
                        </div>
                    </dl>

                    <div class="border-top pt-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <span class="fw-bold text-body">Total Sementara</span>
                            <span class="fs-4 fw-bold text-mlm-primary" aria-live="polite">
                                <span x-show="!hydrated">
                                    Rp {{ number_format(max(0, (float) $serverSummary['subtotal'] - (float) $serverSummary['discount_amount']) + (float) $serverSummary['tax_amount'], 0, ',', '.') }}
                                </span>
                                <span x-show="hydrated" x-cloak x-text="formatRupiah(estimatedGrandTotal)"></span>
                            </span>
                        </div>
                    </div>

                    <div
                        class="rounded-3 p-3 small mb-3"
                        :class="selectedRate ? 'bg-mlm-secondary text-mlm-primary border border-success-subtle' : 'bg-light text-mlm-muted border'"
                    >
                        <div x-show="selectedRate" x-cloak class="fw-semibold">
                            <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
                            Layanan pengiriman siap: <span x-text="selectedRate ? (selectedRate.courier_name + ' ' + selectedRate.service_name) : ''"></span>
                        </div>
                        <div x-show="!selectedRate">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            Silakan pilih alamat dan satu layanan pengiriman untuk melihat total sementara termasuk ongkos kirim.
                        </div>
                    </div>

                    <div
                        x-show="submitError"
                        x-cloak
                        class="alert alert-danger small mb-3"
                        role="alert"
                        aria-live="polite"
                    >
                        <div class="fw-bold mb-1">
                            <i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i>
                            Pesanan Belum Dapat Dibuat
                        </div>
                        <div x-text="submitError"></div>
                    </div>

                    <p class="small text-mlm-muted mb-3">
                        Harga produk, ketersediaan stok, berat paket, dan ongkos kirim diverifikasi ulang langsung di server saat pesanan dibuat.
                    </p>

                    @if(count($addresses) > 0)
                        <button
                            type="button"
                            class="btn btn-primary w-100 fw-semibold mb-2"
                            style="min-height: 44px;"
                            :disabled="!canSubmitOrder"
                            @click="submitOrder()"
                        >
                            <span x-show="!submitting">
                                <i class="bi bi-bag-check me-1" aria-hidden="true"></i>
                                <span>Buat Pesanan</span>
                            </span>
                            <span x-show="submitting" x-cloak>
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                <span>Memproses Pesanan...</span>
                            </span>
                        </button>
                    @endif

                    <a href="{{ route('storefront.cart') }}" class="btn btn-outline-primary w-100">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        <span>Kembali ke Keranjang</span>
                    </a>
                </aside>
            </div>
        </div>
    @endif
</div>
@endsection

@if($canCheckout)
@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('checkoutPage', (config) => ({
        hydrated: false,
        canCheckout: Boolean(config.canCheckout),
        quoteUrl: config.quoteUrl || null,
        storeUrl: config.storeUrl || null,
        addresses: Array.isArray(config.addresses) ? config.addresses : [],
        selectedAddressId: config.initialAddressId ? Number(config.initialAddressId) : null,
        addressListExpanded: (Array.isArray(config.addresses) ? config.addresses.length : 0) <= 1,
        serverSummary: config.summary || {
            valid_quantity: 0,
            total_weight_grams: 0,
            subtotal: 0,
            discount_amount: 0,
            tax_percentage: 0,
            tax_amount: 0,
        },
        quoteLoading: false,
        quoteError: '',
        quoteData: null,
        rates: [],
        quoteFetched: false,
        selectedRateKey: null,
        selectedRate: null,
        customerNotes: '',
        idempotencyKey: '',
        submitting: false,
        submitError: '',
        abortController: null,
        activeRequestToken: 0,

        init() {
            this.hydrated = true;
            this.idempotencyKey = this.generateIdempotencyKey();

            if (this.canCheckout && this.addresses.length > 0 && this.selectedAddressId && this.quoteUrl) {
                const current = this.selectedAddress;
                if (current && current.has_valid_region) {
                    this.fetchShippingQuote(true);
                }
            }
        },

        get selectedAddress() {
            if (!this.selectedAddressId) {
                return null;
            }
            return this.addresses.find((item) => Number(item.id) === Number(this.selectedAddressId)) || null;
        },

        get shippingCostPreview() {
            return this.selectedRate ? (Number(this.selectedRate.cost) || 0) : 0;
        },

        get estimatedGrandTotal() {
            const subtotal = Number(this.serverSummary.subtotal) || 0;
            const discount = Number(this.serverSummary.discount_amount) || 0;
            const tax = Number(this.serverSummary.tax_amount) || 0;

            return Math.max(0, subtotal - discount) + tax + this.shippingCostPreview;
        },

        get canSubmitOrder() {
            return this.canCheckout
                && Boolean(this.storeUrl)
                && Boolean(this.selectedAddressId)
                && Boolean(this.selectedRate)
                && !this.quoteLoading
                && !this.submitting;
        },

        generateIdempotencyKey() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }

            if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                const bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
            }

            return 'chk-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 14);
        },

        refreshIdempotencyKey() {
            this.idempotencyKey = this.generateIdempotencyKey();
            this.submitError = '';
        },

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        },

        rateKey(rate) {
            if (!rate) {
                return '';
            }
            return `${String(rate.courier_code || '').toLowerCase()}:${String(rate.service_code || '').toUpperCase()}`;
        },

        formatRupiah(value) {
            return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(Number(value) || 0));
        },

        formatWeight(grams) {
            const safeGrams = Number(grams) || 0;
            if (safeGrams >= 1000) {
                return (safeGrams / 1000).toLocaleString('id-ID', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 2,
                }) + ' kg';
            }
            return new Intl.NumberFormat('id-ID').format(safeGrams) + ' gram';
        },

        resetShippingSelectionAndQuote() {
            this.selectedRateKey = null;
            this.selectedRate = null;
            this.rates = [];
            this.quoteData = null;
            this.quoteError = '';
            this.quoteFetched = false;
            this.refreshIdempotencyKey();
        },

        selectAddress(addressId) {
            const normalizedId = Number(addressId);
            if (!normalizedId || this.submitting) {
                return;
            }

            if (normalizedId === Number(this.selectedAddressId) && this.quoteFetched && !this.quoteError) {
                return;
            }

            this.selectedAddressId = normalizedId;
            this.resetShippingSelectionAndQuote();

            const chosen = this.selectedAddress;
            if (!chosen || !chosen.has_valid_region) {
                if (this.abortController) {
                    this.abortController.abort();
                }
                this.activeRequestToken++;
                this.quoteLoading = false;
                return;
            }

            this.fetchShippingQuote(true);
        },

        retryQuote() {
            if (this.quoteLoading || this.submitting || !this.selectedAddressId) {
                return;
            }

            this.selectedRateKey = null;
            this.selectedRate = null;
            this.refreshIdempotencyKey();
            this.fetchShippingQuote(false);
        },

        selectRate(rate) {
            if (!rate || this.submitting) {
                return;
            }

            const key = this.rateKey(rate);
            const matched = this.rates.find((item) => this.rateKey(item) === key);
            if (!matched) {
                return;
            }

            if (this.selectedRateKey !== key) {
                this.selectedRateKey = key;
                this.selectedRate = matched;
                this.refreshIdempotencyKey();
            }
        },

        async fetchShippingQuote(allowSupersede = false) {
            if (!this.canCheckout || !this.quoteUrl || !this.selectedAddressId) {
                return;
            }

            if (this.quoteLoading && !allowSupersede) {
                return;
            }

            if (this.abortController) {
                this.abortController.abort();
            }

            const requestToken = ++this.activeRequestToken;
            const targetAddressId = Number(this.selectedAddressId);

            // Reset pilihan layanan lama setiap kali quote diminta ulang.
            this.selectedRateKey = null;
            this.selectedRate = null;
            this.quoteLoading = true;
            this.quoteError = '';

            this.abortController = new AbortController();

            try {
                const response = await fetch(this.quoteUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        address_id: targetAddressId,
                    }),
                    signal: this.abortController.signal,
                });

                const payload = await response.json().catch(() => null);

                // Abaikan respons lama jika alamat berubah sebelum request ini selesai.
                if (requestToken !== this.activeRequestToken || Number(this.selectedAddressId) !== targetAddressId) {
                    return;
                }

                if (!response.ok || !payload || payload.success !== true) {
                    const validationMsg = payload?.errors?.address_id?.[0];
                    this.rates = [];
                    this.quoteData = null;
                    this.quoteFetched = false;
                    this.selectedRateKey = null;
                    this.selectedRate = null;
                    this.quoteError = validationMsg || payload?.message || 'Gagal mengambil tarif pengiriman. Silakan coba lagi.';
                    return;
                }

                const data = payload.data || {};
                this.quoteData = data;
                this.rates = Array.isArray(data.rates) ? data.rates : [];
                this.quoteFetched = true;
                this.quoteError = '';
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                if (requestToken === this.activeRequestToken) {
                    this.rates = [];
                    this.quoteData = null;
                    this.quoteFetched = false;
                    this.selectedRateKey = null;
                    this.selectedRate = null;
                    this.quoteError = 'Terjadi gangguan jaringan saat mengambil ongkos kirim. Silakan coba lagi.';
                }
            } finally {
                if (requestToken === this.activeRequestToken) {
                    this.quoteLoading = false;
                }
            }
        },

        async submitOrder() {
            if (!this.canSubmitOrder) {
                return;
            }

            if (!this.idempotencyKey) {
                this.idempotencyKey = this.generateIdempotencyKey();
            }

            this.submitting = true;
            this.submitError = '';

            try {
                const response = await fetch(this.storeUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                        'Idempotency-Key': this.idempotencyKey,
                    },
                    body: JSON.stringify({
                        address_id: Number(this.selectedAddressId),
                        courier_code: String(this.selectedRate.courier_code || '').toLowerCase(),
                        service_code: String(this.selectedRate.service_code || '').toUpperCase(),
                        idempotency_key: this.idempotencyKey,
                        customer_notes: String(this.customerNotes || '').trim() || null,
                    }),
                });

                const payload = await response.json().catch(() => null);

                if (!response.ok || !payload || payload.success !== true) {
                    const firstValidationError = payload?.errors
                        ? Object.values(payload.errors).flat()[0]
                        : null;

                    this.submitError = firstValidationError
                        || payload?.message
                        || 'Gagal membuat pesanan. Silakan periksa kembali data checkout Anda.';

                    if (response.status === 409) {
                        this.idempotencyKey = this.generateIdempotencyKey();
                    }

                    return;
                }

                if (payload.data && payload.data.redirect_url) {
                    window.location.assign(payload.data.redirect_url);
                }
            } catch (error) {
                // Pertahankan idempotencyKey yang sama saat retry akibat gangguan jaringan.
                this.submitError = 'Terjadi gangguan jaringan saat membuat pesanan. Silakan coba kirim ulang.';
            } finally {
                this.submitting = false;
            }
        },
    }));
});
</script>
@endpush
@endif
