@extends('layouts.admin')

@section('title', 'Inventori & Stok')

@section('content')
@php
    $initialTargetKey = old('product_id')
        ? ((int) old('product_id').':'.(int) (old('product_variant_id') ?? 0))
        : ($adjustableTargets->first()['key'] ?? '');
    $adjustableTargetMap = $adjustableTargets->keyBy('key')->all();
    $shouldReopenModal = $errors->hasAny(['product_id', 'product_variant_id', 'reason', 'quantity_change', 'notes', 'error']);
@endphp

<x-breadcrumb :items="[
    ['label' => 'Admin Dashboard', 'url' => route('admin.dashboard')],
    ['label' => 'Inventori & Stok'],
]" />

<div
    x-data="{
        adjustModalOpen: @js($shouldReopenModal),
        targetKey: @js($initialTargetKey),
        quantityChange: @js((string) old('quantity_change', '')),
        targets: @js($adjustableTargetMap),
        get parsedProductId() {
            if (!this.targetKey) return '';
            return this.targetKey.split(':')[0] || '';
        },
        get parsedVariantId() {
            if (!this.targetKey) return '';
            const v = this.targetKey.split(':')[1] || '0';
            return v === '0' ? '' : v;
        },
        get selectedTarget() {
            return this.targetKey && this.targets[this.targetKey] ? this.targets[this.targetKey] : null;
        },
        get projectedStock() {
            if (!this.selectedTarget) return null;
            const delta = parseInt(this.quantityChange, 10);
            if (Number.isNaN(delta)) return this.selectedTarget.stock;
            return this.selectedTarget.stock + delta;
        },
        openAdjustModal(key = null) {
            if (key) {
                this.targetKey = key;
            }
            this.adjustModalOpen = true;
            this.$nextTick(() => {
                const qtyInput = document.getElementById('adjustQtyChange');
                if (qtyInput && key) qtyInput.focus();
            });
        },
        closeAdjustModal() {
            this.adjustModalOpen = false;
        }
    }"
