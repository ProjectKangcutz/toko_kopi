@php
    $isEdit = isset($faq) && $faq->exists;
@endphp

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Detail Pertanyaan &amp; Jawaban</h2>

            <div class="mb-3">
                <label for="faqCategory" class="form-label small fw-semibold">Kategori <span class="text-danger">*</span></label>
                <input
                    type="text"
                    id="faqCategory"
                    name="category"
                    list="faqCategoryList"
                    value="{{ old('category', $faq->category ?? 'Umum') }}"
                    class="form-control @error('category') is-invalid @enderror"
                    placeholder="Contoh: Pemesanan, Pembayaran, Pengiriman"
                    required
                >
                <datalist id="faqCategoryList">
                    @foreach($existingCategories as $cat)
                        <option value="{{ $cat }}"></option>
                    @endforeach
                </datalist>
                @error('category')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="faqQuestion" class="form-label small fw-semibold">Pertanyaan <span class="text-danger">*</span></label>
                <input
                    type="text"
                    id="faqQuestion"
                    name="question"
                    value="{{ old('question', $faq->question ?? '') }}"
                    class="form-control @error('question') is-invalid @enderror"
                    placeholder="Contoh: Berapa lama pesanan diproses setelah pembayaran lunas?"
                    required
                >
                @error('question')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div>
                @include('admin.partials.cms-editor', [
                    'id' => 'faqAnswer',
                    'name' => 'answer',
                    'label' => 'Jawaban',
                    'value' => old('answer', $faq->answer ?? ''),
                    'rows' => 8,
                    'required' => true,
                    'placeholder' => 'Tuliskan jawaban yang jelas dan informatif untuk pelanggan...',
                ])
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="mlm-card p-3 p-md-4">
            <h2 class="h5 fw-bold text-mlm-primary mb-3">Pengaturan Tampil</h2>

            <div class="mb-3">
                <label for="faqSortOrder" class="form-label small fw-semibold">Urutan Tampil <span class="text-danger">*</span></label>
                <input
                    type="number"
                    id="faqSortOrder"
                    name="sort_order"
                    min="0"
                    max="10000"
                    value="{{ old('sort_order', $faq->sort_order ?? ($nextOrder ?? 1)) }}"
                    class="form-control @error('sort_order') is-invalid @enderror"
                    required
                >
                @error('sort_order')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text small">Angka lebih kecil tampil lebih dahulu di halaman FAQ.</div>
            </div>

            <div class="form-check form-switch mb-4">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="faqIsActive"
                    name="is_active"
                    value="1"
                    @checked(old('is_active', $faq->is_active ?? true))
                >
                <label class="form-check-label fw-semibold" for="faqIsActive">
                    Aktif (tampilkan di storefront)
                </label>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <span>{{ $isEdit ? 'Simpan Perubahan' : 'Simpan FAQ' }}</span>
                </button>
                <a href="{{ route('admin.faqs.index') }}" class="btn btn-outline-secondary">
                    Batal
                </a>
            </div>
        </div>
    </div>
</div>
