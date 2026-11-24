<?php

namespace App\Services\Storefront;

use App\Models\Article;
use App\Models\Faq;
use App\Models\Page;
use App\Services\Setting\StoreSettingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class StorefrontContentService
{
    public const ABOUT_CACHE_KEY = 'storefront_about_page_v1';

    public const CONTACT_CACHE_KEY = 'storefront_contact_page_v1';

    public const FAQ_CACHE_KEY = 'storefront_faqs_active_v1';

    public const POLICY_CACHE_PREFIX = 'storefront_policy_page_v1_';

    public const PAGE_CACHE_PREFIX = 'storefront_cms_page_v1_';

    public const POLICY_SLUGS = [
        'kebijakan-privasi',
        'syarat-ketentuan',
        'kebijakan-pengiriman',
        'kebijakan-pengembalian',
    ];

    public const SYSTEM_SPECIAL_SLUGS = [
        'tentang-kami',
        'kontak',
    ];

    /**
     * Slug yang dilindungi agar custom page tidak menabrak route sistem atau halaman bawaan.
     *
     * @var array<int, string>
     */
    public const RESERVED_SLUGS = [
        'admin',
        'akun',
        'api',
        'up',
        'storage',
        'assets',
        'login',
        'logout',
        'register',
        'daftar',
        'masuk',
        'produk',
        'kategori',
        'keranjang',
        'artikel',
        'tentang-kami',
        'faq',
        'kontak',
        'checkout',
        'pembayaran',
        'pesanan',
        'webhook',
        'webhooks',
        'midtrans',
        'rajaongkir',
        'halaman',
        'beranda',
        'home',
        'dashboard',
        'search',
        'pencarian',
        'sitemap',
        'robots',
        'kebijakan-privasi',
        'syarat-ketentuan',
        'kebijakan-pengiriman',
        'kebijakan-pengembalian',
    ];

    public function __construct(
        protected StoreSettingService $settingService,
    ) {
    }

    public static function clearCache(): void
    {
        Cache::forget(self::ABOUT_CACHE_KEY);
        Cache::forget(self::CONTACT_CACHE_KEY);
        Cache::forget(self::FAQ_CACHE_KEY);

        foreach (self::POLICY_SLUGS as $slug) {
            Cache::forget(self::PAGE_CACHE_PREFIX.$slug);
            Cache::forget(self::POLICY_CACHE_PREFIX.$slug);
        }
    }

    public static function clearPageCache(?string $slug): void
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return;
        }

        Cache::forget(self::PAGE_CACHE_PREFIX.$slug);
        Cache::forget(self::POLICY_CACHE_PREFIX.$slug);
    }

    public function publishedArticles(string $search = '', int $perPage = 9): LengthAwarePaginator
    {
        $search = trim($search);

        return Article::query()
            ->with('author:id,name')
            ->published()
            ->when($search !== '', function ($query) use ($search) {
                $escaped = addcslashes($search, '%_\\');
                $query->where(function ($sub) use ($escaped) {
                    $sub->where('title', 'like', '%'.$escaped.'%')
                        ->orWhere('excerpt', 'like', '%'.$escaped.'%');
                });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findPublishedArticleBySlug(string $slug): ?Article
    {
        return Article::query()
            ->with('author:id,name')
            ->published()
            ->where('slug', $slug)
            ->first();
    }

    public function relatedArticles(Article $article, int $limit = 3): Collection
    {
        return Article::query()
            ->with('author:id,name')
            ->published()
            ->where('id', '!=', $article->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function aboutPageData(): array
    {
        $page = Cache::remember(self::ABOUT_CACHE_KEY, 600, function () {
            return Page::query()
                ->where('slug', 'tentang-kami')
                ->where('is_published', true)
                ->first();
        });

        return [
            'page' => $page,
            'settings' => $this->settingService->aboutSettings(),
        ];
    }

    public function activeFaqsGrouped(): Collection
    {
        $faqs = Cache::remember(self::FAQ_CACHE_KEY, 600, function () {
            return Faq::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });

        return $faqs->groupBy(function (Faq $faq) {
            $category = trim((string) $faq->category);

            return $category !== '' ? $category : 'Umum';
        });
    }

    public function contactPageData(): array
    {
        $page = Cache::remember(self::CONTACT_CACHE_KEY, 600, function () {
            return Page::query()
                ->where('slug', 'kontak')
                ->where('is_published', true)
                ->first();
        });

        return [
            'page' => $page,
            'contact' => $this->settingService->contactSettings(),
        ];
    }

    public function findPublishedPageBySlug(string $slug): ?Page
    {
        if (in_array($slug, self::SYSTEM_SPECIAL_SLUGS, true)) {
            return null;
        }

        return Cache::remember(self::PAGE_CACHE_PREFIX.$slug, 600, function () use ($slug) {
            return Page::query()
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
        });
    }

    public function findPublishedPolicyPage(string $slug): ?Page
    {
        if (! in_array($slug, self::POLICY_SLUGS, true)) {
            return null;
        }

        return $this->findPublishedPageBySlug($slug);
    }
}
