@php
    $storeName = $storeSettings['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara');
    $metaDescription = trim((string) View::yieldContent('meta_description', $storeSettings['store_description'] ?? ''));
    $pageTitle = trim((string) View::yieldContent('title', $storeName));
    $canonicalUrl = trim((string) View::yieldContent('canonical', url()->current()));
    $robotsMeta = trim((string) View::yieldContent('robots', 'index,follow'));
    $ogType = trim((string) View::yieldContent('og_type', 'website'));
    $defaultLogoUrl = !empty($storeSettings['logo_path']) ? asset('storage/'.$storeSettings['logo_path']) : null;
    $ogImage = trim((string) View::yieldContent('og_image', $defaultLogoUrl ?? ''));

    $primaryColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($storeSettings['primary_color'] ?? ''))
        ? $storeSettings['primary_color']
        : '#245C45';
    $secondaryColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($storeSettings['secondary_color'] ?? ''))
        ? $storeSettings['secondary_color']
        : '#E9F1E9';
    $accentColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($storeSettings['accent_color'] ?? ''))
        ? $storeSettings['accent_color']
        : '#E9A23B';

    $organizationSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'OnlineStore',
        'name' => $storeName,
        'url' => url('/'),
        'description' => $storeSettings['store_description'] ?? null,
        'logo' => $defaultLogoUrl,
        'email' => ($storeSettings['store_email'] ?? null) ?: null,
        'telephone' => ($storeSettings['store_phone'] ?? null) ?: null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta name="robots" content="{{ $robotsMeta }}">

    @if(!empty($storeSettings['favicon_path']))
        <link rel="icon" href="{{ asset('storage/'.$storeSettings['favicon_path']) }}">
    @endif

    {{-- Open Graph --}}
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="{{ $storeName }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="@yield('og_title', $pageTitle)">
    <meta property="og:description" content="@yield('og_description', $metaDescription)">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if($ogImage !== '')
        <meta property="og:image" content="{{ $ogImage }}">
    @endif

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="{{ $ogImage !== '' ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="@yield('og_title', $pageTitle)">
    <meta name="twitter:description" content="@yield('og_description', $metaDescription)">
    @if($ogImage !== '')
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/modern-local-market.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/coffee-nusantara.css') }}" rel="stylesheet">

    <style>
        :root {
            --mlm-primary: {{ $primaryColor }};
            --mlm-secondary: {{ $secondaryColor }};
            --mlm-accent: {{ $accentColor }};
        }
    </style>

    @unless(View::hasSection('disable_default_schema'))
        <script type="application/ld+json">{!! json_encode($organizationSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endunless
    @stack('structured_data')
    @stack('styles')
</head>
<body class="coffee-storefront d-flex flex-column min-vh-100">
    <x-header
        :store-settings="$storeSettings"
        :storefront-nav="$storefrontNav ?? []"
        :cart-item-count="$cartItemCount ?? 0"
    />

    <main class="flex-grow-1">
        <div class="container pt-3">
            <x-notification />
        </div>
        @yield('content')
    </main>

    <x-footer
        :store-settings="$storeSettings"
        :storefront-nav="$storefrontNav ?? []"
    />
    <x-modal />

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="{{ asset('assets/js/app.js') }}" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js" defer></script>
    @stack('scripts')
</body>
</html>
