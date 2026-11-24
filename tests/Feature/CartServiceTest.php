<?php

namespace Tests\Feature;

use App\Http\Requests\Storefront\AddToCartRequest;
use App\Http\Requests\Storefront\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontLayoutService;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CartServiceTest extends TestCase
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
            'sku' => 'SKU-'.$suffix,
            'name' => 'Produk UMKM '.$suffix,
            'slug' => 'produk-umkm-'.$suffix,
            'description' => 'Deskripsi produk UMKM.',
            'price' => 50000,
            'stock' => 10,
            'reserved_stock' => 0,
            'weight_grams' => 250,
            'has_variants' => false,
            'is_active' => true,
        ], $overrides));
    }

    protected function createVariant(Product $product, array $overrides = []): ProductVariant
    {
        $suffix = uniqid();

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'VAR-'.$suffix,
            'name' => 'Varian '.$suffix,
            'price' => 60000,
            'weight_grams' => 300,
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
            'email' => 'pelanggan-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Rahasia123',
            'status' => 'active',
        ], $overrides));
    }

    public function test_guest_cart_stores_items_in_session_without_price_truth(): void
    {
        $product = $this->createProduct(['price' => 75000, 'stock' => 8]);

        $result = $this->cartService->addItem($product->id, null, 2);

        $sessionCart = session(CartService::GUEST_SESSION_KEY);
        $expectedKey = CartService::buildItemKey($product->id, null);

        $this->assertIsArray($sessionCart);
        $this->assertArrayHasKey($expectedKey, $sessionCart);
        $this->assertSame([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ], $sessionCart[$expectedKey]);
        $this->assertArrayNotHasKey('price', $sessionCart[$expectedKey]);
        $this->assertSame(150000.0, $result['line_subtotal']);
    }

    public function test_identical_product_additions_accumulate_for_guest_and_customer(): void
    {
        $product = $this->createProduct(['stock' => 10]);

        $this->cartService->addItem($product->id, null, 2);
        $this->cartService->addItem($product->id, null, 3);

        $guestCart = $this->cartService->getCart();
        $this->assertCount(1, $guestCart['items']);
        $this->assertSame(5, $guestCart['items']->first()['quantity']);

        $customer = $this->createCustomer();
        $this->cartService->addItem($product->id, null, 1, $customer);
        $this->cartService->addItem($product->id, null, 4, $customer);

        $this->assertSame(1, Cart::where('customer_id', $customer->id)->count());
        $this->assertSame(1, CartItem::where('product_id', $product->id)->whereNull('product_variant_id')->count());
        $this->assertSame(5, CartItem::where('product_id', $product->id)->whereNull('product_variant_id')->value('quantity'));
    }

    public function test_different_variants_become_separate_cart_items(): void
    {
        $product = $this->createProduct(['has_variants' => true, 'stock' => 0]);
        $variantA = $this->createVariant($product, ['name' => '250gr', 'price' => 45000, 'stock' => 5]);
        $variantB = $this->createVariant($product, ['name' => '500gr', 'price' => 85000, 'stock' => 5]);

        $this->cartService->addItem($product->id, $variantA->id, 1);
        $this->cartService->addItem($product->id, $variantB->id, 2);

        $cart = $this->cartService->getCart();
        $this->assertCount(2, $cart['items']);
        $this->assertSame(3, $cart['total_quantity']);
        $this->assertSame(215000.0, $cart['subtotal']);
    }

    public function test_variant_from_another_product_missing_variant_and_variant_on_simple_product_are_rejected(): void
    {
        $productA = $this->createProduct(['has_variants' => true]);
        $productB = $this->createProduct(['has_variants' => true]);
        $simpleProduct = $this->createProduct(['has_variants' => false, 'stock' => 5]);
        $variantB = $this->createVariant($productB, ['stock' => 5]);

        try {
            $this->cartService->addItem($productA->id, $variantB->id, 1);
            $this->fail('Varian milik produk lain seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_variant_id', $e->errors());
        }

        try {
            $this->cartService->addItem($productA->id, null, 1);
            $this->fail('Produk bervarian tanpa variant_id seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_variant_id', $e->errors());
        }

        try {
            $this->cartService->addItem($simpleProduct->id, $variantB->id, 1);
            $this->fail('Produk non-varian dengan variant_id seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('product_variant_id', $e->errors());
        }
    }

    public function test_inactive_product_is_rejected(): void
    {
        $product = $this->createProduct(['is_active' => false, 'stock' => 10]);

        $this->expectException(ValidationException::class);
        $this->cartService->addItem($product->id, null, 1);
    }

    public function test_soft_deleted_product_is_rejected(): void
    {
        $product = $this->createProduct(['is_active' => true, 'stock' => 10]);
        $product->delete();

        $this->expectException(ValidationException::class);
        $this->cartService->addItem($product->id, null, 1);
    }

    public function test_out_of_stock_product_is_rejected(): void
    {
        $product = $this->createProduct(['stock' => 3, 'reserved_stock' => 3]);

        $this->expectException(ValidationException::class);
        $this->cartService->addItem($product->id, null, 1);
    }

    public function test_accumulative_quantity_exceeding_available_stock_is_rejected(): void
    {
        $product = $this->createProduct(['stock' => 5, 'reserved_stock' => 1]); // available = 4

        $this->cartService->addItem($product->id, null, 3);

        try {
            $this->cartService->addItem($product->id, null, 2);
            $this->fail('Kuantitas akumulatif melebihi stok tersedia seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $this->assertSame(3, $this->cartService->getHeaderItemCount());
    }

    public function test_customer_cart_persists_in_database(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['price' => 40000, 'stock' => 10]);

        $this->cartService->addItem($product->id, null, 2, $customer);

        $this->assertDatabaseHas('carts', [
            'customer_id' => $customer->id,
        ]);

        $cart = Cart::where('customer_id', $customer->id)->firstOrFail();
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $hydrated = $this->cartService->getCart($customer);
        $this->assertSame('customer', $hydrated['mode']);
        $this->assertSame(80000.0, $hydrated['subtotal']);
    }

    public function test_customer_cannot_access_or_mutate_another_customers_cart_item(): void
    {
        $owner = $this->createCustomer();
        $intruder = $this->createCustomer();
        $product = $this->createProduct(['stock' => 10]);

        $added = $this->cartService->addItem($product->id, null, 2, $owner);
        $cartItem = CartItem::findOrFail($added['cart_item_id']);

        $this->assertTrue($owner->can('view', $cartItem));
        $this->assertTrue($owner->can('update', $cartItem));
        $this->assertTrue($owner->can('delete', $cartItem));

        $this->assertFalse($intruder->can('view', $cartItem));
        $this->assertFalse($intruder->can('update', $cartItem));
        $this->assertFalse($intruder->can('delete', $cartItem));

        Auth::guard('customer')->login($intruder);
        $request = UpdateCartItemRequest::create('/keranjang/'.$cartItem->id, 'PATCH', ['quantity' => 1]);
        $route = new RoutingRoute(['PATCH'], '/keranjang/{item}', []);
        $route->bind($request);
        $route->setParameter('item', $cartItem->id);
        $request->setRouteResolver(fn () => $route);
        $this->assertFalse($request->authorize());
        Auth::guard('customer')->logout();

        try {
            $this->cartService->updateItem($cartItem->id, 1, $intruder);
            $this->fail('Customer lain tidak boleh mengubah CartItem milik orang lain.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->cartService->removeItem($cartItem->id, $intruder);
            $this->fail('Customer lain tidak boleh menghapus CartItem milik orang lain.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, $cartItem->fresh()->quantity);
    }

    public function test_guest_cart_merges_on_customer_login_and_registration(): void
    {
        $product = $this->createProduct(['stock' => 10]);
        $existingCustomer = $this->createCustomer(['email' => 'login-merge@example.com']);

        $this->cartService->addItem($product->id, null, 2);

        $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->post('/akun/masuk', [
            'email' => 'login-merge@example.com',
            'password' => 'Rahasia123',
        ])->assertRedirect(route('customer.dashboard'));

        $this->assertNull(session(CartService::GUEST_SESSION_KEY));
        $this->assertSame(2, $this->cartService->getHeaderItemCount($existingCustomer->fresh()));

        $this->post('/akun/keluar');
        $this->assertGuest('customer');

        $product2 = $this->createProduct(['stock' => 10]);
        $this->cartService->addItem($product2->id, null, 3);

        $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->post('/akun/daftar', [
            'name' => 'Pembeli Baru',
            'email' => 'register-merge@example.com',
            'phone' => '081299988877',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ])->assertRedirect(route('customer.dashboard'));

        $newCustomer = Customer::where('email', 'register-merge@example.com')->firstOrFail();
        $this->assertNull(session(CartService::GUEST_SESSION_KEY));
        $this->assertSame(3, $this->cartService->getHeaderItemCount($newCustomer));
    }

    public function test_merge_combines_identical_items_without_creating_duplicates(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['stock' => 10]);

        $this->cartService->addItem($product->id, null, 2, $customer);
        $this->cartService->addItem($product->id, null, 3); // guest session

        $result = $this->cartService->mergeGuestCartToCustomer($customer);

        $this->assertSame(1, $result['merged_count']);
        $this->assertSame(1, CartItem::whereHas('cart', fn ($q) => $q->where('customer_id', $customer->id))->count());
        $this->assertSame(5, $this->cartService->getHeaderItemCount($customer));
    }

    public function test_merge_caps_quantity_to_available_stock_and_skips_invalid_items(): void
    {
        $customer = $this->createCustomer();
        $limitedProduct = $this->createProduct(['name' => 'Gula Aren', 'stock' => 5, 'reserved_stock' => 1]); // available 4
        $outOfStockProduct = $this->createProduct(['name' => 'Kopi Habis', 'stock' => 2]);

        $this->cartService->addItem($limitedProduct->id, null, 2, $customer);
        $this->cartService->addItem($limitedProduct->id, null, 4); // guest adds 4 -> total 6 > 4
        $this->cartService->addItem($outOfStockProduct->id, null, 1);

        // Produk kedua kehabisan stok sebelum merge dijalankan.
        $outOfStockProduct->update(['stock' => 0]);

        $result = $this->cartService->mergeGuestCartToCustomer($customer);

        $this->assertSame(1, $result['merged_count']);
        $this->assertSame(1, $result['adjusted_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame(4, $this->cartService->getHeaderItemCount($customer));
    }

    public function test_merge_is_idempotent_when_called_multiple_times(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['stock' => 10]);

        $this->cartService->addItem($product->id, null, 3);

        $first = $this->cartService->mergeGuestCartToCustomer($customer);
        $second = $this->cartService->mergeGuestCartToCustomer($customer);

        $this->assertSame(1, $first['merged_count']);
        $this->assertSame(0, $second['merged_count']);
        $this->assertSame(3, $this->cartService->getHeaderItemCount($customer));
    }

    public function test_header_counter_is_accurate_for_guest_and_customer(): void
    {
        $layoutService = app(StorefrontLayoutService::class);
        $product = $this->createProduct(['stock' => 10]);

        $this->cartService->addItem($product->id, null, 2);
        $this->assertSame(2, $this->cartService->getHeaderItemCount());
        $this->assertSame(2, $layoutService->cartItemCount());

        $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->get('/')
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (2 item)', false);

        $customer = $this->createCustomer();
        $this->cartService->addItem($product->id, null, 4, $customer);

        $this->actingAs($customer, 'customer')
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (4 item)', false);
    }

    public function test_price_calculation_always_uses_live_database_values_and_validates_request_rules(): void
    {
        $product = $this->createProduct([
            'price' => 100000,
            'promo_price' => null,
            'stock' => 10,
        ]);

        $this->cartService->addItem($product->id, null, 2);

        // Simulasikan perubahan harga dan promo di server setelah item masuk keranjang.
        $product->update([
            'price' => 120000,
            'promo_price' => 90000,
            'promo_start_at' => now()->subHour(),
            'promo_end_at' => now()->addHour(),
        ]);

        $cart = $this->cartService->getCart();
        $item = $cart['items']->first();

        $this->assertSame(90000.0, $item['unit_price']);
        $this->assertTrue($item['is_promo']);
        $this->assertSame(180000.0, $cart['subtotal']);

        $addRules = (new AddToCartRequest())->rules();
        $this->assertTrue(Validator::make([
            'product_id' => 'abc',
            'quantity' => 0,
        ], $addRules)->fails());
        $this->assertFalse(Validator::make([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
        ], $addRules)->fails());

        $updateRules = (new UpdateCartItemRequest())->rules();
        $this->assertTrue(Validator::make(['quantity' => 0], $updateRules)->fails());
        $this->assertFalse(Validator::make(['quantity' => 3], $updateRules)->fails());
    }

    public function test_corrupt_or_empty_guest_session_never_causes_errors(): void
    {
        session()->forget(CartService::GUEST_SESSION_KEY);
        $this->assertSame(0, $this->cartService->getHeaderItemCount());
        $this->assertSame(0, $this->cartService->getCart()['total_quantity']);

        session()->put(CartService::GUEST_SESSION_KEY, 'corrupt-scalar-payload');
        $this->assertSame(0, $this->cartService->getHeaderItemCount());
        $this->assertCount(0, $this->cartService->getCart()['items']);

        session()->put(CartService::GUEST_SESSION_KEY, [
            'not-an-array',
            ['product_id' => 'abc', 'quantity' => -5],
            ['product_id' => 999999, 'product_variant_id' => 'invalid', 'quantity' => 2],
        ]);

        $this->assertSame(0, $this->cartService->getHeaderItemCount());
        $this->assertFalse($this->cartService->removeItem('missing-key'));
        $this->get('/')->assertStatus(200);
    }
}
