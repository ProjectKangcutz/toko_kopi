<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontLayoutService;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        Cache::flush();
        $this->cartService = app(CartService::class);
    }

    protected function createProduct(array $overrides = []): Product
    {
        $suffix = uniqid();

        return Product::create(array_merge([
            'sku' => 'INT-'.$suffix,
            'name' => 'Produk Integrasi '.$suffix,
            'slug' => 'produk-integrasi-'.$suffix,
            'description' => 'Produk UMKM untuk pengujian integrasi.',
            'price' => 80000,
            'stock' => 10,
            'reserved_stock' => 0,
            'weight_grams' => 250,
            'has_variants' => false,
            'is_featured' => true,
            'is_active' => true,
        ], $overrides));
    }

    protected function createVariant(Product $product, array $overrides = []): ProductVariant
    {
        $suffix = uniqid();

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'IVAR-'.$suffix,
            'name' => 'Varian '.$suffix,
            'price' => 90000,
            'weight_grams' => 350,
            'stock' => 10,
            'reserved_stock' => 0,
            'is_active' => true,
        ], $overrides));
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = uniqid();

        return Customer::create(array_merge([
            'name' => 'Pelanggan '.$suffix,
            'email' => 'int-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Rahasia123',
            'status' => 'active',
        ], $overrides));
    }

    public function test_end_to_end_guest_funnel_consistency_across_homepage_catalog_detail_and_cart(): void
    {
        $category = Category::create([
            'name' => 'Minuman Tradisional',
            'slug' => 'minuman-tradisional',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = $this->createProduct([
            'name' => 'Wedang Uwuh Jogja',
            'slug' => 'wedang-uwuh-jogja',
            'price' => 50000,
            'promo_price' => 40000,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
            'weight_grams' => 300,
            'stock' => 10,
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);

        $this->get('/')
            ->assertStatus(200)
            ->assertSee('Wedang Uwuh Jogja')
            ->assertSee(route('storefront.product', $product->slug), false);

        $this->get(route('storefront.catalog'))
            ->assertStatus(200)
            ->assertSee('Wedang Uwuh Jogja')
            ->assertSee('Rp 40.000');

        $this->get(route('storefront.product', $product->slug))
            ->assertStatus(200)
            ->assertSee('Wedang Uwuh Jogja')
            ->assertSee('300 gram')
            ->assertSee('Rp 40.000');

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 2,
            ])
            ->assertJsonPath('item.unit_price', 40000)
            ->assertJsonPath('item.line_subtotal', 80000);

        $cartResponse = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->get(route('storefront.cart'));

        $cartResponse->assertStatus(200)
            ->assertSee('Keranjang belanja (2 item)', false)
            ->assertSee('2 item valid')
            ->assertSee('600 gram')
            ->assertSee('Rp 80.000');
    }

    public function test_merge_warning_displays_after_login_and_disappears_on_refresh(): void
    {
        $customer = $this->createCustomer(['email' => 'warn-login@example.com']);
        $limited = $this->createProduct(['name' => 'Gula Semut Aren', 'stock' => 6]);
        $disabled = $this->createProduct(['name' => 'Kopi Nonaktif Merge', 'stock' => 5]);

        $this->cartService->addItem($limited->id, null, 5);
        $this->cartService->addItem($disabled->id, null, 1);

        // Perubahan kondisi di server sebelum login
        $limited->update(['stock' => 3]);
        $disabled->update(['is_active' => false]);

        $loginResponse = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->post('/akun/masuk', [
            'email' => 'warn-login@example.com',
            'password' => 'Rahasia123',
        ]);

        $loginResponse->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');

        $dashboardFirst = $this->get('/akun');
        $dashboardFirst->assertStatus(200)
            ->assertSee('alert-warning', false)
            ->assertSee('Gula Semut Aren')
            ->assertSee('Kopi Nonaktif Merge');

        // Refresh tidak boleh menampilkan pesan warning berulang.
        $dashboardRefresh = $this->get('/akun');
        $dashboardRefresh->assertStatus(200)
            ->assertDontSee('Gula Semut Aren')
            ->assertDontSee('Kopi Nonaktif Merge');
    }

    public function test_merge_warning_displays_after_register_and_disappears_on_refresh(): void
    {
        $limited = $this->createProduct(['name' => 'Madu Klanceng', 'stock' => 5]);
        $this->cartService->addItem($limited->id, null, 4);

        $limited->update(['stock' => 2]);

        $registerResponse = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->post('/akun/daftar', [
            'name' => 'Pembeli Baru',
            'email' => 'warn-register@example.com',
            'phone' => '081277778888',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $registerResponse->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');

        $this->get('/akun')
            ->assertStatus(200)
            ->assertSee('alert-warning', false)
            ->assertSee('Madu Klanceng');

        $this->get('/akun')
            ->assertStatus(200)
            ->assertDontSee('Madu Klanceng');
    }

    public function test_header_badge_and_cart_summary_match_valid_quantity_for_inactive_oos_and_variant_mismatch(): void
    {
        $validProduct = $this->createProduct(['name' => 'Produk Valid', 'price' => 30000, 'stock' => 10]);
        $inactiveProduct = $this->createProduct(['name' => 'Produk Mati', 'price' => 40000, 'stock' => 10]);
        $oosProduct = $this->createProduct(['name' => 'Produk Habis', 'price' => 50000, 'stock' => 5]);
        $mismatchProduct = $this->createProduct(['name' => 'Produk Ubah Varian', 'price' => 60000, 'stock' => 10, 'has_variants' => false]);

        $checkConsistency = function (?Customer $customer = null) use ($validProduct, $inactiveProduct, $oosProduct, $mismatchProduct): void {
            $this->cartService->addItem($validProduct->id, null, 2, $customer);
            $this->cartService->addItem($inactiveProduct->id, null, 3, $customer);
            $this->cartService->addItem($oosProduct->id, null, 2, $customer);
            $this->cartService->addItem($mismatchProduct->id, null, 1, $customer);

            // Ubah status setelah masuk keranjang
            $inactiveProduct->update(['is_active' => false]);
            $oosProduct->update(['stock' => 5, 'reserved_stock' => 5]);
            $mismatchProduct->update(['has_variants' => true]);

            $cart = $this->cartService->getCart($customer);
            $headerCount = $this->cartService->getHeaderItemCount($customer);

            $this->assertSame(8, $cart['total_quantity']);
            $this->assertSame(2, $cart['valid_quantity']);
            $this->assertSame($cart['valid_quantity'], $headerCount);
            $this->assertSame(60000.0, $cart['subtotal']);

            $request = $customer
                ? $this->actingAs($customer, 'customer')
                : $this->withSession([CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY)]);

            $response = $request->get(route('storefront.cart'));
            $response->assertStatus(200)
                ->assertSee('Keranjang belanja (2 item)', false)
                ->assertSee('2 item valid')
                ->assertSee('dari 8 item tersimpan')
                ->assertSee('Produk Mati')
                ->assertSee('Produk Habis')
                ->assertSee('Produk Ubah Varian')
                ->assertSee('Tidak dihitung dalam subtotal');

            // Reset perubahan produk untuk iterasi berikutnya
            $inactiveProduct->update(['is_active' => true]);
            $oosProduct->update(['stock' => 5, 'reserved_stock' => 0]);
            $mismatchProduct->update(['has_variants' => false]);
        };

        // Uji untuk guest
        $checkConsistency(null);
        $this->cartService->clearCart(null);

        // Uji untuk customer
        $customer = $this->createCustomer();
        $checkConsistency($customer);
    }

    public function test_inactive_customer_session_cannot_mutate_or_fallback_to_guest_while_guest_cart_remains_unaffected(): void
    {
        $product = $this->createProduct(['stock' => 10, 'price' => 50000]);
        $activeCustomer = $this->createCustomer(['status' => 'active']);
        $otherCustomer = $this->createCustomer(['status' => 'active']);

        $added = $this->cartService->addItem($product->id, null, 2, $activeCustomer);
        $itemId = $added['cart_item_id'];
        $otherAdded = $this->cartService->addItem($product->id, null, 3, $otherCustomer);

        // Nonaktifkan customer setelah sesi sempat login dan ada guest payload di session
        $activeCustomer->update(['status' => 'suspended']);
        $freshSuspended = $activeCustomer->fresh();

        // 1. Service-level konsistensi: read return empty customer state, mutasi melempar AuthorizationException
        $this->assertSame(0, $this->cartService->getHeaderItemCount($freshSuspended));
        $suspendedCart = $this->cartService->getCart($freshSuspended);
        $this->assertSame('customer', $suspendedCart['mode']);
        $this->assertCount(0, $suspendedCart['items']);

        foreach ([
            fn () => $this->cartService->addItem($product->id, null, 1, $freshSuspended),
            fn () => $this->cartService->updateItem($itemId, 5, $freshSuspended),
            fn () => $this->cartService->removeItem($itemId, $freshSuspended),
            fn () => $this->cartService->clearCart($freshSuspended),
            fn () => $this->cartService->mergeGuestCartToCustomer($freshSuspended),
        ] as $call) {
            try {
                $call();
                $this->fail('Customer nonaktif seharusnya ditolak dengan AuthorizationException.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        // 2. Endpoint AJAX JSON mengembalikan 403 & memutus sesi customer nonaktif tanpa menyentuh cart
        $this->actingAs($freshSuspended, 'customer')
            ->patchJson(route('storefront.cart.update', ['item' => $itemId]), ['quantity' => 5])
            ->assertStatus(403);
        $this->assertGuest('customer');

        $this->actingAs($freshSuspended, 'customer')
            ->deleteJson(route('storefront.cart.remove', ['item' => $itemId]))
            ->assertStatus(403);

        $this->actingAs($freshSuspended, 'customer')
            ->deleteJson(route('storefront.cart.clear'))
            ->assertStatus(403);

        $this->actingAs($freshSuspended, 'customer')
            ->postJson(route('storefront.cart.add'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(403);

        // Akses GET /keranjang HTML me-logout dan mengarahkan ke halaman login
        $this->actingAs($freshSuspended, 'customer')
            ->get(route('storefront.cart'))
            ->assertRedirect(route('customer.login'));
        $this->assertGuest('customer');

        // Item customer sendiri & customer lain tetap utuh di DB
        $this->assertSame(2, CartItem::findOrFail($itemId)->quantity);
        $this->assertSame(3, CartItem::findOrFail($otherAdded['cart_item_id'])->quantity);

        // 3. Guest asli tetap berfungsi normal tanpa terganggu
        $this->postJson(route('storefront.cart.add'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'cart_item_count' => 2]);
    }

    public function test_cart_page_ssr_and_alpine_render_consistent_unavailable_state_and_safe_stepper_guards(): void
    {
        $inactiveButHasStock = $this->createProduct([
            'name' => 'Produk Nonaktif Berstok',
            'stock' => 7,
            'is_active' => true,
        ]);
        $this->cartService->addItem($inactiveButHasStock->id, null, 2);
        $inactiveButHasStock->update(['is_active' => false]);

        $response = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->get(route('storefront.cart'));

        $response->assertStatus(200);
        $html = $response->getContent();

        // Baik SSR maupun Alpine harus menggunakan label 'Tidak tersedia' berdasarkan is_available
        $this->assertStringContainsString('x-show="item.is_available"', $html);
        $this->assertStringContainsString('x-show="!item.is_available" class="text-danger">Tidak tersedia', $html);
        $this->assertSame(2, substr_count($html, 'Tidak tersedia'));

        // Alpine stepper memakai canModifyQuantity(item) yang memblokir update saat stok 0 atau item nonaktif
        $this->assertStringContainsString('canModifyQuantity(item)', $html);
        $this->assertStringContainsString('@click="decrementQuantity(item)"', $html);
        $this->assertStringContainsString('if (stock <= 0)', $html);
    }

    public function test_product_detail_promo_period_label_strictly_matches_selected_variant_promo(): void
    {
        $product = $this->createProduct([
            'name' => 'Kopi Varian Promo Selektif',
            'slug' => 'kopi-varian-promo-selektif',
            'has_variants' => true,
            'stock' => 0,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDays(3),
        ]);

        // Varian pertama (terpilih awal): harga reguler (tidak promo)
        $regularVariant = $this->createVariant($product, [
            'name' => 'Kemasan 250g Reguler',
            'price' => 65000,
            'promo_price' => null,
            'stock' => 5,
        ]);

        // Varian kedua: sedang promo
        $promoVariant = $this->createVariant($product, [
            'name' => 'Kemasan 500g Promo',
            'price' => 120000,
            'promo_price' => 95000,
            'stock' => 5,
        ]);

        $response = $this->get(route('storefront.product', $product->slug));
        $response->assertStatus(200);

        $viewData = $response->original->getData();
        $this->assertSame($regularVariant->id, $viewData['initialVariantId']);
        $this->assertFalse($viewData['pricing']['is_promo']);
        $this->assertNull($viewData['pricing']['promo_period_label']);

        $variantsById = collect($viewData['variants'])->keyBy('id');
        $this->assertNull($variantsById[$regularVariant->id]['promo_period_label']);
        $this->assertNotNull($variantsById[$promoVariant->id]['promo_period_label']);

        $html = $response->getContent();
        $this->assertStringContainsString('x-show="currentIsPromo && Boolean(currentPromoPeriodLabel)"', $html);
    }

    public function test_view_composer_memoizes_cart_counter_within_request_without_cross_actor_leakage(): void
    {
        $product = $this->createProduct(['stock' => 15]);
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();

        $this->cartService->addItem($product->id, null, 2, $customerA);
        $this->cartService->addItem($product->id, null, 5, $customerB);

        // Hitung query cart_items dalam satu HTTP GET request (layout + header + footer ter-render sekaligus)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($customerA, 'customer')
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (2 item)', false);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $cartItemQueries = collect($queries)->filter(
            fn (array $q) => str_contains($q['query'], 'cart_items')
        );
        $this->assertSame(1, $cartItemQueries->count(), 'Query cart_items seharusnya hanya dijalankan sekali per HTTP request.');

        // Mutasi pada request berikutnya harus langsung tercermin di badge header
        $this->actingAs($customerA, 'customer')
            ->postJson(route('storefront.cart.add'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(200)
            ->assertJson(['cart_item_count' => 3]);

        $this->actingAs($customerA, 'customer')
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (3 item)', false);

        // Customer B tetap terisolasi dengan counter miliknya sendiri
        $this->actingAs($customerB, 'customer')
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (5 item)', false);

        // Guest tetap terisolasi
        auth('customer')->logout();
        $this->flushSession();
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (0 item)', false);

        // Pastikan mutasi melalui CartService langsung menginvalidasi memoization request saat ini
        $layoutService = app(StorefrontLayoutService::class);
        $this->assertSame(0, $layoutService->cartItemCount());
        $this->cartService->addItem($product->id, null, 4);
        $this->assertSame(4, $layoutService->cartItemCount());
    }
}
