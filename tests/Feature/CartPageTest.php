<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Services\Storefront\CartService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CartPageTest extends TestCase
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
            'sku' => 'CRT-'.$suffix,
            'name' => 'Produk Keranjang '.$suffix,
            'slug' => 'produk-keranjang-'.$suffix,
            'description' => 'Produk UMKM berkualitas.',
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
            'sku' => 'CVAR-'.$suffix,
            'name' => 'Varian '.$suffix,
            'price' => 65000,
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
            'name' => 'Pembeli '.$suffix,
            'email' => 'pembeli-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Rahasia123',
            'status' => 'active',
        ], $overrides));
    }

    public function test_guest_and_customer_see_empty_state_when_cart_is_empty(): void
    {
        $guestResponse = $this->get(route('storefront.cart'));
        $guestResponse->assertStatus(200)
            ->assertSee('Keranjang Belanja')
            ->assertSee('Keranjang Belanja Masih Kosong')
            ->assertSee('Mulai Belanja')
            ->assertSee(route('storefront.catalog'));

        $customer = $this->createCustomer();
        $this->actingAs($customer, 'customer')
            ->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Keranjang Belanja Masih Kosong');
    }

    public function test_guest_and_customer_can_view_cart_items_variants_seo_and_no_product_json_ld(): void
    {
        $product = $this->createProduct([
            'name' => 'Kopi Toraja Sapan',
            'sku' => 'TOR-01',
            'has_variants' => true,
            'stock' => 0,
        ]);

        $attr = ProductAttribute::create(['name' => 'Ukuran', 'slug' => 'ukuran']);
        $attrVal = ProductAttributeValue::create(['attribute_id' => $attr->id, 'value' => '250g', 'slug' => '250g']);
        $variant = $this->createVariant($product, [
            'name' => 'Bubuk Halus 250g',
            'sku' => 'TOR-01-250',
            'price' => 75000,
            'stock' => 8,
        ]);
        $variant->attributeValues()->attach($attrVal->id);

        $this->cartService->addItem($product->id, $variant->id, 2);

        $guestResponse = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->get(route('storefront.cart'));

        $guestResponse->assertStatus(200)
            ->assertSee('Kopi Toraja Sapan')
            ->assertSee('TOR-01-250')
            ->assertSee('Bubuk Halus 250g')
            ->assertSee('Ukuran: 250g')
            ->assertSee('Rp 150.000')
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false)
            ->assertSee('Keranjang belanja (2 item)', false);

        $html = $guestResponse->getContent();
        $this->assertStringNotContainsString('"@type":"Product"', $html);
        $this->assertSame(1, substr_count($html, '<h1 '));

        $customer = $this->createCustomer();
        $this->cartService->addItem($product->id, $variant->id, 3, $customer);

        $this->actingAs($customer, 'customer')
            ->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Kopi Toraja Sapan')
            ->assertSee('Rp 225.000')
            ->assertSee('Keranjang belanja (3 item)', false);
    }

    public function test_guest_and_customer_can_update_quantity_via_ajax_and_standard_form_and_browser_price_is_ignored(): void
    {
        $product = $this->createProduct(['price' => 40000, 'stock' => 10]);
        $this->cartService->addItem($product->id, null, 1);
        $guestKey = CartService::buildItemKey($product->id, null);

        // Guest update via AJAX dengan manipulasi harga di request
        $ajaxResponse = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->patchJson(route('storefront.cart.update', ['item' => $guestKey]), [
            'quantity' => 3,
            'price' => 100,
            'unit_price' => 1,
            'subtotal' => 1,
        ]);

        $ajaxResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 3,
            ])
            ->assertJsonPath('cart.subtotal', 120000)
            ->assertJsonPath('cart.items.0.unit_price', 40000)
            ->assertJsonPath('cart.items.0.line_subtotal', 120000);

        // Guest update via form HTML standar (redirect + flash)
        $this->from(route('storefront.cart'))
            ->patch(route('storefront.cart.update', ['item' => $guestKey]), [
                'quantity' => 2,
            ])
            ->assertRedirect(route('storefront.cart'))
            ->assertSessionHas('success');

        $this->assertSame(2, $this->cartService->getHeaderItemCount());

        // Customer update via AJAX & form standar
        $customer = $this->createCustomer();
        $added = $this->cartService->addItem($product->id, null, 1, $customer);
        $cartItemId = $added['cart_item_id'];

        $this->actingAs($customer, 'customer')
            ->patchJson(route('storefront.cart.update', ['item' => $cartItemId]), [
                'quantity' => 4,
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 4,
            ])
            ->assertJsonPath('cart.subtotal', 160000);

        $this->assertSame(4, CartItem::findOrFail($cartItemId)->quantity);
    }

    public function test_invalid_quantity_or_exceeding_stock_is_rejected_without_corrupting_existing_cart(): void
    {
        $product = $this->createProduct(['stock' => 5, 'reserved_stock' => 1]); // available 4
        $this->cartService->addItem($product->id, null, 2);
        $guestKey = CartService::buildItemKey($product->id, null);

        $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->patchJson(route('storefront.cart.update', ['item' => $guestKey]), [
            'quantity' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->patchJson(route('storefront.cart.update', ['item' => $guestKey]), [
            'quantity' => 5, // melebihi available 4
        ])->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(2, $this->cartService->getHeaderItemCount());
    }

    public function test_guest_and_customer_can_remove_items_handle_missing_item_and_clear_cart(): void
    {
        $productA = $this->createProduct(['name' => 'Teh Melati', 'stock' => 10]);
        $productB = $this->createProduct(['name' => 'Gula Kelapa', 'stock' => 10]);

        $this->cartService->addItem($productA->id, null, 1);
        $this->cartService->addItem($productB->id, null, 2);
        $keyA = CartService::buildItemKey($productA->id, null);

        // Hapus satu item guest
        $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->deleteJson(route('storefront.cart.remove', ['item' => $keyA]))
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 2,
            ]);

        // Hapus item yang sudah tidak tersedia tidak menyebabkan 500
        $this->deleteJson(route('storefront.cart.remove', ['item' => '999999-0']))
            ->assertStatus(200)
            ->assertJson([
                'success' => false,
                'cart_item_count' => 2,
            ]);

        // Clear guest cart
        $this->deleteJson(route('storefront.cart.clear'))
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 0,
            ])
            ->assertJsonPath('cart.items', []);

        // Customer remove & clear
        $customer = $this->createCustomer();
        $itemA = $this->cartService->addItem($productA->id, null, 2, $customer);
        $itemB = $this->cartService->addItem($productB->id, null, 3, $customer);

        $this->actingAs($customer, 'customer')
            ->deleteJson(route('storefront.cart.remove', ['item' => $itemA['cart_item_id']]))
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 3,
            ]);

        $this->assertDatabaseMissing('cart_items', ['id' => $itemA['cart_item_id']]);
        $this->assertDatabaseHas('cart_items', ['id' => $itemB['cart_item_id']]);

        $this->actingAs($customer, 'customer')
            ->delete(route('storefront.cart.clear'))
            ->assertRedirect(route('storefront.cart'))
            ->assertSessionHas('success');

        $this->assertSame(0, $this->cartService->getHeaderItemCount($customer));
    }

    public function test_customer_cannot_update_or_delete_another_customers_cart_item_and_guest_cannot_mutate_customer_item(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $product = $this->createProduct(['stock' => 10]);

        $itemB = $this->cartService->addItem($product->id, null, 2, $customerB);
        $itemBId = $itemB['cart_item_id'];

        // Guest mencoba mutasi item customer B tidak mengubah DB customer B
        $this->patchJson(route('storefront.cart.update', ['item' => $itemBId]), ['quantity' => 5])
            ->assertStatus(422);
        $this->deleteJson(route('storefront.cart.remove', ['item' => $itemBId]))
            ->assertStatus(200)
            ->assertJson(['success' => false]);
        $this->assertSame(2, CartItem::findOrFail($itemBId)->quantity);

        // Customer A tidak boleh mengubah atau menghapus item Customer B
        $this->actingAs($customerA, 'customer')
            ->patchJson(route('storefront.cart.update', ['item' => $itemBId]), ['quantity' => 1])
            ->assertStatus(403);

        $this->actingAs($customerA, 'customer')
            ->deleteJson(route('storefront.cart.remove', ['item' => $itemBId]))
            ->assertStatus(403);

        $this->assertDatabaseHas('cart_items', [
            'id' => $itemBId,
            'quantity' => 2,
        ]);
    }

    public function test_stock_change_inactive_product_and_invalid_variant_produce_warnings_and_keep_removable(): void
    {
        $stockDropProduct = $this->createProduct(['name' => 'Keripik Pisang', 'stock' => 6, 'price' => 20000]);
        $inactiveProduct = $this->createProduct(['name' => 'Sambal Roa', 'stock' => 5, 'price' => 30000]);
        $variantParent = $this->createProduct(['name' => 'Batik Cap', 'has_variants' => true, 'stock' => 0]);
        $variant = $this->createVariant($variantParent, ['name' => 'Ukuran L', 'stock' => 5, 'price' => 90000]);

        $this->cartService->addItem($stockDropProduct->id, null, 4);
        $this->cartService->addItem($inactiveProduct->id, null, 1);
        $this->cartService->addItem($variantParent->id, $variant->id, 1);

        // Kondisi katalog berubah setelah item masuk ke keranjang
        $stockDropProduct->update(['stock' => 2]);
        $inactiveProduct->update(['is_active' => false]);
        $variant->update(['is_active' => false]);

        $response = $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->get(route('storefront.cart'));

        $response->assertStatus(200)
            ->assertSee('Stok Keripik Pisang tersisa 2 unit.')
            ->assertSee('Produk Sambal Roa sedang tidak aktif.')
            ->assertSee('Varian untuk produk Batik Cap tidak lagi tersedia.')
            ->assertSee('Tidak dihitung dalam subtotal');

        // Item bermasalah tetap bisa dihapus oleh pengguna
        $inactiveKey = CartService::buildItemKey($inactiveProduct->id, null);
        $this->deleteJson(route('storefront.cart.remove', ['item' => $inactiveKey]))
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }
}
