@props(['title' => 'Dashboard', 'drawerTarget' => '#sidebarDrawer', 'guard' => 'customer'])

<header class="bg-mlm-surface border-bottom px-3 px-lg-4 py-2 d-flex align-items-center justify-content-between gap-2">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="{{ $drawerTarget }}" aria-label="Buka menu navigasi">
            <i class="bi bi-list fs-5"></i>
        </button>
        <h1 class="h5 mb-0 fw-bold text-truncate">{{ $title }}</h1>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('storefront.home') }}" class="btn btn-sm btn-outline-primary d-none d-sm-inline-flex">
            <i class="bi bi-shop"></i> Lihat Toko
        </a>
        <span class="badge bg-mlm-secondary text-mlm-primary px-3 py-2 rounded-pill">
            {{ auth($guard)->user()?->name }}
        </span>
    </div>
</header>
