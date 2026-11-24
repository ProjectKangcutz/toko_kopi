<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\ContentSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_layout_renders_dynamic_identity_menus_categories_search_and_seo_tags(): void
    {
        $this->seed(SettingSeeder::class);
        $this->seed(ContentSeeder::class);

        Setting::updateOrCreate(
            ['group' => 'general', 'key' => 'store_name'],
            ['value' => 'Toko Kriya Nusantara', 'type' => 'string', 'is_public' => true]
        );

        Category::create([
            'name' => 'Kuliner Tradisional',
            'slug' => 'kuliner-tradisional',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Category::create([
            'name' => 'Kategori Nonaktif',
            'slug' => 'kategori-nonaktif',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        Menu::create([
            'location' => 'header',
            'label' => 'Promo Spesial',
            'url' => '/produk?promo=1',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Toko Kriya Nusantara');
        $response->assertSee('Kuliner Tradisional');
        $response->assertDontSee('Kategori Nonaktif');
        $response->assertSee('Promo Spesial');
        $response->assertSee('Kebijakan Privasi');
        $response->assertSee('role="search"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('name="twitter:card"', false);
        $response->assertSee('rel="canonical"', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('storefrontMobileDrawer', false);
    }

    public function test_header_cart_counter_reflects_authenticated_customer_cart(): void
    {
        $customer = Customer::create([
            'name' => 'Dewi',
            'email' => 'dewi@example.com',
            'phone' => '081233344455',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $product = Product::create([
            'sku' => 'BATIK-01',
            'name' => 'Kain Batik Tulis',
            'slug' => 'kain-batik-tulis',
            'description' => 'Batik tulis asli pengrajin.',
            'price' => 250000,
            'stock' => 10,
            'weight_grams' => 300,
            'is_active' => true,
        ]);

        $cart = Cart::create(['customer_id' => $customer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($customer, 'customer')->get('/');

        $response->assertStatus(200);
        $response->assertSee('Keranjang belanja (3 item)', false);
    }
}
