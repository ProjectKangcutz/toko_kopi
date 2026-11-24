@props([
    'storeSettings' => [],
    'storefrontNav' => [],
])

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $footerCol1Menus = collect($storefrontNav['footerCol1Menus'] ?? []);
    $footerCol2Menus = collect($storefrontNav['footerCol2Menus'] ?? []);
    $footerPolicyMenus = collect($storefrontNav['footerPolicyMenus'] ?? []);
    $waNumber = preg_replace('/\D+/', '', (string) ($storeSettings['store_whatsapp'] ?? ''));
    $catalogUrl = Route::has('storefront.catalog') ? route('storefront.catalog') : url('/produk');
@endphp

<footer class="bg-mlm-surface border-top mt-auto pt-5 pb-4">
    <div class="container">
        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    @if(!empty($storeSettings['logo_path']))
                        <img
                            src="{{ asset('storage/'.$storeSettings['logo_path']) }}"
                            alt="{{ $storeName }}"
                            class="rounded-2 object-fit-contain"
                            style="height: 36px; max-width: 120px;"
                        >
                    @endif
                    <div><h5 class="fw-bold text-mlm-primary mb-0">{{ $storeName }}</h5><span class="small text-mlm-muted">Satu Nusantara, Seribu Cerita Kopi.</span></div>
                </div>
                <p class="text-mlm-muted small mb-3">{{ $storeSettings['store_description'] ?? '' }}</p>

                <div class="d-flex flex-column gap-1 small text-mlm-muted">
                    @if(!empty($storeSettings['store_address']))
                        <div><i class="bi bi-geo-alt me-2 text-mlm-primary"></i>{{ $storeSettings['store_address'] }}</div>
                    @endif
                    @if(!empty($storeSettings['store_phone']))
                        <div><i class="bi bi-telephone me-2 text-mlm-primary"></i>{{ $storeSettings['store_phone'] }}</div>
                    @endif
                    @if(!empty($storeSettings['store_email']))
                        <div>
                            <i class="bi bi-envelope me-2 text-mlm-primary"></i>
                            <a href="mailto:{{ $storeSettings['store_email'] }}" class="text-decoration-none text-mlm-muted">{{ $storeSettings['store_email'] }}</a>
                        </div>
                    @endif
                    @if($waNumber !== '')
                        <div>
                            <i class="bi bi-whatsapp me-2 text-mlm-primary"></i>
                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-mlm-muted">WhatsApp Layanan Pelanggan</a>
                        </div>
                    @endif
                    @if(!empty($storeSettings['store_hours']))
                        <div><i class="bi bi-clock me-2 text-mlm-primary"></i>{{ $storeSettings['store_hours'] }}</div>
                    @endif
                </div>

                @php
                    $socialLinks = array_filter([
                        'Instagram' => ['url' => \App\Services\Storefront\HomepageService::sanitizeUrl($storeSettings['social_instagram'] ?? null), 'icon' => 'bi-instagram'],
                        'Facebook' => ['url' => \App\Services\Storefront\HomepageService::sanitizeUrl($storeSettings['social_facebook'] ?? null), 'icon' => 'bi-facebook'],
                        'TikTok' => ['url' => \App\Services\Storefront\HomepageService::sanitizeUrl($storeSettings['social_tiktok'] ?? null), 'icon' => 'bi-tiktok'],
                        'YouTube' => ['url' => \App\Services\Storefront\HomepageService::sanitizeUrl($storeSettings['social_youtube'] ?? null), 'icon' => 'bi-youtube'],
                    ], fn ($item) => !empty($item['url']));
                @endphp
                @if(count($socialLinks) > 0)
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        @foreach($socialLinks as $label => $item)
                            <a
                                href="{{ $item['url'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                aria-label="{{ $label }}"
                            >
                                <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                                <span>{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <h6 class="fw-semibold mb-3">Jelajah Toko</h6>
                <ul class="list-unstyled small mb-0">
                    @forelse($footerCol1Menus as $menu)
                        <li><a href="{{ url($menu->url) }}" target="{{ $menu->target ?? '_self' }}" class="mlm-footer-link">{{ $menu->label }}</a></li>
                    @empty
                        <li><a href="{{ route('storefront.home') }}" class="mlm-footer-link">Beranda</a></li>
                        <li><a href="{{ $catalogUrl }}" class="mlm-footer-link">Katalog Produk</a></li>
                        <li><a href="{{ url('/artikel') }}" class="mlm-footer-link">Artikel &amp; Panduan</a></li>
                    @endforelse
                </ul>
            </div>

            <div class="col-6 col-md-4 col-lg-3">
                <h6 class="fw-semibold mb-3">Layanan Pelanggan</h6>
                <ul class="list-unstyled small mb-0">
                    @forelse($footerCol2Menus as $menu)
                        <li><a href="{{ url($menu->url) }}" target="{{ $menu->target ?? '_self' }}" class="mlm-footer-link">{{ $menu->label }}</a></li>
                    @empty
                        <li><a href="{{ url('/tentang-kami') }}" class="mlm-footer-link">Tentang Kami</a></li>
                        <li><a href="{{ url('/faq') }}" class="mlm-footer-link">FAQ</a></li>
                        <li><a href="{{ url('/kontak') }}" class="mlm-footer-link">Hubungi Kami</a></li>
                        <li><a href="{{ route('customer.login') }}" class="mlm-footer-link">Akun Pelanggan</a></li>
                    @endforelse
                </ul>
            </div>

            <div class="col-12 col-md-4 col-lg-3">
                <h6 class="fw-semibold mb-3">Kebijakan Toko</h6>
                <ul class="list-unstyled small mb-2">
                    @forelse($footerPolicyMenus as $menu)
                        <li><a href="{{ url($menu->url) }}" target="{{ $menu->target ?? '_self' }}" class="mlm-footer-link">{{ $menu->label }}</a></li>
                    @empty
                        @unless($storefrontNav['hasManagedFooterPages'] ?? false)
                            <li><a href="{{ url('/kebijakan-privasi') }}" class="mlm-footer-link">Kebijakan Privasi</a></li>
                            <li><a href="{{ url('/syarat-ketentuan') }}" class="mlm-footer-link">Syarat &amp; Ketentuan</a></li>
                            <li><a href="{{ url('/kebijakan-pengiriman') }}" class="mlm-footer-link">Kebijakan Pengiriman</a></li>
                            <li><a href="{{ url('/kebijakan-pengembalian') }}" class="mlm-footer-link">Kebijakan Pengembalian</a></li>
                        @else
                            <li class="text-mlm-muted">Tidak ada halaman kebijakan aktif.</li>
                        @endunless
                    @endforelse
                </ul>
            </div>
        </div>

        <hr class="my-4">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 small text-mlm-muted">
            <span>&copy; {{ date('Y') }} {{ $storeName }}. Seluruh hak cipta dilindungi.</span>
            <span>Pembayaran aman melalui Midtrans &middot; Pengiriman ke seluruh Indonesia</span>
        </div>
    </div>
</footer>
