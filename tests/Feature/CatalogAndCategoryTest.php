<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogAndCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        Cache::flush();
    }

    public function test_catalog_without_products_renders_empty_state_and_seo(): void
    {
        $response = $this->get('/produk');

        $response->assertStatus(200);
        $response->assertSee('Katalog Produk');
        $response->assertSee('Belum Ada Produk Tersedia');
        $response->assertSee('<link rel="canonical" href="'.route('storefront.catalog').'">', false);
        $response->assertSee('<meta name="robots" content="index,follow">', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertSame(1, substr_count($html, '"@type":"OnlineStore"'));
        $this->assertSame(1, substr_count($html, '"@type":"BreadcrumbList"'));
    }

    public function test_catalog_shows_only_active_sellable_products_and_supports_variants(): void
    {
        $activeSimple = Product::create([
            'sku' => 'KAT-AKTIF-1',
            'name' => 'Kopi Gayo Asli',
            'slug' => 'kopi-gayo-asli',
            'description' => 'Kopi pilihan.',
            'price' => 85000,
            'stock' => 10,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'KAT-HABIS-1',
            'name' => 'Kopi Stok Terpesan',
            'slug' => 'kopi-stok-terpesan',
            'description' => 'Stok habis.',
            'price' => 80000,
            'stock' => 5,
            'reserved_stock' => 5,
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'KAT-NONAKTIF-1',
            'name' => 'Kopi Nonaktif',
            'slug' => 'kopi-nonaktif',
            'description' => 'Nonaktif.',
            'price' => 80000,
            'stock' => 10,
            'is_active' => false,
        ]);

        $variantProduct = Product::create([
            'sku' => 'KAT-VAR-1',
            'name' => 'Tenun Ikat Sikka',
            'slug' => 'tenun-ikat-sikka',
            'description' => 'Tenun bervarian.',
            'price' => 500000,
            'stock' => 0,
            'has_variants' => true,
            'is_active' => true,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
        ]);

        ProductVariant::create([
            'product_id' => $variantProduct->id,
            'sku' => 'VAR-NONAKTIF',
            'name' => 'Varian Nonaktif Murah',
            'price' => 99000,
            'stock' => 10,
            'is_active' => false,
        ]);

        ProductVariant::create([
            'product_id' => $variantProduct->id,
            'sku' => 'VAR-AKTIF-S',
            'name' => 'Varian S Promo',
            'price' => 250000,
            'promo_price' => 195000,
            'stock' => 6,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        ProductVariant::create([
            'product_id' => $variantProduct->id,
            'sku' => 'VAR-AKTIF-L',
            'name' => 'Varian L Reguler',
            'price' => 280000,
            'stock' => 4,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $response = $this->get('/produk');

        $response->assertStatus(200);
        $response->assertSee($activeSimple->name);
        $response->assertSee('Stok: 8');
        $response->assertSee('Tenun Ikat Sikka');
        $response->assertSee('Stok: 9');
        $response->assertSee('Rp 195.000');
        $response->assertDontSee('Rp 99.000');
        $response->assertDontSee('Kopi Stok Terpesan');
        $response->assertDontSee('Kopi Nonaktif');
    }

    public function test_catalog_pagination_uses_12_per_page_and_resists_query_manipulation(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            Product::create([
                'sku' => 'PAG-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Produk Paginasi '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'slug' => 'produk-paginasi-'.$i,
                'description' => 'Produk ke-'.$i,
                'price' => 50000 + ($i * 1000),
                'stock' => 10,
                'is_active' => true,
            ]);
        }

        // Page 1: 12 item dengan canonical bersih tanpa ?page=1
        $page1 = $this->get('/produk?page=1');
        $page1->assertStatus(200);
        $page1->assertSee('Menampilkan 1–12 dari 15 produk tersedia');
        $page1->assertSee('<link rel="canonical" href="'.route('storefront.catalog').'">', false);
        $page1->assertSee('<meta name="robots" content="index,follow">', false);

        // Page 2: 3 item tersisa dengan canonical ?page=2
        $page2 = $this->get('/produk?page=2');
        $page2->assertStatus(200);
        $page2->assertSee('Menampilkan 13–15 dari 15 produk tersedia');
        $page2->assertSee('<link rel="canonical" href="'.route('storefront.catalog', ['page' => 2]).'">', false);
        $page2->assertSee('<meta name="robots" content="index,follow">', false);

        // Manipulasi per_page & parameter asing harus diabaikan (tetap 12) & diberi noindex,follow
        $manipulated = $this->get('/produk?per_page=50&unknown=1');
        $manipulated->assertStatus(200);
        $manipulated->assertSee('Menampilkan 1–12 dari 15 produk tersedia');
        $manipulated->assertSee('<meta name="robots" content="noindex,follow">', false);
        $manipulated->assertSee('<link rel="canonical" href="'.route('storefront.catalog').'">', false);

        // Halaman di luar batas
        $outOfRange = $this->get('/produk?page=99');
        $outOfRange->assertStatus(200);
        $outOfRange->assertSee('Halaman Tidak Memiliki Produk');
        $outOfRange->assertSee('<meta name="robots" content="noindex,follow">', false);
    }

    public function test_category_handles_active_inactive_soft_deleted_and_missing_slugs(): void
    {
        $activeCategory = Category::create([
            'name' => 'Kuliner Lokal',
            'slug' => 'kuliner-lokal',
            'description' => 'Makanan khas daerah.',
            'is_active' => true,
        ]);

        $inactiveCategory = Category::create([
            'name' => 'Kategori Nonaktif',
            'slug' => 'kategori-nonaktif',
            'is_active' => false,
        ]);

        $deletedCategory = Category::create([
            'name' => 'Kategori Dihapus',
            'slug' => 'kategori-dihapus',
            'is_active' => true,
        ]);
        $deletedCategory->delete();

        $this->get('/kategori/'.$activeCategory->slug)
            ->assertStatus(200)
            ->assertSee('Kuliner Lokal')
            ->assertSee('Belum Ada Produk di Kategori Ini');

        $this->get('/kategori/'.$inactiveCategory->slug)->assertStatus(404);
        $this->get('/kategori/'.$deletedCategory->slug)->assertStatus(404);
        $this->get('/kategori/tidak-ada-sama-sekali')->assertStatus(404);
    }

    public function test_parent_category_shows_nested_active_descendant_products_without_duplicates_and_builds_breadcrumbs(): void
    {
        $grandparent = Category::create([
            'name' => 'Kriya Nusantara',
            'slug' => 'kriya-nusantara',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $parent = Category::create([
            'parent_id' => $grandparent->id,
            'name' => 'Tekstil Tradisional',
            'slug' => 'tekstil-tradisional',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $childA = Category::create([
            'parent_id' => $parent->id,
            'name' => 'Batik Tulis',
            'slug' => 'batik-tulis',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $childB = Category::create([
            'parent_id' => $parent->id,
            'name' => 'Batik Cap',
            'slug' => 'batik-cap',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $inactiveChild = Category::create([
            'parent_id' => $parent->id,
            'name' => 'Subkategori Nonaktif',
            'slug' => 'subkategori-nonaktif',
            'is_active' => false,
            'sort_order' => 3,
        ]);

        // Satu produk masuk ke dua subkategori berbeda -> tidak boleh duplikat saat buka induk.
        $multiSubProduct = Product::create([
            'sku' => 'BTK-MULTI',
            'name' => 'Selendang Batik Solo',
            'slug' => 'selendang-batik-solo',
            'description' => 'Terkait ke dua subkategori.',
            'price' => 175000,
            'stock' => 12,
            'is_active' => true,
        ]);
        $multiSubProduct->categories()->attach([
            $childA->id => ['is_primary' => true],
            $childB->id => ['is_primary' => false],
        ]);

        // Produk hanya berada di subkategori nonaktif -> tidak boleh muncul di kategori induk maupun subkategori.
        $hiddenSubProduct = Product::create([
            'sku' => 'BTK-HIDDEN',
            'name' => 'Kain Subkategori Nonaktif',
            'slug' => 'kain-subkategori-nonaktif',
            'description' => 'Tersembunyi.',
            'price' => 150000,
            'stock' => 5,
            'is_active' => true,
        ]);
        $hiddenSubProduct->categories()->attach($inactiveChild->id, ['is_primary' => true]);

        // Buka kategori grandparent: harus melihat produk cucu tepat 1 kali, dan tidak melihat subkategori nonaktif.
        $gpResponse = $this->get('/kategori/'.$grandparent->slug);
        $gpResponse->assertStatus(200);
        $gpResponse->assertSee('1 produk tersedia');
        $gpResponse->assertSee('Selendang Batik Solo');
        $gpResponse->assertDontSee('Kain Subkategori Nonaktif');
        $gpResponse->assertDontSee('Subkategori Nonaktif');
        $this->assertSame(1, substr_count($gpResponse->getContent(), 'Selendang Batik Solo'));

        // Pada /produk, chip kategori induk juga harus menghitung produk descendant secara unik (1, bukan 0 atau 2).
        $catalogResponse = $this->get('/produk');
        $catalogResponse->assertStatus(200);
        $catalogResponse->assertSeeInOrder(['Kriya Nusantara', '1']);

        // Buka subkategori terdalam: cek breadcrumb hierarkis lengkap.
        $childResponse = $this->get('/kategori/'.$childA->slug);
        $childResponse->assertStatus(200);
        $childResponse->assertSee('Beranda');
        $childResponse->assertSee('Katalog Produk');
        $childResponse->assertSee('Kriya Nusantara');
        $childResponse->assertSee('Tekstil Tradisional');
        $childResponse->assertSee('Batik Tulis');
    }

    public function test_category_cycle_protection_prevents_infinite_loop(): void
    {
        $catA = Category::create(['name' => 'Kategori A', 'slug' => 'kategori-a', 'is_active' => true]);
        $catB = Category::create(['parent_id' => $catA->id, 'name' => 'Kategori B', 'slug' => 'kategori-b', 'is_active' => true]);

        // Simulasikan siklus corrupt di database (A -> parent B, B -> parent A)
        DB::table('categories')->where('id', $catA->id)->update(['parent_id' => $catB->id]);

        $response = $this->get('/kategori/kategori-a');
        $response->assertStatus(200);
        $response->assertSee('Kategori A');
    }

    public function test_category_seo_rejects_external_canonical_escapes_xss_and_avoids_duplicate_schema(): void
    {
        $category = Category::create([
            'name' => 'Keramik <script>alert(1)</script>',
            'slug' => 'keramik-aman',
            'description' => 'Deskripsi aman <img src=x onerror=alert(1)>',
            'is_active' => true,
            'canonical_url' => 'https://spam-eksternal.example.com/keramik',
        ]);

        $response = $this->get('/kategori/'.$category->slug);
        $response->assertStatus(200);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertSame(1, substr_count($html, '"@type":"OnlineStore"'));
        $this->assertSame(1, substr_count($html, '"@type":"BreadcrumbList"'));

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
        $response->assertDontSee('https://spam-eksternal.example.com/keramik', false);
        $response->assertSee('<link rel="canonical" href="'.route('storefront.category', $category->slug).'">', false);
    }

    public function test_maintenance_mode_blocks_catalog_and_category_for_guests_and_homepage_remains_compatible(): void
    {
        $category = Category::create([
            'name' => 'Minuman Herbal',
            'slug' => 'minuman-herbal',
            'is_active' => true,
        ]);

        // Pastikan homepage tetap bekerja dan terhubung ke route katalog/kategori.
        $this->get('/')
            ->assertStatus(200)
            ->assertSee(route('storefront.catalog'), false)
            ->assertSee(route('storefront.category', $category->slug), false);

        Setting::updateOrCreate(
            ['group' => 'store', 'key' => 'maintenance_mode'],
            ['value' => '1', 'type' => 'boolean', 'is_public' => false]
        );

        $this->get('/produk')->assertStatus(503);
        $this->get('/kategori/'.$category->slug)->assertStatus(503);

        $this->seed(RolePermissionSeeder::class);
        $admin = User::create([
            'name' => 'Admin Pemeliharaan',
            'email' => 'maint@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail()->id);

        $this->actingAs($admin, 'admin')->get('/produk')->assertStatus(200);
        $this->actingAs($admin, 'admin')->get('/kategori/'.$category->slug)->assertStatus(200);
    }
}
