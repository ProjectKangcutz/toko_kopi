@php
    /** @var \App\Models\CustomerAddress|null $address */
    $address = $address ?? null;
    $isEdit = $address !== null;

    $initialValues = [
        'province_id' => (string) old('province_id', $address?->province_id ?? ''),
        'province_name' => (string) old('province_name', $address?->province_name ?? ''),
        'city_id' => (string) old('city_id', $address?->city_id ?? ''),
        'city_name' => (string) old('city_name', $address?->city_name ?? ''),
        'district_id' => (string) old('district_id', $address?->district_id ?? ''),
        'district_name' => (string) old('district_name', $address?->district_name ?? ''),
        'subdistrict_id' => (string) old('subdistrict_id', $address?->subdistrict_id ?? ''),
        'subdistrict_name' => (string) old('subdistrict_name', $address?->subdistrict_name ?? ''),
        'postal_code' => (string) old('postal_code', $address?->postal_code ?? ''),
    ];

    $hasRegionValidationErrors = $errors->hasAny([
        'province_id',
        'province_name',
        'city_id',
        'city_name',
        'district_id',
        'district_name',
        'subdistrict_id',
        'subdistrict_name',
    ]);

    $isPrimaryChecked = (bool) old(
        'is_primary',
        $address ? $address->is_primary : ! ($hasAddresses ?? false)
    );
@endphp

<div
    x-data="addressRegionForm({
        searchUrl: @js(route('customer.regions.search')),
        initial: @js($initialValues),
        hasRegionErrors: @js($hasRegionValidationErrors)
    })"