>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-mlm-primary mb-1">Inventori &amp; Stok</h1>
            <p class="small text-mlm-muted mb-0">Pantau ringkasan stok SKU, reservasi aktif checkout, riwayat mutasi, dan penyesuaian stok manual dengan audit trail.</p>
        </div>

        @if($admin->hasPermission('inventory.adjust'))
            <button
                type="button"
                class="btn btn-primary fw-semibold d-inline-flex align-items-center gap-2 align-self-start align-self-md-auto"
                style="min-height: 44px;"
                @click="openAdjustModal()"
            >
                <i class="bi bi-sliders" aria-hidden="true"></i>
                <span>Penyesuaian Stok Manual</span>
            </button>
        @endif
    </div>

    {{-- Ringkasan Statistik --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="mlm-card p-3 h-100">
                <div class="small text-mlm-muted">Total SKU Dipantau</div>
                <div class="h4 fw-bold text-mlm-primary mb-0">{{ $summaryStats['total_sku'] }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mlm-card p-3 h-100">
                <div class="small text-mlm-muted">Stok Menipis</div>
                <div class="h4 fw-bold text-warning mb-0">{{ $summaryStats['low_stock_sku'] }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mlm-card p-3 h-100">
                <div class="small text-mlm-muted">Stok Tersedia Habis</div>
                <div class="h4 fw-bold text-danger mb-0">{{ $summaryStats['out_of_stock_sku'] }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="mlm-card p-3 h-100">
                <div class="small text-mlm-muted">Unit Direservasi</div>
                <div class="h4 fw-bold text-mlm-primary mb-0">{{ $summaryStats['total_reserved_units'] }}</div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column gap-4">
        {{-- Ringkasan Stok SKU --}}
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Ringkasan Stok Produk &amp; Varian</h2>

            <form method="GET" action="{{ route('admin.inventory.index') }}" class="row g-3 align-items-end mb-4">
                <div class="col-12 col-md-5">
                    <label for="invSearch" class="form-label small fw-semibold">Cari Produk / SKU</label>
                    <input
                        type="text"
                        id="invSearch"
                        name="search"
                        value="{{ $search }}"
                        class="form-control"
                        style="min-height: 44px;"
                        placeholder="SKU atau nama produk..."
                    >
                </div>
                <div class="col-12 col-md-4">
                    <label for="invFilter" class="form-label small fw-semibold">Filter Kondisi</label>
                    <select id="invFilter" name="filter" class="form-select" style="min-height: 44px;">
                        <option value="all" @selected($filter === 'all')>Semua SKU</option>
                        <option value="low" @selected($filter === 'low')>Stok Menipis (&le; Min. Stok)</option>
                        <option value="out" @selected($filter === 'out')>Stok Tersedia Habis (0)</option>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold" style="min-height: 44px;">
                        <i class="bi bi-search me-1" aria-hidden="true"></i> Filter
                    </button>
                    @if($search !== '' || $filter !== 'all')
                        <a href="{{ route('admin.inventory.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="min-height: 44px; min-width: 44px;" aria-label="Reset filter">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </form>

            @if($stockItems->isEmpty())
                <x-empty-state icon="bi-archive" title="Tidak Ada SKU Ditemukan" message="Belum ada data SKU produk yang cocok dengan filter saat ini." />
            @else
                <x-table :headers="['SKU', 'Produk / Varian', 'Fisik', 'Reservasi', 'Tersedia', 'Status', 'Aksi']">
                    @foreach($stockItems as $item)
                        <tr>
                            <td data-label="SKU" class="font-monospace small text-nowrap">{{ $item['sku'] }}</td>
                            <td data-label="Produk / Varian">
                                <div class="fw-semibold">{{ $item['name'] }}</div>
                                @if(! empty($item['variant_name']))
                                    <div class="small text-mlm-primary">Varian: {{ $item['variant_name'] }}</div>
                                @endif
                            </td>
                            <td data-label="Fisik" class="fw-semibold">{{ $item['stock'] }}</td>
                            <td data-label="Reservasi">{{ $item['reserved_stock'] }}</td>
                            <td data-label="Tersedia" class="fw-bold {{ $item['available_stock'] <= 0 ? 'text-danger' : ($item['stock'] <= $item['min_stock'] ? 'text-warning' : 'text-mlm-primary') }}">
                                {{ $item['available_stock'] }}
                                <div class="small fw-normal text-mlm-muted">Min: {{ $item['min_stock'] }}</div>
                            </td>
                            <td data-label="Status" class="text-nowrap">
                                @if($item['available_stock'] <= 0)
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1">Habis</span>
                                @elseif($item['stock'] <= $item['min_stock'])
                                    <span class="badge bg-warning-subtle text-warning-emphasis px-2 py-1">Menipis</span>
                                @else
                                    <span class="badge bg-success-subtle text-success px-2 py-1">Aman</span>
                                @endif
                            </td>
                            <td data-label="Aksi" class="text-nowrap">
                                @if($admin->hasPermission('inventory.adjust'))
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 px-3"
                                        style="min-height: 40px;"
                                        @click="openAdjustModal('{{ $item['target_key'] }}')"
                                    >
                                        <i class="bi bi-sliders" aria-hidden="true"></i> Sesuaikan
                                    </button>
                                @else
                                    <span class="small text-mlm-muted">Read-only</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-table>

                <x-pagination :paginator="$stockItems" />
            @endif
        </section>

        {{-- Penyesuaian Manual Terbaru --}}
        @if($admin->hasPermission('inventory.adjust'))
            <section class="mlm-card p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                    <h2 class="h6 fw-bold text-mlm-primary mb-0">Penyesuaian Manual Terbaru</h2>
                    <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">{{ $adjustments->count() }} catatan terbaru</span>
                </div>

                @if($adjustments->isEmpty())
                    <p class="small text-mlm-muted mb-0">Belum ada penyesuaian stok manual.</p>
                @else
                    <x-table :headers="['Nomor', 'Produk / Varian', 'Perubahan', 'Alasan & Keterangan', 'Admin', 'Waktu']">
                        @foreach($adjustments as $adj)
                            <tr>
                                <td data-label="Nomor" class="font-monospace small fw-semibold text-nowrap">{{ $adj->adjustment_number }}</td>
                                <td data-label="Produk / Varian" class="small">
                                    <div class="fw-semibold">{{ $adj->product?->name ?? '-' }}</div>
                                    @if($adj->variant)
                                        <div class="text-mlm-muted">{{ $adj->variant->name }}</div>
                                    @endif
                                </td>
                                <td data-label="Perubahan" class="fw-bold text-nowrap {{ $adj->quantity_change > 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $adj->quantity_change > 0 ? '+'.$adj->quantity_change : $adj->quantity_change }}
                                </td>
                                <td data-label="Alasan &amp; Keterangan" class="small">
                                    <span class="badge bg-light text-dark border me-1">{{ $reasons[$adj->reason] ?? $adj->reason }}</span>
                                    <span class="text-mlm-muted">{{ $adj->notes }}</span>
                                </td>
                                <td data-label="Admin" class="small text-mlm-muted">{{ $adj->adjuster?->name ?? '-' }}</td>
                                <td data-label="Waktu" class="small text-mlm-muted text-nowrap">
                                    {{ $adj->adjusted_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </section>
        @endif

        {{-- Reservasi Stok Aktif --}}
        <section class="mlm-card p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
                <h2 class="h6 fw-bold text-mlm-primary mb-0">Reservasi Stok Aktif (Checkout / Menunggu Bayar)</h2>
                <span class="badge rounded-pill bg-mlm-secondary text-mlm-primary">{{ $activeReservations->total() }} reservasi</span>
            </div>

            @if($activeReservations->isEmpty())
                <p class="small text-mlm-muted mb-0">Tidak ada reservasi stok aktif saat ini.</p>
            @else
                <x-table :headers="['No. Order', 'Produk / Varian', 'Qty', 'Status Order', 'Kedaluwarsa']">
                    @foreach($activeReservations as $res)
                        <tr>
                            <td data-label="No. Order" class="fw-semibold">
                                @if($res->order)
                                    <a href="{{ route('admin.orders.show', $res->order) }}" class="text-decoration-none text-mlm-primary">
                                        {{ $res->order->order_number }}
                                    </a>
                                @else
                                    -
                                @endif
                            </td>
                            <td data-label="Produk / Varian" class="small">
                                <div class="fw-semibold">{{ $res->product?->name ?? 'Produk Terarsip' }}</div>
                                @if($res->variant)
                                    <div class="text-mlm-muted">Varian: {{ $res->variant->name }}</div>
                                @endif
                            </td>
                            <td data-label="Qty" class="fw-bold">{{ $res->quantity }}</td>
                            <td data-label="Status Order">
                                @if($res->order)
                                    <x-status-badge :status="$res->order->order_status" />
                                @else
                                    -
                                @endif
                            </td>
                            <td data-label="Kedaluwarsa" class="small text-mlm-muted">
                                {{ $res->expires_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                            </td>
                        </tr>
                    @endforeach
                </x-table>

                <x-pagination :paginator="$activeReservations" />
            @endif
        </section>

        {{-- Daftar Mutasi Stok --}}
        <section class="mlm-card p-3 p-md-4">
            <h2 class="h6 fw-bold text-mlm-primary pb-2 mb-3 border-bottom">Riwayat Mutasi Stok (Stock Movements)</h2>

            @if($movements->isEmpty())
                <p class="small text-mlm-muted mb-0">Belum ada catatan mutasi stok.</p>
            @else
                <x-table :headers="['Waktu', 'Produk / Varian', 'Tipe', 'Perubahan', 'Stok', 'Keterangan', 'Aktor']">
                    @foreach($movements as $movement)
                        <tr>
                            <td data-label="Waktu" class="small text-mlm-muted text-nowrap">
                                {{ $movement->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                            </td>
                            <td data-label="Produk / Varian" class="small">
                                <div class="fw-semibold">{{ $movement->product?->name ?? '-' }}</div>
                                @if($movement->variant)
                                    <div class="text-mlm-muted">{{ $movement->variant->name }}</div>
                                @endif
                            </td>
                            <td data-label="Tipe">
                                <span class="badge bg-light text-dark border text-uppercase">{{ $movement->type }}</span>
                            </td>
                            <td data-label="Perubahan" class="fw-bold {{ $movement->quantity > 0 ? 'text-success' : 'text-danger' }}">
                                {{ $movement->quantity > 0 ? '+'.$movement->quantity : $movement->quantity }}
                            </td>
                            <td data-label="Stok" class="small text-nowrap">
                                {{ $movement->stock_before }} &rarr; <strong>{{ $movement->stock_after }}</strong>
                            </td>
                            <td data-label="Keterangan" class="small">{{ $movement->notes ?: '-' }}</td>
                            <td data-label="Aktor" class="small text-mlm-muted">{{ $movement->creator?->name ?? 'Sistem' }}</td>
                        </tr>
                    @endforeach
                </x-table>

                <x-pagination :paginator="$movements" />
            @endif
        </section>
    </div>

    {{-- Pop-up Modal Penyesuaian Stok Manual --}}
    @if($admin->hasPermission('inventory.adjust'))
        <div
            x-show="adjustModalOpen"
            x-cloak
            :class="{ 'd-block show': adjustModalOpen }"
            @click.self="closeAdjustModal()"
            @keydown.escape.window="closeAdjustModal()"
            class="modal fade"
            style="background: rgba(36, 43, 39, 0.5);"
            role="dialog"
            aria-modal="true"
            aria-labelledby="stockAdjustmentModalTitle"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header border-bottom">
                        <div>
                            <h2 class="modal-title h5 fw-bold text-mlm-primary mb-0" id="stockAdjustmentModalTitle">Penyesuaian Stok Manual</h2>
                            <div class="small text-mlm-muted">Tercatat atomik di StockAdjustment &amp; StockMovement</div>
                        </div>
                        <button type="button" class="btn-close" @click="closeAdjustModal()" aria-label="Tutup"></button>
                    </div>

                    <form id="stockAdjustmentForm" method="POST" action="{{ route('admin.inventory.adjust') }}">
                        @csrf
                        <input type="hidden" name="product_id" :value="parsedProductId">
                        <input type="hidden" name="product_variant_id" :value="parsedVariantId">

                        <div class="modal-body d-flex flex-column gap-3">
                            @if($errors->has('error') || $errors->has('product_id') || $errors->has('product_variant_id'))
                                <div class="alert alert-danger small mb-0" role="alert">
                                    {{ $errors->first('error') ?: ($errors->first('product_id') ?: $errors->first('product_variant_id')) }}
                                </div>
                            @endif

                            <div>
                                <label for="adjustTargetSelect" class="form-label small fw-semibold">Pilih Produk / Varian <span class="text-danger">*</span></label>
                                <select id="adjustTargetSelect" x-model="targetKey" class="form-select" style="min-height: 44px;" required>
                                    <option value="">-- Pilih Target SKU --</option>
                                    @foreach($adjustableTargets as $target)
                                        <option value="{{ $target['key'] }}">{{ $target['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="rounded-3 bg-mlm-secondary p-3 small" x-show="selectedTarget">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold text-mlm-primary" x-text="selectedTarget ? selectedTarget.name : ''"></span>
                                    <span class="font-monospace text-mlm-muted" x-text="selectedTarget ? selectedTarget.sku : ''"></span>
                                </div>
                                <div class="d-flex flex-wrap gap-3 text-mlm-muted">
                                    <span>Fisik saat ini: <strong class="text-dark" x-text="selectedTarget ? selectedTarget.stock : 0"></strong></span>
                                    <span>Direservasi: <strong class="text-dark" x-text="selectedTarget ? selectedTarget.reserved_stock : 0"></strong></span>
                                    <span>
                                        Perkiraan fisik akhir:
                                        <strong
                                            :class="(projectedStock !== null && selectedTarget && projectedStock < selectedTarget.reserved_stock) ? 'text-danger' : 'text-mlm-primary'"
                                            x-text="projectedStock !== null ? projectedStock : '-'"
                                        ></strong>
                                    </span>
                                </div>
                            </div>

                            <div>
                                <label for="adjustReason" class="form-label small fw-semibold">Alasan Penyesuaian <span class="text-danger">*</span></label>
                                <select id="adjustReason" name="reason" class="form-select @error('reason') is-invalid @enderror" style="min-height: 44px;" required>
                                    <option value="">-- Pilih Alasan --</option>
                                    @foreach($reasons as $reasonKey => $reasonLabel)
                                        <option value="{{ $reasonKey }}" @selected(old('reason') === $reasonKey)>{{ $reasonLabel }}</option>
                                    @endforeach
                                </select>
                                @error('reason')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label for="adjustQtyChange" class="form-label small fw-semibold">Perubahan Jumlah (+/-) <span class="text-danger">*</span></label>
                                <input
                                    type="number"
                                    id="adjustQtyChange"
                                    name="quantity_change"
                                    x-model="quantityChange"
                                    required
                                    class="form-control @error('quantity_change') is-invalid @enderror"
                                    style="min-height: 44px;"
                                    placeholder="Contoh: 10 atau -2"
                                >
                                <div class="form-text small">Gunakan angka positif untuk menambah stok, angka negatif (misal -3) untuk mengurangi stok. Stok tidak boleh negatif atau lebih kecil dari stok yang direservasi.</div>
                                @error('quantity_change')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div>
                                <label for="adjustNotes" class="form-label small fw-semibold">Keterangan Wajib <span class="text-danger">*</span></label>
                                <textarea
                                    id="adjustNotes"
                                    name="notes"
                                    rows="3"
                                    required
                                    minlength="3"
                                    maxlength="500"
                                    class="form-control @error('notes') is-invalid @enderror"
                                    placeholder="Jelaskan alasan fisik penyesuaian stok (nomor batch, hasil opname, kerusakan, dll)..."
                                >{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="modal-footer border-top">
                            <button type="button" class="btn btn-outline-secondary" style="min-height: 44px;" @click="closeAdjustModal()">
                                Batal
                            </button>
                            <button type="submit" class="btn btn-primary fw-semibold px-4" style="min-height: 44px;">
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Simpan Penyesuaian Stok
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
