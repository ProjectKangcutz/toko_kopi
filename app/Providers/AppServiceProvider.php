<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\CustomerAddress;
use App\Models\Faq;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Policies\ArticlePolicy;
use App\Policies\CartItemPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\CustomerAddressPolicy;
use App\Policies\FaqPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PagePolicy;
use App\Policies\ProductPolicy;
use App\Services\Admin\CatalogManagementService;
use App\Services\Admin\ContentManagementService;
use App\Services\Checkout\OrderCreationService;
use App\Services\Content\HtmlSanitizer;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Order\OrderLifecycleService;
use App\Services\Payment\MidtransClient;
use App\Services\Payment\MidtransWebhookIngestionService;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\PaymentStateSynchronizationService;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\KomerceRegionClient;
use App\Services\Shipping\RajaOngkirShippingClient;
use App\Services\Shipping\RegionLookupService;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontContentService;
use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StoreSettingService::class);
        $this->app->singleton(KomerceRegionClient::class);
        $this->app->singleton(RegionLookupService::class);
        $this->app->singleton(RajaOngkirShippingClient::class);
        $this->app->singleton(ShippingRateService::class);
        $this->app->singleton(CartService::class);
        $this->app->singleton(StorefrontLayoutService::class);
        $this->app->singleton(StorefrontContentService::class);
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(InventoryReservationService::class);
        $this->app->singleton(InventoryAdjustmentService::class);
        $this->app->singleton(CatalogManagementService::class);
        $this->app->singleton(ContentManagementService::class);
        $this->app->singleton(OrderCreationService::class);
        $this->app->singleton(OrderLifecycleService::class);
        $this->app->singleton(MidtransClient::class);
        $this->app->singleton(PaymentInitiationService::class);
        $this->app->singleton(PaymentStateSynchronizationService::class);
        $this->app->singleton(MidtransWebhookIngestionService::class);
    }

    public function boot(): void
    {
        $trustedProxies = trim((string) config('app.trusted_proxies', ''));
        if ($trustedProxies !== '') {
            TrustProxies::at(
                $trustedProxies === '*'
                    ? '*'
                    : array_values(array_filter(array_map('trim', explode(',', $trustedProxies))))
            );
        }

        if ($this->app->environment('production') && str_starts_with(strtolower((string) config('app.url', '')), 'https://')) {
            URL::forceScheme('https');
        }

        Paginator::useBootstrapFive();

        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(CustomerAddress::class, CustomerAddressPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(CartItem::class, CartItemPolicy::class);
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(Faq::class, FaqPolicy::class);

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)->by(strtolower($email).'|'.$request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('midtrans-webhook', function (Request $request) {
            return Limit::perMinute(180)->by((string) $request->ip());
        });

        RateLimiter::for('checkout', function (Request $request) {
            $customerId = Auth::guard('customer')->id() ?? $request->user('customer')?->id;
            $key = $customerId ? 'customer:'.$customerId : 'ip:'.$request->ip();

            return Limit::perMinute(10)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan pembuatan pesanan. Silakan tunggu sebentar sebelum mencoba lagi.',
                    'error_code' => 'checkout_rate_limited',
                    'data' => null,
                ], 429);
            });
        });

        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('region-lookup', function (Request $request) {
            return Limit::perMinute(60)->by($request->user('customer')?->id ?: $request->ip());
        });

        RateLimiter::for('shipping-quote', function (Request $request) {
            $customerId = Auth::guard('customer')->id() ?? $request->user('customer')?->id;
            $key = $customerId ? 'customer:'.$customerId : 'ip:'.$request->ip();

            return Limit::perMinute(10)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan cek ongkos kirim. Silakan tunggu sebentar sebelum mencoba lagi.',
                    'error_code' => 'shipping_rate_limited',
                    'data' => null,
                ], 429);
            });
        });

        RateLimiter::for('payment-initiate', function (Request $request) {
            $customerId = Auth::guard('customer')->id() ?? $request->user('customer')?->id;
            $key = $customerId ? 'customer:'.$customerId : 'ip:'.$request->ip();

            return Limit::perMinute(10)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak permintaan inisiasi pembayaran. Silakan tunggu sebentar sebelum mencoba lagi.',
                    'error_code' => 'payment_rate_limited',
                    'data' => null,
                ], 429);
            });
        });

        View::composer('*', function ($view) {
            try {
                /** @var StoreSettingService $settings */
                $settings = app(StoreSettingService::class);
                $view->with('storeSettings', $settings->publicSettings());
            } catch (Throwable) {
                // Fallback aman saat aplikasi baru dijalankan sebelum migrasi database.
                $view->with('storeSettings', [
                    'store_name' => config('app.name', 'Pasar Lokal Nusantara'),
                    'store_tagline' => 'Produk UMKM Pilihan Asli Nusantara',
                    'primary_color' => '#245C45',
                    'secondary_color' => '#E9F1E9',
                    'accent_color' => '#E9A23B',
                ]);
            }
        });

        View::composer(['layouts.storefront', 'components.header', 'components.footer'], function ($view) {
            try {
                /** @var StorefrontLayoutService $layoutService */
                $layoutService = app(StorefrontLayoutService::class);
                $view->with('storefrontNav', $layoutService->navigationData());
                $view->with('cartItemCount', $layoutService->cartItemCount());
            } catch (Throwable) {
                $view->with('storefrontNav', [
                    'headerMenus' => collect(),
                    'footerCol1Menus' => collect(),
                    'footerCol2Menus' => collect(),
                    'footerPolicyMenus' => collect(),
                    'navCategories' => collect(),
                ]);
                $view->with('cartItemCount', 0);
            }
        });
    }
}
