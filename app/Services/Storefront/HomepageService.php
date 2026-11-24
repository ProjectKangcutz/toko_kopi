<?php

namespace App\Services\Storefront;

use App\Models\Article;
use App\Models\Banner;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Testimonial;
use App\Services\Setting\StoreSettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HomepageService
{
    public const CACHE_KEY = 'storefront_homepage_data_v1';

    /**
     * Maksimal 5 menit (300 detik) agar data terjadwal dan perubahan massal tidak kedaluwarsa lama.
     */
    public const CACHE_TTL_SECONDS = 180;

    public function __construct(
        protected StoreSettingService $settingService,
    ) {
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function getHomepageData(): array
    {
        $now = now();

        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($now) {
            return $this->queryHomepageData($now);
        });

        // Re-validasi item sensitif waktu terhadap $now agar banner/promo kedaluwarsa tidak tampil meski masih dalam TTL cache.
        $heroBanners = collect($cached['heroBanners'] ?? [])
            ->filter(fn (Banner $banner) => $banner->is_active && $banner->isWithinSchedule($now))
            ->values();

        $promoBanners = collect($cached['promoBanners'] ?? [])
            ->filter(fn (Banner $banner) => $banner->is_active && $banner->isWithinSchedule($now))
            ->values();

        $promoProducts = collect($cached['promoProducts'] ?? [])
            ->filter(fn (Product $product) => $product->displayPricing($now)['is_promo'])
            ->values();

        $articles = collect($cached['articles'] ?? [])
            ->filter(fn (Article $article) => $article->status === 'published' && (! $article->published_at || $article->published_at->lessThanOrEqualTo($now)))
            ->values();

        return [
            'heroBanners' => $heroBanners,
            'promoBanners' => $promoBanners,
            'categories' => collect($cached['categories'] ?? []),
            'featuredProducts' => collect($cached['featuredProducts'] ?? []),
            'latestProducts' => collect($cached['latestProducts'] ?? []),
            'bestsellingProducts' => collect($cached['bestsellingProducts'] ?? []),
            'promoProducts' => $promoProducts,
            'testimonials' => collect($cached['testimonials'] ?? []),
            'articles' => $articles,
            'heroFallback' => $this->resolveHeroFallback(),
            'advantages' => $this->resolveAdvantages(),
            'seoMetaDescription' => $this->resolveSeoMetaDescription(),
        ];
    }

    protected function queryHomepageData(CarbonInterface $now): array
    {
        $productRelations = [
            'images',
            'categories' => fn ($q) => $q->where('categories.is_active', true)->orderBy('categories.sort_order'),
            'variants' => fn ($q) => $q->where('product_variants.is_active', true),
        ];

        $heroBanners = Banner::query()
            ->activeWithinWindow('hero', $now)
            ->orderBy('sort_order')
            ->limit(5)
            ->get();

        $promoBanners = Banner::query()
            ->activeWithinWindow('promo_middle', $now)
            ->orderBy('sort_order')
            ->limit(3)
            ->get();

        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(8)
            ->get();

        app(CatalogService::class)->attachDescendantProductCounts($categories);

        $featuredProducts = Product::query()
            ->with($productRelations)
            ->activeAndSellable()
            ->where('products.is_featured', true)
            ->latest('products.id')
            ->limit(8)
            ->get();

        $latestProducts = Product::query()
            ->with($productRelations)
            ->activeAndSellable()
            ->latest('products.created_at')
            ->latest('products.id')
            ->limit(8)
            ->get();

        $salesSubquery = OrderItem::query()
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as total_sold')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.product_id')
            ->where('orders.payment_status', 'paid')
            ->whereNotIn('orders.order_status', ['cancelled', 'refunded'])
            ->groupBy('order_items.product_id');

        $bestsellingProducts = Product::query()
            ->select('products.*', 'valid_sales.total_sold as valid_sold_count')
            ->joinSub($salesSubquery, 'valid_sales', function ($join) {
                $join->on('products.id', '=', 'valid_sales.product_id');
            })
            ->with($productRelations)
            ->activeAndSellable()
            ->where('valid_sales.total_sold', '>', 0)
            ->orderByDesc('valid_sales.total_sold')
            ->orderByDesc('products.id')
            ->limit(8)
            ->get();

        $promoProducts = Product::query()
            ->with($productRelations)
            ->activeAndSellable()
            ->currentlyOnPromo($now)
            ->latest('products.updated_at')
            ->limit(8)
            ->get();

        $testimonials = Testimonial::query()
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->limit(6)
            ->get();

        $articles = Article::query()
            ->with('author:id,name')
            ->published($now)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return [
            'heroBanners' => $heroBanners,
            'promoBanners' => $promoBanners,
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
            'latestProducts' => $latestProducts,
            'bestsellingProducts' => $bestsellingProducts,
            'promoProducts' => $promoProducts,
            'testimonials' => $testimonials,
            'articles' => $articles,
        ];
    }

    public static function mediaUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    public static function sanitizeUrl(?string $url, ?string $fallback = null): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return $fallback;
        }

        // Path relatif internal aman (tolak protocol-relative //evil.com)
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return url($url);
        }

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $url;
            }
        }

        return $fallback;
    }

    protected function resolveHeroFallback(): array
    {
        try {
            $public = $this->settingService->publicSettings();
            $heroTitle = $this->settingService->get('appearance', 'hero_title') ?: ($public['store_tagline'] ?? 'Produk UMKM Pilihan Langsung dari Produsen Lokal');
            $heroSubtitle = $this->settingService->get('appearance', 'hero_subtitle') ?: ($public['store_description'] ?? '');
            $heroCtaLabel = $this->settingService->get('appearance', 'hero_cta_label') ?: 'Jelajahi Produk';
            $heroCtaUrl = self::sanitizeUrl(
                (string) $this->settingService->get('appearance', 'hero_cta_url', '/produk'),
                url('/produk')
            );
            $heroImagePath = $this->settingService->get('appearance', 'hero_image_path');

            return [
                'badge' => $public['store_name'] ?? 'Pasar Lokal Nusantara',
                'title' => (string) $heroTitle,
                'subtitle' => (string) $heroSubtitle,
                'cta_label' => (string) $heroCtaLabel,
                'cta_url' => (string) $heroCtaUrl,
                'image_url' => self::mediaUrl($heroImagePath ? (string) $heroImagePath : null),
            ];
        } catch (Throwable) {
            return [
                'badge' => 'Pasar Lokal Nusantara',
                'title' => 'Produk UMKM Pilihan Langsung dari Produsen Lokal',
                'subtitle' => 'Belanja produk lokal berkualitas langsung dari pelaku UMKM Indonesia.',
                'cta_label' => 'Jelajahi Produk',
                'cta_url' => url('/produk'),
                'image_url' => null,
            ];
        }
    }

    protected function resolveAdvantages(): Collection
    {
        try {
            $originCity = (string) ($this->settingService->get('store', 'origin_city_name') ?: 'Indonesia');
            $storeHours = (string) ($this->settingService->get('general', 'store_hours') ?: 'Senin - Sabtu');
            $guestEnabled = (bool) $this->settingService->get('checkout', 'guest_checkout_enabled', true);

            return collect([
                [
                    'icon' => 'bi-shield-check',
                    'title' => 'Pembayaran Aman & Terverifikasi',
                    'description' => 'Pembayaran diproses melalui kanal resmi dengan validasi status otomatis di server.',
                ],
                [
                    'icon' => 'bi-truck',
                    'title' => 'Pengiriman dari '.$originCity,
                    'description' => 'Perhitungan ongkos kirim transparan sesuai berat produk dan layanan kurir pilihan Anda.',
                ],
                [
                    'icon' => 'bi-bag-check',
                    'title' => $guestEnabled ? 'Belanja Fleksibel & Mudah' : 'Belanja Terdata di Akun',
                    'description' => $guestEnabled
                        ? 'Dapat memesan langsung sebagai tamu maupun melalui akun pelanggan terdaftar.'
                        : 'Pantau seluruh riwayat pesanan dan status pengiriman dari dashboard pelanggan.',
                ],
                [
                    'icon' => 'bi-headset',
                    'title' => 'Layanan Pelanggan Responsif',
                    'description' => 'Dukungan komunikasi langsung pada jam operasional '.$storeHours.'.',
                ],
            ]);
        } catch (Throwable) {
            return collect([
                [
                    'icon' => 'bi-shield-check',
                    'title' => 'Pembayaran Aman & Terverifikasi',
                    'description' => 'Validasi transaksi dan stok produk dikelola langsung secara aman oleh sistem.',
                ],
                [
                    'icon' => 'bi-truck',
                    'title' => 'Pengiriman Terpercaya',
                    'description' => 'Perhitungan ongkos kirim transparan sesuai berat dan alamat tujuan.',
                ],
                [
                    'icon' => 'bi-headset',
                    'title' => 'Layanan Pelanggan',
                    'description' => 'Komunikasi dua arah antara pelanggan dan admin toko.',
                ],
            ]);
        }
    }

    protected function resolveSeoMetaDescription(): string
    {
        try {
            $seoDesc = (string) $this->settingService->get('seo', 'default_meta_description', '');
            if ($seoDesc !== '') {
                return $seoDesc;
            }

            return (string) $this->settingService->get(
                'general',
                'store_description',
                'Belanja produk UMKM unggulan asli Indonesia dengan pembayaran aman dan pengiriman terpercaya.'
            );
        } catch (Throwable) {
            return 'Belanja produk UMKM unggulan asli Indonesia dengan pembayaran aman dan pengiriman terpercaya.';
        }
    }
}