>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <x-form.input
                name="label"
                label="Label Alamat"
                :value="$address?->label"
                placeholder="Contoh: Rumah, Kantor, Kos"
                maxlength="60"
                required
            />
        </div>

        <div class="col-12 col-md-4">
            <x-form.input
                name="recipient_name"
                label="Nama Penerima"
                :value="$address?->recipient_name ?? auth('customer')->user()?->name"
                placeholder="Nama lengkap penerima paket"
                maxlength="255"
                required
            />
        </div>

        <div class="col-12 col-md-4">
            <x-form.input
                name="recipient_phone"
                label="Nomor Telepon Penerima"
                type="tel"
                :value="$address?->recipient_phone ?? auth('customer')->user()?->phone"
                placeholder="Contoh: 081234567890"
                autocomplete="tel"
                required
            />
        </div>
    </div>

    <div class="border rounded-3 p-3 p-md-4 bg-light-subtle mb-3">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 mb-3">
            <div>
                <h3 class="h6 fw-bold mb-1">Wilayah Pengiriman <span class="text-danger">*</span></h3>
                <p class="small text-mlm-muted mb-0">
                    Cari kelurahan, kecamatan, kota, atau kode pos untuk mengisi wilayah otomatis.
                </p>
            </div>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm flex-shrink-0"
                style="min-height: 44px;"
                @click="toggleManualMode()"
                x-text="manualMode ? 'Tutup Input Manual' : 'Isi Wilayah Secara Manual'"
            ></button>
        </div>

        @if($hasRegionValidationErrors)
            <div class="alert alert-danger py-2 small mb-3" role="alert">
                Silakan pilih wilayah dari hasil pencarian atau lengkapi kolom Provinsi dan Kota/Kabupaten secara manual.
            </div>
        @endif

        <div class="mb-3">
            <label for="region_keyword" class="form-label fw-medium">Cari Wilayah</label>
            <div class="d-flex flex-column flex-sm-row gap-2">
                <input
                    type="search"
                    id="region_keyword"
                    class="form-control"
                    x-model="keyword"
                    @keydown.enter.prevent="searchRegions()"
                    placeholder="Ketik kecamatan, kelurahan, kota, atau kode pos (misal: Sumur Bandung / 40111)"
                    autocomplete="off"
                >
                <button
                    type="button"
                    class="btn btn-primary px-4 flex-shrink-0"
                    :disabled="loading"
                    @click="searchRegions()"
                >
                    <span x-show="!loading">
                        <span class="d-inline-flex align-items-center gap-2">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <span>Cari Wilayah</span>
                        </span>
                    </span>
                    <span x-show="loading" x-cloak>
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                            <span>Mencari...</span>
                        </span>
                    </span>
                </button>
            </div>
            <div class="form-text">Minimal 2 karakter. Tekan Enter atau klik Cari Wilayah.</div>
        </div>

        <div x-show="searchError" x-cloak class="alert alert-warning small mb-3" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
                <div>
                    <div class="fw-semibold" x-text="searchError"></div>
                    <div class="mt-1">Anda tetap dapat mengisi Provinsi, Kota/Kabupaten, Kecamatan, Kelurahan, dan Kode Pos pada kolom manual di bawah.</div>
                </div>
            </div>
        </div>

        <div x-show="searched && !loading && !searchError && results.length === 0" x-cloak class="alert alert-info small mb-3" role="status">
            Wilayah tidak ditemukan untuk kata kunci tersebut. Coba kata kunci lain atau gunakan tombol <strong>Isi Wilayah Secara Manual</strong>.
        </div>

        <div x-show="results.length > 0" x-cloak class="mb-3">
            <div class="small fw-semibold text-mlm-muted mb-2">
                Pilih salah satu hasil wilayah (<span x-text="results.length"></span> ditemukan):
            </div>
            <div class="mlm-region-results" role="listbox" aria-label="Hasil pencarian wilayah">
                <template x-for="item in results" :key="item.id + '-' + item.label">
                    <button
                        type="button"
                        class="mlm-region-option"
                        @click="selectRegion(item)"
                    >
                        <div class="fw-semibold small" x-text="item.label"></div>
                        <div class="text-mlm-muted" style="font-size: 0.78rem;">
                            <span x-text="[item.subdistrict_name, item.district_name, item.city_name, item.province_name].filter(Boolean).join(' • ')"></span>
                            <template x-if="item.zip_code">
                                <span class="ms-1 badge text-bg-light border" x-text="item.zip_code"></span>
                            </template>
                        </div>
                    </button>
                </template>
            </div>
        </div>

        <div x-show="hasSelectedRegion" x-cloak class="mlm-region-summary mb-3">
            <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                <div>
                    <div class="small fw-semibold text-mlm-primary mb-1">
                        <i class="bi bi-check-circle-fill me-1"></i> Wilayah Terpilih
                    </div>
                    <div class="small fw-medium" x-text="formattedSelectedRegion"></div>
                </div>
                <span class="badge bg-white text-mlm-primary border align-self-start align-self-sm-center"
                      x-text="subdistrict_id ? 'ID Wilayah: ' + subdistrict_id : 'Input Manual'"></span>
            </div>
        </div>

        <input type="hidden" name="province_id" x-model="province_id">
        <input type="hidden" name="city_id" x-model="city_id">
        <input type="hidden" name="district_id" x-model="district_id">
        <input type="hidden" name="subdistrict_id" x-model="subdistrict_id">

        <div x-show="manualMode" x-cloak class="pt-3 mt-2 border-top">
            <div class="small fw-semibold text-mlm-muted mb-3">
                Detail Wilayah (Manual / Penyesuaian)
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <x-form.input
                        name="province_name"
                        label="Provinsi"
                        :value="$initialValues['province_name']"
                        placeholder="Contoh: Jawa Barat"
                        maxlength="100"
                        x-model="province_name"
                        @input="markAsManual()"
                        x-bind:required="manualMode"
                    />
                </div>
                <div class="col-12 col-md-6">
                    <x-form.input
                        name="city_name"
                        label="Kota / Kabupaten"
                        :value="$initialValues['city_name']"
                        placeholder="Contoh: Kota Bandung"
                        maxlength="120"
                        x-model="city_name"
                        @input="markAsManual()"
                        x-bind:required="manualMode"
                    />
                </div>
                <div class="col-12 col-md-6">
                    <x-form.input
                        name="district_name"
                        label="Kecamatan"
                        :value="$initialValues['district_name']"
                        placeholder="Contoh: Sumur Bandung"
                        maxlength="120"
                        x-model="district_name"
                        @input="markAsManual()"
                    />
                </div>
                <div class="col-12 col-md-6">
                    <x-form.input
                        name="subdistrict_name"
                        label="Kelurahan / Desa"
                        :value="$initialValues['subdistrict_name']"
                        placeholder="Contoh: Braga"
                        maxlength="120"
                        x-model="subdistrict_name"
                        @input="markAsManual()"
                    />
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-8">
            <x-form.textarea
                name="address_line"
                label="Alamat Lengkap"
                :value="$address?->address_line"
                rows="3"
                placeholder="Nama jalan, nomor rumah, blok, RT/RW, dan patokan lokasi"
                maxlength="2000"
                required
            />
        </div>

        <div class="col-12 col-md-4">
            <x-form.input
                name="postal_code"
                label="Kode Pos"
                :value="$initialValues['postal_code']"
                placeholder="5 digit angka (contoh: 40111)"
                inputmode="numeric"
                maxlength="5"
                autocomplete="postal-code"
                x-model="postal_code"
                required
            />
        </div>
    </div>

    <div class="mb-4">
        <input type="hidden" name="is_primary" value="0">
        <div class="form-check">
            <input
                class="form-check-input"
                type="checkbox"
                value="1"
                id="is_primary"
                name="is_primary"
                @if($isPrimaryChecked) checked @endif
            >
            <label class="form-check-label fw-medium" for="is_primary">
                Jadikan sebagai alamat pengiriman utama
            </label>
        </div>
        @if(! $isEdit && ! ($hasAddresses ?? false))
            <div class="form-text">Alamat pertama yang Anda simpan otomatis ditetapkan sebagai alamat utama.</div>
        @elseif($isEdit && $address->is_primary && ! ($hasOtherAddresses ?? true))
            <div class="form-text">Alamat ini adalah satu-satunya alamat aktif Anda sehingga status alamat utama tetap dipertahankan.</div>
        @endif
    </div>

    <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 pt-3 border-top">
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check2-circle"></i> {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Alamat' }}
        </button>
        <a href="{{ route('customer.addresses.index') }}" class="btn btn-outline-secondary px-4 d-inline-flex align-items-center justify-content-center" style="min-height: 44px;">
            Batal
        </a>
    </div>
</div>
