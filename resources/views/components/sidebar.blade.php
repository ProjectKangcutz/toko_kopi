@props(['role' => 'customer'])

@if($role === 'admin')
    @php
        $adminUser = auth('admin')->user();
    @endphp
    <div class="d-flex flex-column h-100">
        <div class="mb-4 px-2">
            <a href="{{ route('admin.dashboard') }}" class="text-decoration-none d-flex align-items-center gap-2">
                <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                    <i class="bi bi-grid-1x2-fill"></i>
                </span>
                <div>
                    <div class="fw-bold text-mlm-primary lh-sm">Admin UMKM</div>
                    <small class="text-mlm-muted">{{ $adminUser?->roles->first()?->name ?? 'Administrator' }}</small>
                </div>
            </a>
        </div>

        <nav class="d-flex flex-column gap-1 flex-grow-1">
            @if($adminUser && $adminUser->hasPermission('dashboard.view'))
                <a href="{{ route('admin.dashboard') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i> Ringkasan Dashboard
                </a>
            @endif
            <div class="px-3 pt-3 pb-1 text-uppercase small fw-semibold text-mlm-muted">Katalog &amp; Operasional</div>
            @if($adminUser && $adminUser->hasPermission('products.view'))
                <a href="{{ route('admin.products.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                    <i class="bi bi-box-seam"></i> Produk
                </a>
            @endif
            @if($adminUser && $adminUser->hasPermission('categories.view'))
                <a href="{{ route('admin.categories.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                    <i class="bi bi-tags"></i> Kategori
                </a>
            @endif
            @if($adminUser && $adminUser->hasPermission('inventory.view'))
                <a href="{{ route('admin.inventory.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.inventory.*') ? 'active' : '' }}">
                    <i class="bi bi-archive"></i> Inventori &amp; Stok
                </a>
            @endif
            @if($adminUser && $adminUser->hasPermission('orders.view'))
                <a href="{{ route('admin.orders.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                    <i class="bi bi-receipt"></i> Pesanan &amp; Pengiriman
                </a>
            @endif
            @if($adminUser && $adminUser->hasPermission('settings.view'))
                <a href="{{ route('admin.settings.edit') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                    <i class="bi bi-gear"></i> Pengaturan Toko
                </a>
            @endif
            @if($adminUser && $adminUser->hasPermission('content.view'))
                <div class="px-3 pt-3 pb-1 text-uppercase small fw-semibold text-mlm-muted">Konten &amp; Halaman</div>
                <a href="{{ route('admin.articles.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.articles.*') ? 'active' : '' }}">
                    <i class="bi bi-journal-richtext"></i> Artikel
                </a>
                <a href="{{ route('admin.pages.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.pages.*') ? 'active' : '' }}">
                    <i class="bi bi-file-earmark-text"></i> Halaman &amp; Kebijakan
                </a>
                <a href="{{ route('admin.about.edit') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.about.*') ? 'active' : '' }}">
                    <i class="bi bi-building"></i> Tentang Kami
                </a>
                <a href="{{ route('admin.faqs.index') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.faqs.*') ? 'active' : '' }}">
                    <i class="bi bi-question-circle"></i> FAQ
                </a>
                <a href="{{ route('admin.contact.edit') }}" class="mlm-sidebar-link {{ request()->routeIs('admin.contact.*') ? 'active' : '' }}">
                    <i class="bi bi-telephone-inbound"></i> Kontak
                </a>
            @endif
            <a href="{{ route('storefront.home') }}" class="mlm-sidebar-link mt-2 border-top pt-3">
                <i class="bi bi-shop"></i> Lihat Toko
            </a>
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}" class="mt-3 pt-3 border-top">
            @csrf
            <button type="submit" class="btn btn-outline-danger w-100">
                <i class="bi bi-box-arrow-left"></i> Keluar Admin
            </button>
        </form>
    </div>
@else
    <div class="d-flex flex-column h-100">
        <div class="mb-4 px-2">
            <a href="{{ route('customer.dashboard') }}" class="text-decoration-none d-flex align-items-center gap-2">
                <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center" style="width:36px;height:36px;">
                    <i class="bi bi-person-badge"></i>
                </span>
                <div>
                    <div class="fw-bold text-mlm-primary lh-sm">Akun Pelanggan</div>
                    <small class="text-mlm-muted">{{ auth('customer')->user()?->name }}</small>
                </div>
            </a>
        </div>

        <nav class="d-flex flex-column gap-1 flex-grow-1">
            <a href="{{ route('customer.dashboard') }}" class="mlm-sidebar-link {{ request()->routeIs('customer.dashboard') ? 'active' : '' }}">
                <i class="bi bi-house-door"></i> Ringkasan
            </a>
            <div class="px-3 pt-3 pb-1 text-uppercase small fw-semibold text-mlm-muted">Menu Pelanggan</div>
            <a href="{{ route('customer.orders.index') }}" class="mlm-sidebar-link {{ request()->routeIs('customer.orders.*') ? 'active' : '' }}">
                <i class="bi bi-bag-check"></i> Pesanan Saya
            </a>
            <a href="{{ route('customer.addresses.index') }}" class="mlm-sidebar-link {{ request()->routeIs('customer.addresses.*') ? 'active' : '' }}">
                <i class="bi bi-geo-alt"></i> Alamat Pengiriman
            </a>
            <a href="{{ route('storefront.home') }}" class="mlm-sidebar-link mt-2 border-top pt-3">
                <i class="bi bi-shop"></i> Kembali ke Toko
            </a>
        </nav>

        <form method="POST" action="{{ route('customer.logout') }}" class="mt-3 pt-3 border-top">
            @csrf
            <button type="submit" class="btn btn-outline-danger w-100">
                <i class="bi bi-box-arrow-left"></i> Keluar
            </button>
        </form>
    </div>
@endif
