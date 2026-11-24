@extends('layouts.storefront')

@section('title', $seo['title'])
@section('meta_description', $seo['description'])
@section('canonical', $seo['canonical'])
@section('robots', $seo['robots'])
@section('og_title', $seo['title'])
@section('og_description', $seo['description'])

@section('content')
<div
    class="container py-3 py-md-4"
    x-data="cartPage({
        initialCart: @js($cartSnapshot),
        clearUrl: @js(route('storefront.cart.clear')),
    })"
>
    <x-breadcrumb :items="$breadcrumbs" />

    {{-- Header Halaman --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h2 fw-bold text-mlm-primary mb-1">Keranjang Belanja</h1>
            <p class="small text-mlm-muted mb-0">
                <span x-show="!hydrated">
                    Pastikan produk dan jumlah pembelian sudah sesuai ({{ number_format((int) $cartSnapshot['valid_quantity'], 0, ',', '.') }} item valid@if((int) $cartSnapshot['total_quantity'] > (int) $cartSnapshot['valid_quantity']) dari {{ number_format((int) $cartSnapshot['total_quantity'], 0, ',', '.') }} item tersimpan@endif).
                </span>
                <span x-show="hydrated" x-cloak>
                    Pastikan produk dan jumlah pembelian sudah sesuai (<span x-text="cart.valid_quantity"></span> item valid<template x-if="cart.total_quantity > cart.valid_quantity"><span> dari <span x-text="cart.total_quantity"></span> item tersimpan</span></template>).
                </span>
            </p>
        </div>

        <a href="{{ route('storefront.catalog') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Lanjut Belanja</span>
        </a>
    </div>

    {{-- Banner Informasi Isolasi Admin & Guest Cart --}}
    @if(\Illuminate\Support\Facades\Auth::guard('admin')->check() && \Illuminate\Support\Facades\Auth::guard('customer')->guest())
        <x-alert type="info" class="mb-3">
            <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2 small">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-shield-lock-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
                    <span>Anda sedang login sebagai Administrator. Keranjang ini adalah keranjang pengunjung (guest session) yang terpisah dari akun admin dan tidak akan digabungkan ke akun admin.</span>
                </div>
                @if(\Illuminate\Support\Facades\Route::has('admin.dashboard'))
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm btn-outline-primary flex-shrink-0">
                        Kembali ke Admin
                    </a>
                @endif
            </div>
        </x-alert>
    @endif

    {{-- Peringatan Ketersediaan / Perubahan Stok --}}
    @if(!empty($cartSnapshot['warnings']))
        <div x-show="!hydrated" class="alert alert-warning mb-3" role="alert">
            <div class="fw-semibold mb-1">
                <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                Perhatian pada isi keranjang Anda:
            </div>
            <ul class="mb-0 ps-3 small">
                @foreach($cartSnapshot['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div x-show="hydrated && cart.warnings.length > 0" x-cloak class="alert alert-warning mb-3" role="alert">
        <div class="fw-semibold mb-1">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            Perhatian pada isi keranjang Anda:
        </div>
        <ul class="mb-0 ps-3 small">
            <template x-for="(warning, idx) in cart.warnings" :key="idx">
                <li x-text="warning"></li>
            </template>
        </ul>
    </div>

    <div
        x-show="actionError"
        x-cloak
        class="alert alert-danger py-2 px-3 small mb-3"
        role="alert"
        x-text="actionError"
    ></div>

    {{-- Empty State --}}
    <div x-show="cart.items.length === 0" @if(count($cartSnapshot['items']) > 0) x-cloak @endif>
        <x-empty-state
            icon="bi-bag"
            title="Keranjang Belanja Masih Kosong"
            message="Anda belum menambahkan produk ke keranjang. Jelajahi produk UMKM pilihan kami untuk mulai berbelanja."
            action-label="Mulai Belanja"
            :action-url="route('storefront.catalog')"
        />
    </div>

    {{-- Konten Utama Keranjang --}}
    <div x-show="cart.items.length > 0" @if(count($cartSnapshot['items']) === 0) x-cloak @endif>
        <div class="row g-4 align-items-start">
            {{-- Kolom Kiri: Daftar Item --}}
            <div class="col-12 col-lg-8">
                <section class="mlm-card p-3 p-md-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-3 mb-3 border-bottom">
                        <h2 class="h5 fw-bold mb-0 text-mlm-primary">Daftar Produk</h2>
                        <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-semibold px-3 py-2">
                            <span x-show="!hydrated">{{ count($cartSnapshot['items']) }} baris produk</span>
                            <span x-show="hydrated" x-cloak><span x-text="cart.items.length"></span> baris produk</span>
                        </span>
                    </div>

                    {{-- Mode SSR / Non-JS Fallback --}}
                    <div x-show="!hydrated">
                        <div class="d-flex flex-column gap-3">
                            @foreach($cartSnapshot['items'] as $item)
                                <article class="mlm-cart-item {{ !$item['is_available'] ? 'is-unavailable' : '' }}">
                                    <div class="d-flex gap-3">
                                        <div class="mlm-cart-thumb">
                                            @if(!empty($item['image_url']))
                                                <img
                                                    src="{{ $item['image_url'] }}"
                                                    alt="{{ $item['name'] }}"
                                                    class="w-100 h-100 object-fit-cover"
                                                    loading="lazy"
                                                >
                                            @else
                                                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-mlm-primary">
                                                    <i class="bi bi-image fs-4 opacity-50" aria-hidden="true"></i>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                <div class="min-w-0">
                                                    @if(!empty($item['product_url']))
                                                        <h3 class="h6 fw-bold mb-1">
                                                            <a href="{{ $item['product_url'] }}" class="text-decoration-none text-reset">
                                                                {{ $item['name'] }}
                                                            </a>
                                                        </h3>
                                                    @else
                                                        <h3 class="h6 fw-bold mb-1 text-mlm-muted">{{ $item['name'] }}</h3>
                                                    @endif

                                                    @if(!empty($item['sku']))
                                                        <div class="small text-mlm-muted">SKU: {{ $item['sku'] }}</div>
                                                    @endif

                                                    @if(!empty($item['variant_name']))
                                                        <div class="small fw-medium text-mlm-primary mt-1">
                                                            Varian: {{ $item['variant_name'] }}
                                                        </div>
                                                    @endif

                                                    @if(!empty($item['variant_attributes']))
                                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                                            @foreach($item['variant_attributes'] as $attr)
                                                                <span class="badge bg-mlm-secondary text-mlm-primary fw-normal">
                                                                    {{ $attr['name'] }}: {{ $attr['value'] }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>

                                                <div class="text-sm-end">
                                                    @if($item['is_promo'])
                                                        <div class="small text-decoration-line-through text-mlm-muted">
                                                            Rp {{ number_format($item['regular_price'], 0, ',', '.') }}
                                                        </div>
                                                    @endif
                                                    <div class="fw-semibold text-mlm-primary">
                                                        Rp {{ number_format($item['unit_price'], 0, ',', '.') }}
                                                    </div>
                                                    <div class="small text-mlm-muted">
                                                        @if($item['is_available'])
                                                            Stok: {{ number_format($item['available_stock'], 0, ',', '.') }}
                                                        @else
                                                            <span class="text-danger">Tidak tersedia</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>

                                            @if(!$item['is_available'] && !empty($item['unavailable_reason']))
                                                <div class="alert alert-danger py-1 px-2 small mt-2 mb-0">
                                                    <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
                                                    {{ $item['unavailable_reason'] }}
                                                </div>
                                            @endif

                                            @php
                                                $ssrQuantityDisabled = (int) $item['available_stock'] <= 0
                                                    || (! $item['is_available'] && (int) $item['quantity'] <= (int) $item['available_stock']);
                                            @endphp

                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-2 border-top">
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <form method="POST" action="{{ $item['update_url'] }}" class="d-inline-flex align-items-center gap-2">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input
                                                            type="number"
                                                            name="quantity"
                                                            value="{{ $item['quantity'] }}"
                                                            min="1"
                                                            max="{{ max(1, (int) $item['available_stock']) }}"
                                                            class="form-control form-control-sm text-center"
                                                            style="width: 76px;"
                                                            aria-label="Kuantitas {{ $item['name'] }}"
                                                            @if($ssrQuantityDisabled) disabled @endif
                                                        >
                                                        <button type="submit" class="btn btn-sm btn-outline-primary" @if($ssrQuantityDisabled) disabled @endif>
                                                            Perbarui
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="{{ $item['remove_url'] }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Hapus {{ $item['name'] }}">
                                                            <i class="bi bi-trash" aria-hidden="true"></i> Hapus
                                                        </button>
                                                    </form>
                                                </div>

                                                <div class="fw-bold text-mlm-primary">
                                                    @if($item['is_available'])
                                                        Subtotal: Rp {{ number_format($item['line_subtotal'], 0, ',', '.') }}
                                                    @else
                                                        <span class="text-danger small fw-semibold">Tidak dihitung dalam subtotal</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>

                    {{-- Mode Reaktif Alpine.js --}}
                    <div x-show="hydrated" x-cloak>
                        <div class="d-flex flex-column gap-3">
                            <template x-for="item in cart.items" :key="item.key">
                                <article class="mlm-cart-item" :class="{ 'is-unavailable': !item.is_available }">
                                    <div class="d-flex gap-3">
                                        <div class="mlm-cart-thumb">
                                            <img
                                                x-show="item.image_url"
                                                :src="item.image_url || ''"
                                                :alt="item.name"
                                                class="w-100 h-100 object-fit-cover"
                                                loading="lazy"
                                            >
                                            <div x-show="!item.image_url" class="w-100 h-100">
                                                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-mlm-primary">
                                                    <i class="bi bi-image fs-4 opacity-50" aria-hidden="true"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                <div class="min-w-0">
                                                    <h3 class="h6 fw-bold mb-1">
                                                        <a
                                                            x-show="item.product_url"
                                                            :href="item.product_url"
                                                            class="text-decoration-none text-reset"
                                                            x-text="item.name"
                                                        ></a>
                                                        <span
                                                            x-show="!item.product_url"
                                                            class="text-mlm-muted"
                                                            x-text="item.name"
                                                        ></span>
                                                    </h3>

                                                    <div x-show="item.sku" class="small text-mlm-muted">
                                                        SKU: <span x-text="item.sku"></span>
                                                    </div>

                                                    <div x-show="item.variant_name" class="small fw-medium text-mlm-primary mt-1">
                                                        Varian: <span x-text="item.variant_name"></span>
                                                    </div>

                                                    <div x-show="item.variant_attributes && item.variant_attributes.length > 0" class="mt-1">
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <template x-for="attr in (item.variant_attributes || [])" :key="attr.name + ':' + attr.value">
                                                                <span class="badge bg-mlm-secondary text-mlm-primary fw-normal" x-text="attr.name + ': ' + attr.value"></span>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="text-sm-end">
                                                    <div x-show="item.is_promo" class="small text-decoration-line-through text-mlm-muted" x-text="formatRupiah(item.regular_price)"></div>
                                                    <div class="fw-semibold text-mlm-primary" x-text="formatRupiah(item.unit_price)"></div>
                                                    <div class="small text-mlm-muted">
                                                        <span x-show="item.is_available">Stok: <span x-text="item.available_stock"></span></span>
                                                        <span x-show="!item.is_available" class="text-danger">Tidak tersedia</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div x-show="!item.is_available && item.unavailable_reason" class="alert alert-danger py-1 px-2 small mt-2 mb-0">
                                                <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
                                                <span x-text="item.unavailable_reason"></span>
                                            </div>

                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-2 border-top">
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <div class="input-group mlm-cart-qty-group">
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-primary"
                                                            :disabled="busyItemKey === item.key || !canModifyQuantity(item) || item.quantity <= 1"
                                                            @click="decrementQuantity(item)"
                                                            aria-label="Kurangi jumlah"
                                                        >
                                                            <i class="bi bi-dash-lg"></i>
                                                        </button>
                                                        <input
                                                            type="number"
                                                            class="form-control text-center fw-semibold"
                                                            min="1"
                                                            :max="Math.max(1, item.available_stock)"
                                                            :value="item.quantity"
                                                            :disabled="busyItemKey === item.key || !canModifyQuantity(item)"
                                                            @change="handleQuantityInput(item, $event)"
                                                            :aria-label="'Kuantitas ' + item.name"
                                                        >
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-primary"
                                                            :disabled="busyItemKey === item.key || !canModifyQuantity(item) || item.quantity >= item.available_stock"
                                                            @click="updateQuantity(item, item.quantity + 1)"
                                                            aria-label="Tambah jumlah"
                                                        >
                                                            <i class="bi bi-plus-lg"></i>
                                                        </button>
                                                    </div>

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        style="min-height: 40px;"
                                                        :disabled="busyItemKey === item.key"
                                                        @click="removeItem(item)"
                                                    >
                                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                                        <span>Hapus</span>
                                                    </button>
                                                </div>

                                                <div class="fw-bold text-mlm-primary">
                                                    <span x-show="item.is_available">
                                                        Subtotal: <span x-text="formatRupiah(item.line_subtotal)"></span>
                                                    </span>
                                                    <span x-show="!item.is_available" class="text-danger small fw-semibold">
                                                        Tidak dihitung dalam subtotal
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </div>

                    {{-- Aksi Kosongkan Keranjang --}}
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3 mt-3 border-top">
                        <small class="text-mlm-muted">
                            Harga dan ketersediaan stok selalu diverifikasi ulang langsung dari server.
                        </small>

                        <div x-show="!hydrated">
                            <form method="POST" action="{{ route('storefront.cart.clear') }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-circle" aria-hidden="true"></i> Kosongkan Keranjang
                                </button>
                            </form>
                        </div>

                        <div x-show="hydrated" x-cloak>
                            <div x-show="!confirmClearOpen">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    style="min-height: 40px;"
                                    :disabled="clearing"
                                    @click="confirmClearOpen = true"
                                >
                                    <i class="bi bi-x-circle" aria-hidden="true"></i> Kosongkan Keranjang
                                </button>
                            </div>

                            <div x-show="confirmClearOpen" x-cloak>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="small text-danger fw-medium">Kosongkan seluruh keranjang?</span>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        style="min-height: 38px;"
                                        :disabled="clearing"
                                        @click="clearCart()"
                                    >
                                        Ya, Kosongkan
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        style="min-height: 38px;"
                                        :disabled="clearing"
                                        @click="confirmClearOpen = false"
                                    >
                                        Batal
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Kolom Kanan: Ringkasan Belanja --}}
            <div class="col-12 col-lg-4">
                <aside class="mlm-card p-3 p-md-4 mlm-cart-summary-sticky" aria-label="Ringkasan Belanja">
                    <div class="d-flex justify-content-between align-items-center pb-3 mb-3 border-bottom">
                        <h2 class="h5 fw-bold mb-0 text-mlm-primary">Ringkasan Belanja</h2>
                        <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary fw-semibold">
                            <span x-show="!hydrated">{{ (int) $cartSnapshot['valid_quantity'] }} item valid</span>
                            <span x-show="hydrated" x-cloak><span x-text="cart.valid_quantity"></span> item valid</span>
                        </span>
                    </div>

                    <dl class="mb-3">
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <dt class="fw-normal text-mlm-muted">Jumlah Item Valid</dt>
                            <dd class="mb-0 fw-semibold">
                                <span x-show="!hydrated">{{ number_format((int) $cartSnapshot['valid_quantity'], 0, ',', '.') }} item</span>
                                <span x-show="hydrated" x-cloak><span x-text="cart.valid_quantity"></span> item</span>
                            </dd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <dt class="fw-normal text-mlm-muted">Estimasi Berat</dt>
                            <dd class="mb-0 fw-semibold">
                                <span x-show="!hydrated">
                                    @php $grams = (int) $cartSnapshot['total_weight_grams']; @endphp
                                    {{ $grams >= 1000 ? number_format($grams / 1000, 2, ',', '.').' kg' : number_format($grams, 0, ',', '.').' gram' }}
                                </span>
                                <span x-show="hydrated" x-cloak x-text="formatWeight(cart.total_weight_grams)"></span>
                            </dd>
                        </div>
                    </dl>

                    <div class="border-top pt-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-body">Total Subtotal</span>
                            <span class="fs-4 fw-bold text-mlm-primary">
                                <span x-show="!hydrated">Rp {{ number_format((float) $cartSnapshot['subtotal'], 0, ',', '.') }}</span>
                                <span x-show="hydrated" x-cloak x-text="formatRupiah(cart.subtotal)"></span>
                            </span>
                        </div>
                    </div>

                    <div
                        x-show="cart.has_unavailable_items"
                        @if(!$cartSnapshot['has_unavailable_items']) x-cloak @endif
                        class="alert alert-warning py-2 px-3 small mb-3"
                    >
                        Sesuaikan atau hapus item yang bermasalah agar ringkasan belanja siap diproses.
                    </div>

                    <div class="d-grid gap-2">
                        @if((int) $cartSnapshot['valid_quantity'] > 0 && ! $cartSnapshot['has_unavailable_items'])
                            <a
                                x-show="!hydrated"
                                href="{{ route('customer.checkout.index') }}"
                                class="btn btn-primary w-100"
                            >
                                <i class="bi bi-shield-check" aria-hidden="true"></i>
                                <span>Lanjut ke Checkout</span>
                            </a>
                        @else
                            <button
                                x-show="!hydrated"
                                type="button"
                                class="btn btn-primary w-100"
                                disabled
                                aria-disabled="true"
                            >
                                <i class="bi bi-shield-check" aria-hidden="true"></i>
                                <span>Lanjut ke Checkout</span>
                            </button>
                        @endif

                        <a
                            x-show="hydrated && cart.valid_quantity > 0 && !cart.has_unavailable_items"
                            x-cloak
                            href="{{ route('customer.checkout.index') }}"
                            class="btn btn-primary w-100"
                        >
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <span>Lanjut ke Checkout</span>
                        </a>

                        <button
                            x-show="hydrated && (cart.valid_quantity <= 0 || cart.has_unavailable_items)"
                            x-cloak
                            type="button"
                            class="btn btn-primary w-100"
                            disabled
                            aria-disabled="true"
                        >
                            <i class="bi bi-shield-check" aria-hidden="true"></i>
                            <span>Lanjut ke Checkout</span>
                        </button>

                        <a href="{{ route('storefront.catalog') }}" class="btn btn-outline-primary w-100">
                            Lanjut Belanja
                        </a>
                    </div>

                    <p class="small text-mlm-muted mt-3 mb-0">
                        Pemilihan alamat pengiriman dan perhitungan ongkos kirim resmi dilakukan di halaman checkout.
                    </p>
                </aside>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('cartPage', (config) => ({
        hydrated: false,
        cart: config.initialCart || {
            items: [],
            total_quantity: 0,
            valid_quantity: 0,
            subtotal: 0,
            total_weight_grams: 0,
            has_unavailable_items: false,
            warnings: [],
        },
        clearUrl: config.clearUrl,
        busyItemKey: null,
        clearing: false,
        confirmClearOpen: false,
        actionError: '',

        init() {
            this.hydrated = true;
        },

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        },

        syncHeaderBadge(count) {
            const safeCount = Number(count ?? 0);
            document.querySelectorAll('.mlm-cart-badge').forEach((badge) => {
                badge.textContent = String(safeCount);
                const link = badge.closest('a');
                if (link) {
                    link.setAttribute('aria-label', `Keranjang belanja (${safeCount} item)`);
                }
            });
        },

        notify(message, type = 'success') {
            if (window.Alpine?.store('toast')) {
                window.Alpine.store('toast').push(message, type);
            }
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

        canModifyQuantity(item) {
            const stock = Number(item.available_stock) || 0;
            if (stock <= 0) {
                return false;
            }

            if (!item.is_available && Number(item.quantity) <= stock) {
                return false;
            }

            return true;
        },

        decrementQuantity(item) {
            if (!this.canModifyQuantity(item) || Number(item.quantity) <= 1) {
                return;
            }

            const stock = Number(item.available_stock) || 0;
            const nextQty = Math.min(Number(item.quantity) - 1, stock);
            if (nextQty >= 1) {
                this.updateQuantity(item, nextQty);
            }
        },

        handleQuantityInput(item, event) {
            if (!this.canModifyQuantity(item)) {
                event.target.value = item.quantity;
                return;
            }

            const rawValue = parseInt(event.target.value, 10);
            if (Number.isNaN(rawValue) || rawValue < 1) {
                event.target.value = item.quantity;
                this.actionError = 'Kuantitas minimal 1 unit.';
                this.notify(this.actionError, 'error');
                return;
            }
            if (rawValue === Number(item.quantity)) {
                return;
            }
            this.updateQuantity(item, rawValue, event.target);
        },

        async updateQuantity(item, nextQuantity, inputEl = null) {
            if (this.busyItemKey !== null || nextQuantity < 1 || !this.canModifyQuantity(item)) {
                if (inputEl) {
                    inputEl.value = item.quantity;
                }
                return;
            }

            this.busyItemKey = item.key;
            this.actionError = '';

            try {
                const response = await fetch(item.update_url, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ quantity: Number(nextQuantity) }),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
                    const message = firstError || data.message || 'Gagal memperbarui kuantitas produk.';
                    this.actionError = message;
                    if (inputEl) {
                        inputEl.value = item.quantity;
                    }
                    this.notify(message, 'error');
                    return;
                }

                if (data.cart) {
                    this.cart = data.cart;
                }
                this.syncHeaderBadge(data.cart_item_count);
                this.notify(data.message || 'Kuantitas produk berhasil diperbarui.', 'success');
            } catch (error) {
                const message = 'Terjadi gangguan jaringan saat memperbarui keranjang.';
                this.actionError = message;
                if (inputEl) {
                    inputEl.value = item.quantity;
                }
                this.notify(message, 'error');
            } finally {
                this.busyItemKey = null;
            }
        },

        async removeItem(item) {
            if (this.busyItemKey !== null) {
                return;
            }

            this.busyItemKey = item.key;
            this.actionError = '';

            try {
                const response = await fetch(item.remove_url, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const message = data.message || 'Gagal menghapus item dari keranjang.';
                    this.actionError = message;
                    this.notify(message, 'error');
                    return;
                }

                if (data.cart) {
                    this.cart = data.cart;
                }
                this.syncHeaderBadge(data.cart_item_count);
                this.notify(data.message || 'Produk berhasil dihapus dari keranjang.', 'success');
            } catch (error) {
                const message = 'Terjadi gangguan jaringan saat menghapus item.';
                this.actionError = message;
                this.notify(message, 'error');
            } finally {
                this.busyItemKey = null;
            }
        },

        async clearCart() {
            if (this.clearing) {
                return;
            }

            this.clearing = true;
            this.actionError = '';

            try {
                const response = await fetch(this.clearUrl, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const message = data.message || 'Gagal mengosongkan keranjang.';
                    this.actionError = message;
                    this.notify(message, 'error');
                    return;
                }

                if (data.cart) {
                    this.cart = data.cart;
                }
                this.confirmClearOpen = false;
                this.syncHeaderBadge(data.cart_item_count ?? 0);
                this.notify(data.message || 'Keranjang belanja berhasil dikosongkan.', 'success');
            } catch (error) {
                const message = 'Terjadi gangguan jaringan saat mengosongkan keranjang.';
                this.actionError = message;
                this.notify(message, 'error');
            } finally {
                this.clearing = false;
            }
        },
    }));
});
</script>
@endpush
