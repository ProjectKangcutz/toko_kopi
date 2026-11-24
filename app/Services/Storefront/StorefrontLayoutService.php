<?php

namespace App\Services\Storefront;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class StorefrontLayoutService
{
    public const NAV_CACHE_KEY = 'storefront_navigation_v1';

    public const REQUEST_CART_COUNT_ATTR = '_mlm_request_cart_count';

    public const REQUEST_NAV_DATA_ATTR = '_mlm_request_nav_data';

    public function __construct(
        protected CartService $cartService,
    ) {
    }

    public static function forgetRequestCartCount(): void
    {
        try {
            if (app()->bound('request')) {
                request()->attributes->remove(self::REQUEST_CART_COUNT_ATTR);
            }
        } catch (Throwable) {
            // Abaikan pada konteks CLI atau non-request.
        }
    }

    public function navigationData(): array
    {
        if (app()->bound('request')) {
            $cached = request()->attributes->get(self::REQUEST_NAV_DATA_ATTR);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = Cache::remember(self::NAV_CACHE_KEY, 1800, function () {
            $menus = Menu::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get()
                ->groupBy('location');

            $categories = Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->limit(12)
                ->get();

            $footerPolicyMenus = $this->resolveFooterPolicyLinks($menus->get('footer_policy', collect()));

            return [
                'headerMenus' => $menus->get('header', collect()),
                'footerCol1Menus' => $menus->get('footer_col_1', collect()),
                'footerCol2Menus' => $menus->get('footer_col_2', collect()),
                'footerPolicyMenus' => $footerPolicyMenus,
                'hasManagedFooterPages' => Page::withTrashed()
                    ->whereNotIn('slug', StorefrontContentService::SYSTEM_SPECIAL_SLUGS)
                    ->exists(),
                'navCategories' => $categories,
            ];
        });

        if (app()->bound('request')) {
            request()->attributes->set(self::REQUEST_NAV_DATA_ATTR, $data);
        }

        return $data;
    }

    protected function resolveFooterPolicyLinks(Collection $menuItems): Collection
    {
        $hasSortOrder = Schema::hasColumn('pages', 'sort_order');
        $hasShowInFooter = Schema::hasColumn('pages', 'show_in_footer');

        $query = Page::withTrashed()
            ->whereNotIn('slug', StorefrontContentService::SYSTEM_SPECIAL_SLUGS);

        if ($hasSortOrder) {
            $query->orderBy('sort_order')->orderBy('id');
        } else {
            $query->orderBy('id');
        }

        $allPages = $query->get();

        $pagesBySlug = $allPages->keyBy('slug');
        $hasPolicyPagesInDb = $allPages->contains(
            fn (Page $p) => in_array($p->slug, StorefrontContentService::POLICY_SLUGS, true)
        );

        $links = collect();
        $seenUrls = [];

        // 1. Halaman kebijakan bawaan & custom page aktif dari tabel pages
        $enabledPages = $allPages->filter(function (Page $page) use ($hasShowInFooter) {
            if ($page->trashed() || ! $page->is_published) {
                return false;
            }

            if ($page->isBuiltInPolicy()) {
                return true;
            }

            return $hasShowInFooter ? (bool) $page->show_in_footer : false;
        });

        if ($hasSortOrder) {
            $enabledPages = $enabledPages->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]);
        } else {
            $enabledPages = $enabledPages->sortBy('id');
        }

        foreach ($enabledPages as $page) {
            $path = '/'.$page->slug;
            $seenUrls[$path] = true;
            $links->push((object) [
                'label' => $page->title,
                'url' => $path,
                'target' => '_self',
            ]);
        }

        // 2. Pertahankan item menu footer_policy yang belum ada di pages atau eksternal,
        //    namun sembunyikan menu yang mengarah ke page yang dinonaktifkan/dihapus.
        foreach ($menuItems as $menu) {
            $normalizedPath = '/'.ltrim((string) parse_url((string) $menu->url, PHP_URL_PATH), '/');
            $candidateSlug = ltrim($normalizedPath, '/');

            if (isset($seenUrls[$normalizedPath])) {
                continue;
            }

            if ($candidateSlug !== '' && $pagesBySlug->has($candidateSlug)) {
                // Halaman sudah ada di DB tetapi Disabled / SoftDeleted -> jangan tampilkan di footer.
                continue;
            }

            if ($hasPolicyPagesInDb && in_array($candidateSlug, StorefrontContentService::POLICY_SLUGS, true)) {
                continue;
            }

            $seenUrls[$normalizedPath] = true;
            $links->push($menu);
        }

        return $links->values();
    }

    public function clearNavigationCache(): void
    {
        Cache::forget(self::NAV_CACHE_KEY);

        try {
            if (app()->bound('request')) {
                request()->attributes->remove(self::REQUEST_NAV_DATA_ATTR);
            }
        } catch (Throwable) {
            // Abaikan pada konteks non-request.
        }
    }

    public function cartItemCount(): int
    {
        try {
            $actor = Auth::guard('customer')->user();
            $actorScope = $actor instanceof Customer
                ? 'customer:'.$actor->id.':'.$actor->status
                : 'guest';

            if (app()->bound('request')) {
                $request = request();
                $memoized = $request->attributes->get(self::REQUEST_CART_COUNT_ATTR);

                if (is_array($memoized) && ($memoized['scope'] ?? null) === $actorScope) {
                    return (int) $memoized['count'];
                }

                $count = $this->cartService->getHeaderItemCount();

                $request->attributes->set(self::REQUEST_CART_COUNT_ATTR, [
                    'scope' => $actorScope,
                    'count' => $count,
                ]);

                return $count;
            }

            return $this->cartService->getHeaderItemCount();
        } catch (Throwable) {
            return 0;
        }
    }
}
