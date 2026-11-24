<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Services\Storefront\CartService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        Cache::flush();
    }

    protected function createProduct(array $overrides = []): Product
    {
        $suffix = uniqid();

        return Product::create(array_merge([
            'sku' => 'PRD-'.$suffix,
            'name' => 'Kopi Gayo Specialty '.$suffix,
            'slug' => 'kopi-gayo-specialty-'.$suffix,
            'short_description' => 'Kopi pilihan petani dataran tinggi Gayo.',
            'description' => "Diproses secara higienis.\nCocok untuk seduh manual.",
            'specifications' => json_encode([
                'Asal Daerah' => 'Aceh Tengah',
                'Berat Bersih' => '250 gram',
            ]),
            'price' => 95000,
            'stock' => 12,
            'reserved_stock' => 2,
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
            'name' => 'Kemasan '.$suffix,
            'price' => 110000,
            'weight_grams' => 500,
            'stock' => 10,
            'reserved_stock' => 0,
            'is_active' => true,
        ], $overrides));
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = uniqid();

        return Customer::create(array_merge([
            'name' => 'Sari '.$suffix,
            'email' => 'sari-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Rahasia123',
            'status' => 'active',
        ], $overrides));
    }

    protected function extractJsonLdBlocks(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $json) => json_decode(trim($json), true))
            ->filter(fn ($decoded) => is_array($decoded))
            ->values()
            ->all();
    }

    public function test_active_product_renders_detail_page_with_single_h1_gallery_specs_and_approved_reviews_only(): void
    {
        $category = Category::create([
            'name' => 'Kopi Nusantara',
            'slug' => 'kopi-nusantara',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = $this->createProduct([
            'name' => 'Kopi Arabika Gayo Premium',
            'slug' => 'kopi-arabika-gayo-premium',
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => 'products/gayo-1.jpg',
            'webp_path' => 'products/gayo-1.webp',
            'alt_text' => 'Kemasan Kopi Gayo',
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => 'products/gayo-2.jpg',
            'alt_text' => 'Biji Kopi Gayo',
            'sort_order' => 2,
            'is_primary' => false,
        ]);

        $reviewer = $this->createCustomer([
            'name' => 'Budi Santoso',
            'email' => 'rahasia-budi@example.com',
            'phone' => '089911223344',
        ]);

        ProductReview::create([
            'product_id' => $product->id,
            'customer_id' => $reviewer->id,
            'rating' => 5,
            'comment' => 'Aroma kopinya sangat mantap.',
            'is_approved' => true,
        ]);

        ProductReview::create([
            'product_id' => $product->id,
            'customer_id' => $reviewer->id,
            'rating' => 1,
            'comment' => 'Komentar belum disetujui admin.',
            'is_approved' => false,
        ]);

        $response = $this->get(route('storefront.product', $product->slug));

        $response->assertStatus(200);
        $response->assertSee('Kopi Arabika Gayo Premium');
        $response->assertSee($product->sku);
        $response->assertSee('Asal Daerah');
        $response->assertSee('Aceh Tengah');
        $response->assertSee('Budi Santoso');
        $response->assertSee('Aroma kopinya sangat mantap.');
        $response->assertDontSee('Komentar belum disetujui admin.');
        $response->assertDontSee('rahasia-budi@example.com');
        $response->assertDontSee('089911223344');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $schemas = $this->extractJsonLdBlocks($html);
        $this->assertSame(1, collect($schemas)->where('@type', 'OnlineStore')->count());
    }

    public function test_missing_inactive_and_soft_deleted_products_return_404(): void
    {
        $this->get('/produk/produk-tidak-ada')->assertStatus(404);

        $inactive = $this->createProduct(['slug' => 'produk-nonaktif', 'is_active' => false]);
        $this->get('/produk/'.$inactive->slug)->assertStatus(404);

        $deleted = $this->createProduct(['slug' => 'produk-terhapus', 'is_active' => true]);
        $deleted->delete();
        $this->get('/produk/'.$deleted->slug)->assertStatus(404);
    }

    public function test_promo_price_respects_active_promo_dates(): void
    {
        $activePromo = $this->createProduct([
            'slug' => 'produk-promo-aktif',
            'price' => 100000,
            'promo_price' => 80000,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
        ]);

        $this->get(route('storefront.product', $activePromo->slug))
            ->assertStatus(200)
            ->assertSee('Rp 80.000')
            ->assertSee('Rp 100.000');

        $expiredPromo = $this->createProduct([
            'slug' => 'produk-promo-berakhir',
            'price' => 100000,
            'promo_price' => 75000,
            'promo_start_at' => now()->subDays(5),
            'promo_end_at' => now()->subDay(),
        ]);

        $this->get(route('storefront.product', $expiredPromo->slug))
            ->assertStatus(200)
            ->assertSee('Rp 100.000')
            ->assertDontSee('Rp 75.000');
    }

    public function test_simple_and_variant_products_render_correctly_and_hide_inactive_variants(): void
    {
        $simple = $this->createProduct(['slug' => 'madu-hutan', 'price' => 65000, 'stock' => 7, 'reserved_stock' => 2]);
        $this->get(route('storefront.product', $simple->slug))
            ->assertStatus(200)
            ->assertSee('Rp 65.000')
            ->assertSee('5');

        $variantProduct = $this->createProduct([
            'slug' => 'kopi-varian',
            'has_variants' => true,
            'price' => 50000,
            'stock' => 0,
        ]);

        $activeVar1 = $this->createVariant($variantProduct, ['name' => 'Biji Utuh 250g', 'price' => 60000, 'stock' => 4]);
        $activeVar2 = $this->createVariant($variantProduct, ['name' => 'Giling Halus 500g', 'price' => 115000, 'stock' => 6]);
        $inactiveVar = $this->createVariant($variantProduct, ['name' => 'Varian Rahasia Nonaktif', 'price' => 10000, 'is_active' => false]);

        $response = $this->get(route('storefront.product', $variantProduct->slug));
        $response->assertStatus(200)
            ->assertSee($activeVar1->name)
            ->assertSee($activeVar2->name)
            ->assertDontSee($inactiveVar->name);

        $schemas = $this->extractJsonLdBlocks($response->getContent());
        $productSchema = collect($schemas)->firstWhere('@type', 'Product');
        $this->assertNotNull($productSchema);
        $this->assertSame('AggregateOffer', $productSchema['offers']['@type']);
        $this->assertSame('60000.00', $productSchema['offers']['lowPrice']);
        $this->assertSame('115000.00', $productSchema['offers']['highPrice']);
    }

    public function test_variant_from_another_product_is_rejected_on_add_to_cart(): void
    {
        $productA = $this->createProduct(['has_variants' => true]);
        $productB = $this->createProduct(['has_variants' => true]);
        $variantB = $this->createVariant($productB, ['stock' => 5]);

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $productA->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('product_variant_id');
    }

    public function test_out_of_stock_product_renders_200_with_out_of_stock_schema_and_blocks_cart_addition(): void
    {
        $oosProduct = $this->createProduct([
            'slug' => 'produk-habis',
            'stock' => 3,
            'reserved_stock' => 3,
        ]);

        $response = $this->get(route('storefront.product', $oosProduct->slug));
        $response->assertStatus(200)
            ->assertSee('Stok Habis');

        $schemas = $this->extractJsonLdBlocks($response->getContent());
        $productSchema = collect($schemas)->firstWhere('@type', 'Product');
        $this->assertSame('https://schema.org/OutOfStock', $productSchema['offers']['availability']);

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $oosProduct->id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_guest_and_customer_can_add_product_and_header_counter_updates(): void
    {
        $product = $this->createProduct(['slug' => 'teh-hijau-nusantara', 'price' => 45000, 'stock' => 10]);

        $jsonResponse = $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $jsonResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 2,
            ]);

        $this->withSession([
            CartService::GUEST_SESSION_KEY => session(CartService::GUEST_SESSION_KEY),
        ])->get(route('storefront.product', $product->slug))
            ->assertStatus(200)
            ->assertSee('Keranjang belanja (2 item)', false);

        // Uji non-JS form submit standar (redirect + flash)
        $this->from(route('storefront.product', $product->slug))
            ->post(route('storefront.cart.add'), [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertRedirect(route('storefront.product', $product->slug))
            ->assertSessionHas('success');

        $customer = $this->createCustomer();
        $this->actingAs($customer, 'customer')
            ->postJson(route('storefront.cart.add'), [
                'product_id' => $product->id,
                'quantity' => 3,
            ])
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'cart_item_count' => 3,
            ]);

        $cart = Cart::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(3, CartItem::where('cart_id', $cart->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_invalid_quantity_and_price_manipulation_are_rejected_or_ignored_without_clearing_cart(): void
    {
        $product = $this->createProduct(['price' => 85000, 'stock' => 4, 'reserved_stock' => 0]);

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 100,
            'unit_price' => 1,
            'subtotal' => 1,
        ])->assertStatus(200)
            ->assertJsonPath('item.unit_price', 85000)
            ->assertJsonPath('item.line_subtotal', 170000);

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 3, // total 5 > 4
        ])->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        // Isi keranjang sebelumnya tetap terjaga (2 unit) dengan harga DB asli.
        $cart = app(CartService::class)->getCart();
        $this->assertSame(2, $cart['total_quantity']);
        $this->assertSame(170000.0, $cart['subtotal']);
    }

    public function test_seo_metadata_canonical_validation_and_json_ld_schemas_are_accurate(): void
    {
        $category = Category::create([
            'name' => 'Kriya Kayu',
            'slug' => 'kriya-kayu',
            'is_active' => true,
        ]);

        $product = $this->createProduct([
            'name' => 'Nampan Jati Ukir',
            'slug' => 'nampan-jati-ukir',
            'price' => 125000,
            'stock' => 5,
            'reserved_stock' => 0,
            'seo_title' => 'Nampan Jati Ukir Asli Jepara',
            'seo_description' => 'Kerajinan nampan kayu jati asli buatan pengrajin UMKM Jepara.',
            'canonical_url' => 'https://malicious-external.example.com/steal-seo',
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);

        $response = $this->get(route('storefront.product', $product->slug));
        $response->assertStatus(200);

        $expectedCanonical = route('storefront.product', $product->slug);
        $response->assertSee('<title>Nampan Jati Ukir Asli Jepara', false);
        $response->assertSee('<meta name="description" content="Kerajinan nampan kayu jati asli buatan pengrajin UMKM Jepara.">', false);
        $response->assertSee('<link rel="canonical" href="'.$expectedCanonical.'">', false);
        $response->assertSee('<meta property="og:type" content="product">', false);

        $schemas = $this->extractJsonLdBlocks($response->getContent());

        $productSchema = collect($schemas)->firstWhere('@type', 'Product');
        $this->assertNotNull($productSchema);
        $this->assertSame('Nampan Jati Ukir', $productSchema['name']);
        $this->assertSame('Offer', $productSchema['offers']['@type']);
        $this->assertSame('125000.00', $productSchema['offers']['price']);
        $this->assertSame('https://schema.org/InStock', $productSchema['offers']['availability']);
        $this->assertArrayNotHasKey('aggregateRating', $productSchema);

        $breadcrumbSchema = collect($schemas)->firstWhere('@type', 'BreadcrumbList');
        $this->assertNotNull($breadcrumbSchema);
        $names = collect($breadcrumbSchema['itemListElement'])->pluck('name')->all();
        $this->assertSame(['Beranda', 'Katalog Produk', 'Kriya Kayu', 'Nampan Jati Ukir'], $names);
    }

    public function test_unapproved_reviews_do_not_produce_fake_aggregate_rating_and_approved_reviews_do(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['slug' => 'batik-tulis-solo']);

        ProductReview::create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'comment' => 'Belum disetujui.',
            'is_approved' => false,
        ]);

        $beforeApproval = $this->get(route('storefront.product', $product->slug));
        $schemasBefore = $this->extractJsonLdBlocks($beforeApproval->getContent());
        $productSchemaBefore = collect($schemasBefore)->firstWhere('@type', 'Product');
        $this->assertArrayNotHasKey('aggregateRating', $productSchemaBefore);

        ProductReview::create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 4,
            'comment' => 'Bagus dan rapi.',
            'is_approved' => true,
        ]);

        $afterApproval = $this->get(route('storefront.product', $product->slug));
        $schemasAfter = $this->extractJsonLdBlocks($afterApproval->getContent());
        $productSchemaAfter = collect($schemasAfter)->firstWhere('@type', 'Product');
        $this->assertArrayHasKey('aggregateRating', $productSchemaAfter);
        $this->assertSame('4.0', $productSchemaAfter['aggregateRating']['ratingValue']);
        $this->assertSame(1, $productSchemaAfter['aggregateRating']['reviewCount']);
    }

    public function test_related_products_are_capped_at_four_and_have_no_duplicates_or_current_product(): void
    {
        $catA = Category::create(['name' => 'Kategori A', 'slug' => 'kategori-a', 'is_active' => true]);
        $catB = Category::create(['name' => 'Kategori B', 'slug' => 'kategori-b', 'is_active' => true]);

        $main = $this->createProduct(['name' => 'Produk Utama Unik', 'slug' => 'produk-utama-unik']);
        $main->categories()->attach([$catA->id => ['is_primary' => true], $catB->id => ['is_primary' => false]]);

        for ($i = 1; $i <= 5; $i++) {
            $rel = $this->createProduct(['name' => 'Terkait Spesial '.$i, 'slug' => 'terkait-spesial-'.$i]);
            // Hubungkan ke dua kategori sekaligus untuk menguji anti-duplikasi query whereHas.
            $rel->categories()->attach([$catA->id => ['is_primary' => true], $catB->id => ['is_primary' => false]]);
        }

        $response = $this->get(route('storefront.product', $main->slug));
        $response->assertStatus(200);

        $viewData = $response->original->getData();
        $related = $viewData['relatedProducts'];

        $this->assertCount(4, $related);
        $this->assertFalse($related->contains('id', $main->id));
        $this->assertSame($related->pluck('id')->unique()->count(), $related->count());
    }
}
