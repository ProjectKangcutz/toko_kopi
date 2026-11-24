@props([
    'storeSettings' => [],
    'storefrontNav' => [],
    'cartItemCount' => 0,
])

@php
    $storeName = $storeSettings['store_name'] ?? 'Kopi Nusantara';
    $headerMenus = collect($storefrontNav['headerMenus'] ?? []);
    $navCategories = collect($storefrontNav['navCategories'] ?? []);
    $catalogUrl = Route::has('storefront.catalog') ? route('storefront.catalog') : url('/produk');
    $cartUrl = Route::has('storefront.cart') ? route('storefront.cart') : url('/keranjang');
@endphp

<header class="bg-mlm-surface border-bottom sticky-top mlm-header">
    @if(!empty($storeSettings['promo_bar_text']))
        <div class="mlm-promo-bar py-2 px-3 text-center">
            <div class="container small fw-medium">{{ $storeSettings['promo_bar_text'] }}</div>
        </div>
    @endif

    <div class="container py-2 py-lg-3">
        <div class="d-flex align-items-center justify-content-between gap-2 gap-lg-4">
            {{-- Mobile Drawer Trigger + Brand Identity --}}
            <div class="d-flex align-items-center gap-2 min-w-0">
                <button
                    class="mlm-icon-btn d-lg-none flex-shrink-0"
                    type="button"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#storefrontMobileDrawer"
                    aria-controls="storefrontMobileDrawer"
                    aria-label="Buka menu navigasi"
                >
                    <i class="bi bi-list fs-5"></i>
                </button>

                <a class="navbar-brand fw-bold text-mlm-primary d-flex align-items-center gap-2 text-decoration-none min-w-0" href="{{ route('storefront.home') }}">
                    @if(!empty($storeSettings['logo_path']))
                        <img
                            src="{{ asset('storage/'.$storeSettings['logo_path']) }}"
                            alt="{{ $storeName }}"
                            class="rounded-2 object-fit-contain flex-shrink-0"
                            style="height: 40px; max-width: 140px;"
                        >
                    @else
                        <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-mlm-secondary text-mlm-primary flex-shrink-0" style="width: 40px; height: 40px;">
                            <i class="bi bi-cup-hot-fill fs-5"></i>
                        </span>
                    @endif
                    <span class="text-truncate fs-5">{{ $storeName }}</span>
                </a>
            </div>

            {{-- Desktop Search Bar --}}
            <form action="{{ $catalogUrl }}" method="GET" role="search" class="d-none d-lg-flex flex-grow-1" style="max-width: 460px;">
                <div class="input-group mlm-search-group w-100">
                    <input
                        type="search"
                        name="q"
                        value="{{ request('q') }}"
                        class="form-control"
                        placeholder="Cari kopi, daerah asal, atau nama produk..."
                        aria-label="Cari produk"
                        maxlength="100"
                    >
                    <button type="submit" class="btn btn-primary px-3 rounded-0" aria-label="Cari">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>

            {{-- Right Actions: Cart & Customer Account --}}
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <a
                    href="{{ $cartUrl }}"
                    class="mlm-icon-btn position-relative px-2 px-sm-3 gap-2"
                    aria-label="Keranjang belanja ({{ $cartItemCount }} item)"
                >
                    <i class="bi bi-bag fs-5"></i>
                    <span class="d-none d-md-inline small fw-semibold">Keranjang</span>
                    <span class="badge rounded-pill mlm-cart-badge">{{ $cartItemCount }}</span>
                </a>

                @auth('customer')
                    <a href="{{ route('customer.dashboard') }}" class="btn btn-outline-primary px-2 px-sm-3">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline">Akun Saya</span>
                    </a>
                @else
                    <a href="{{ route('customer.login') }}" class="btn btn-outline-primary px-2 px-sm-3">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span class="d-none d-sm-inline">Masuk</span>
                    </a>
                @endauth
            </div>
        </div>

        {{-- Mobile Search Bar --}}
        <form action="{{ $catalogUrl }}" method="GET" role="search" class="d-lg-none mt-2">
            <div class="input-group mlm-search-group w-100">
                <input
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control"
                    placeholder="Cari kopi favorit Anda..."
                    aria-label="Cari produk"
                    maxlength="100"
                >
                <button type="submit" class="btn btn-primary px-3 rounded-0" aria-label="Cari">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>

    {{-- Desktop Secondary Navigation Bar: Category Dropdown + Dynamic Menus --}}
    <nav class="border-top bg-mlm-surface d-none d-lg-block" aria-label="Navigasi utama">
        <div class="container">
            <div class="d-flex align-items-center gap-2 py-1">
                @if($navCategories->isNotEmpty())
                    <div class="dropdown">
                        <button
                            class="btn btn-sm bg-mlm-secondary text-mlm-primary fw-semibold dropdown-toggle d-inline-flex align-items-center gap-2 px-3"
                            style="min-height: 40px;"
                            type="button"
                            id="desktopCategoryMenu"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            <i class="bi bi-grid"></i> Jelajahi Kopi
                        </button>
                        <ul class="dropdown-menu shadow-sm border" aria-labelledby="desktopCategoryMenu" style="min-width: 240px;">
                            <li>
                                <a class="dropdown-item fw-semibold py-2" href="{{ $catalogUrl }}">Semua Produk</a>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            @foreach($navCategories as $category)
                                @php
                                    $catUrl = Route::has('storefront.category')
                                        ? route('storefront.category', $category->slug)
                                        : url('/kategori/'.$category->slug);
                                @endphp
                                <li>
                                    <a class="dropdown-item py-2 d-flex justify-content-between align-items-center" href="{{ $catUrl }}">
                                        <span>{{ $category->name }}</span>
                                        @if($category->children->isNotEmpty())
                                            <small class="text-mlm-muted ms-2">({{ $category->children->count() }})</small>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <ul class="nav align-items-center gap-1">
                    @forelse($headerMenus as $menu)
                        @if($menu->children->isNotEmpty())
                            <li class="nav-item dropdown">
                                <a class="nav-link mlm-nav-link dropdown-toggle" href="{{ url($menu->url) }}" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    {{ $menu->label }}
                                </a>
                                <ul class="dropdown-menu shadow-sm border">
                                    @foreach($menu->children as $child)
                                        <li>
                                            <a class="dropdown-item py-2" href="{{ url($child->url) }}" target="{{ $child->target ?? '_self' }}">
                                                {{ $child->label }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @else
                            <li class="nav-item">
                                <a
                                    class="nav-link mlm-nav-link {{ request()->is(ltrim($menu->url, '/') ?: '/') ? 'active' : '' }}"
                                    href="{{ url($menu->url) }}"
                                    target="{{ $menu->target ?? '_self' }}"
                                >
                                    {{ $menu->label }}
                                </a>
                            </li>
                        @endif
                    @empty
                        <li class="nav-item">
                            <a class="nav-link mlm-nav-link {{ request()->routeIs('storefront.home') ? 'active' : '' }}" href="{{ route('storefront.home') }}">Beranda</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link mlm-nav-link" href="{{ $catalogUrl }}">Produk</a>
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </nav>
</header>

{{-- Responsive Mobile Navigation Drawer --}}
<div class="offcanvas offcanvas-start" tabindex="-1" id="storefrontMobileDrawer" aria-labelledby="storefrontMobileDrawerLabel">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold text-mlm-primary mb-0" id="storefrontMobileDrawerLabel">{{ $storeName }}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column gap-3">
        <div>
            <div class="small text-uppercase fw-semibold text-mlm-muted mb-2">Menu Utama</div>
            <div class="d-flex flex-column gap-1">
                @forelse($headerMenus as $menu)
                    <a href="{{ url($menu->url) }}" target="{{ $menu->target ?? '_self' }}" class="mlm-sidebar-link">
                        {{ $menu->label }}
                    </a>
                    @foreach($menu->children as $child)
                        <a href="{{ url($child->url) }}" target="{{ $child->target ?? '_self' }}" class="mlm-sidebar-link ps-4 small">
                            <i class="bi bi-chevron-right small"></i> {{ $child->label }}
                        </a>
                    @endforeach
                @empty
                    <a href="{{ route('storefront.home') }}" class="mlm-sidebar-link">Beranda</a>
                    <a href="{{ $catalogUrl }}" class="mlm-sidebar-link">Katalog Produk</a>
                @endforelse
            </div>
        </div>

        @if($navCategories->isNotEmpty())
            <div class="border-top pt-3">
                <div class="small text-uppercase fw-semibold text-mlm-muted mb-2">Kategori Produk</div>
                <div class="d-flex flex-column gap-1">
                    @foreach($navCategories as $category)
                        @php
                            $catUrl = Route::has('storefront.category')
                                ? route('storefront.category', $category->slug)
                                : url('/kategori/'.$category->slug);
                        @endphp
                        <a href="{{ $catUrl }}" class="mlm-sidebar-link">{{ $category->name }}</a>
                        @foreach($category->children as $subCategory)
                            @php
                                $subCatUrl = Route::has('storefront.category')
                                    ? route('storefront.category', $subCategory->slug)
                                    : url('/kategori/'.$subCategory->slug);
                            @endphp
                            <a href="{{ $subCatUrl }}" class="mlm-sidebar-link ps-4 small text-mlm-muted">
                                <i class="bi bi-dot"></i> {{ $subCategory->name }}
                            </a>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mt-auto border-top pt-3 d-flex flex-column gap-2">
            @auth('customer')
                <a href="{{ route('customer.dashboard') }}" class="btn btn-primary w-100">
                    <i class="bi bi-person-circle"></i> Dashboard Pelanggan
                </a>
            @else
                <a href="{{ route('customer.login') }}" class="btn btn-primary w-100">Masuk Pelanggan</a>
                <a href="{{ route('customer.register') }}" class="btn btn-outline-primary w-100">Daftar Akun</a>
            @endauth
        </div>
    </div>
</div>
