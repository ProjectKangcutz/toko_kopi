<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Admin Dashboard') — {{ $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/modern-local-market.css') }}?v={{ filemtime(public_path('assets/css/modern-local-market.css')) }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
    <div class="mlm-shell">
        <aside class="mlm-sidebar d-none d-lg-flex flex-column p-3">
            <x-sidebar role="admin" />
        </aside>

        <div class="offcanvas offcanvas-start" tabindex="-1" id="adminSidebarDrawer" aria-labelledby="adminSidebarLabel">
            <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title fw-bold text-mlm-primary" id="adminSidebarLabel">Admin Panel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
            </div>
            <div class="offcanvas-body">
                <x-sidebar role="admin" />
            </div>
        </div>

        <div class="mlm-main">
            <x-navbar title="Panel Administrator" drawer-target="#adminSidebarDrawer" guard="admin" />
            <main class="container-fluid p-3 p-lg-4 flex-grow-1">
                <x-notification />
                @yield('content')
            </main>
        </div>
    </div>

    <x-modal />
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js" defer></script>
    @stack('scripts')
</body>
</html>
