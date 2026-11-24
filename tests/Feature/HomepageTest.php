<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Storefront\HomepageService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        Cache::flush();
    }

    public function test_homepage_renders_clean_empty_state_without_fake_data_when_database_has_no_catalog(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Katalog Produk Belum Tersedia');
        $response->assertDontSee('Produk Terlaris');
        $response->assertDontSee('Kata Pelanggan Kami');
        $response->assertDontSee('Artikel &amp; Cerita UMKM', false);
        $response->assertSee('Buka Katalog Produk');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertSame(1, substr_count($html, '"@type":"OnlineStore"'));
    }

    public function test_homepage_renders_complete_sections_with_valid_database_data(): void
    {
        $author = User::create([
            'name' => 'Editor Toko',
            'email' => 'editor@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Kopi Nusantara',
            'slug' => 'kopi-nusantara',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = Product::create([
            'sku' => 'KOPI-PREM-01',
            'name' => 'Kopi Arabika Flores',
            'slug' => 'kopi-arabika-flores',
            'description' => 'Biji kopi sangrai pilihan.',
            'price' => 95000,
            'stock' => 15,
            'reserved_stock' => 2,
            'weight_grams' => 250,
            'is_featured' => true,
            'is_active' => true,
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
        ProductImage::create([
            'product_id' => $product->id,
            'image_path' => 'products/flores.jpg',
            'alt_text' => 'Kemasan Kopi Arabika Flores',
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        Banner::create([
            'title' => 'Panen Raya UMKM',
            'subtitle' => 'Produk lokal langsung dari pengrajin daerah.',
            'placement' => 'hero',
            'image_desktop_path' => 'banners/hero-panen.jpg',
            'alt_text' => 'Banner Panen Raya UMKM',
            'cta_label' => 'Belanja Sekarang',
            'cta_url' => '/produk',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Promo Kemasan Hemat',
            'subtitle' => 'Pengemasan aman ke seluruh Indonesia.',
            'placement' => 'promo_middle',
            'image_desktop_path' => 'banners/promo-kemasan.jpg',
            'cta_label' => 'Lihat Promo',
            'cta_url' => '/produk?promo=1',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        Testimonial::create([
            'customer_name' => 'Budi Santoso',
            'customer_role_or_city' => 'Yogyakarta',
            'rating' => 5,
            'quote' => 'Pengiriman cepat dan kopinya sangat segar.',
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        Article::create([
            'title' => 'Cara Menyeduh Kopi Tubruk Nikmat',
            'slug' => 'cara-menyeduh-kopi-tubruk-nikmat',
            'excerpt' => 'Panduan sederhana menyeduh kopi Nusantara di rumah.',
            'content' => '<p>Konten artikel.</p>',
            'author_id' => $author->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Panen Raya UMKM');
        $response->assertSee('Kopi Nusantara');
        $response->assertSee('1 produk');
        $response->assertSee('Kopi Arabika Flores');
        $response->assertSee('Stok: 13');
        $response->assertSee('Promo Kemasan Hemat');
        $response->assertSee('Budi Santoso');
        $response->assertSee('Cara Menyeduh Kopi Tubruk Nikmat');
        $response->assertSee('loading="lazy"', false);
        $response->assertSee('fetchpriority="high"', false);
    }

    public function test_banners_respect_active_status_and_schedule_window(): void
    {
        Banner::create([
            'title' => 'Banner Aktif Sekarang',
            'placement' => 'hero',
            'image_desktop_path' => 'banners/aktif.jpg',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Banner Kedaluwarsa',
            'placement' => 'hero',
            'image_desktop_path' => 'banners/expired.jpg',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->subMinute(),
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Banner Masa Depan',
            'placement' => 'hero',
            'image_desktop_path' => 'banners/future.jpg',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'is_active' => true,
        ]);

        Banner::create([
            'title' => 'Banner Nonaktif',
            'placement' => 'promo_middle',
            'image_desktop_path' => 'banners/off.jpg',
            'is_active' => false,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Banner Aktif Sekarang');
        $response->assertDontSee('Banner Kedaluwarsa');
        $response->assertDontSee('Banner Masa Depan');
        $response->assertDontSee('Banner Nonaktif');
    }

    public function test_featured_and_latest_products_exclude_inactive_and_out_of_stock_items(): void
    {
        Product::create([
            'sku' => 'AKTIF-1',
            'name' => 'Tas Anyaman Aktif',
            'slug' => 'tas-anyaman-aktif',
            'description' => 'Produk siap jual.',
            'price' => 120000,
            'stock' => 5,
            'reserved_stock' => 1,
            'is_featured' => true,
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'HABIS-1',
            'name' => 'Tas Anyaman Terpesan Penuh',
            'slug' => 'tas-anyaman-terpesan-penuh',
            'description' => 'Semua stok sedang direservasi.',
            'price' => 120000,
            'stock' => 4,
            'reserved_stock' => 4,
            'is_featured' => true,
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'NONAKTIF-1',
            'name' => 'Tas Anyaman Nonaktif',
            'slug' => 'tas-anyaman-nonaktif',
            'description' => 'Produk dinonaktifkan.',
            'price' => 120000,
            'stock' => 10,
            'reserved_stock' => 0,
            'is_featured' => true,
            'is_active' => false,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Tas Anyaman Aktif');
        $response->assertDontSee('Tas Anyaman Terpesan Penuh');
        $response->assertDontSee('Tas Anyaman Nonaktif');
    }

    public function test_bestselling_products_aggregate_only_valid_paid_orders_without_duplicates(): void
    {
        $validBestseller = Product::create([
            'sku' => 'LARIS-1',
            'name' => 'Sambal Roa Juara',
            'slug' => 'sambal-roa-juara',
            'description' => 'Sambal khas.',
            'price' => 45000,
            'stock' => 50,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $cancelledOnlyProduct = Product::create([
            'sku' => 'BATAL-1',
            'name' => 'Keripik Pesanan Batal',
            'slug' => 'keripik-pesanan-batal',
            'description' => 'Tidak boleh jadi terlaris.',
            'price' => 25000,
            'stock' => 50,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        // 2 order valid berbeda untuk produk yang sama -> harus tergabung (total 7) dan tidak duplikat.
        $paidOrder1 = Order::create([
            'order_number' => 'PLN-TEST-001',
            'customer_name_snapshot' => 'Andi',
            'customer_email_snapshot' => 'andi@example.com',
            'customer_phone_snapshot' => '0811111111',
            'order_status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 135000,
            'grand_total' => 135000,
            'total_weight_grams' => 300,
        ]);
        OrderItem::create([
            'order_id' => $paidOrder1->id,
            'product_id' => $validBestseller->id,
            'product_name_snapshot' => $validBestseller->name,
            'sku_snapshot' => $validBestseller->sku,
            'unit_price_snapshot' => 45000,
            'quantity' => 3,
            'line_total' => 135000,
        ]);

        $paidOrder2 = Order::create([
            'order_number' => 'PLN-TEST-002',
            'customer_name_snapshot' => 'Siti',
            'customer_email_snapshot' => 'siti@example.com',
            'customer_phone_snapshot' => '0822222222',
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'subtotal' => 180000,
            'grand_total' => 180000,
            'total_weight_grams' => 400,
        ]);
        OrderItem::create([
            'order_id' => $paidOrder2->id,
            'product_id' => $validBestseller->id,
            'product_name_snapshot' => $validBestseller->name,
            'sku_snapshot' => $validBestseller->sku,
            'unit_price_snapshot' => 45000,
            'quantity' => 4,
            'line_total' => 180000,
        ]);

        // Order unpaid & cancelled/refunded tidak boleh dihitung.
        $unpaidOrder = Order::create([
            'order_number' => 'PLN-TEST-003',
            'customer_name_snapshot' => 'Dodi',
            'customer_email_snapshot' => 'dodi@example.com',
            'customer_phone_snapshot' => '0833333333',
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'subtotal' => 250000,
            'grand_total' => 250000,
            'total_weight_grams' => 500,
        ]);
        OrderItem::create([
            'order_id' => $unpaidOrder->id,
            'product_id' => $cancelledOnlyProduct->id,
            'product_name_snapshot' => $cancelledOnlyProduct->name,
            'sku_snapshot' => $cancelledOnlyProduct->sku,
            'unit_price_snapshot' => 25000,
            'quantity' => 10,
            'line_total' => 250000,
        ]);

        $refundedOrder = Order::create([
            'order_number' => 'PLN-TEST-004',
            'customer_name_snapshot' => 'Tono',
            'customer_email_snapshot' => 'tono@example.com',
            'customer_phone_snapshot' => '0844444444',
            'order_status' => 'refunded',
            'payment_status' => 'paid',
            'subtotal' => 200000,
            'grand_total' => 200000,
            'total_weight_grams' => 500,
        ]);
        OrderItem::create([
            'order_id' => $refundedOrder->id,
            'product_id' => $cancelledOnlyProduct->id,
            'product_name_snapshot' => $cancelledOnlyProduct->name,
            'sku_snapshot' => $cancelledOnlyProduct->sku,
            'unit_price_snapshot' => 25000,
            'quantity' => 8,
            'line_total' => 200000,
        ]);

        $homepageData = app(HomepageService::class)->getHomepageData();

        $this->assertCount(1, $homepageData['bestsellingProducts']);
        $this->assertSame($validBestseller->id, $homepageData['bestsellingProducts']->first()->id);
        $this->assertSame(7, (int) $homepageData['bestsellingProducts']->first()->valid_sold_count);

        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Produk Terlaris');
        $response->assertSee('Terjual 7');
    }

    public function test_promo_products_validate_lower_promo_price_and_active_schedule(): void
    {
        $activePromo = Product::create([
            'sku' => 'PROMO-OK',
            'name' => 'Madu Hutan Promo',
            'slug' => 'madu-hutan-promo',
            'description' => 'Promo valid.',
            'price' => 100000,
            'promo_price' => 75000,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
            'stock' => 10,
            'is_active' => true,
        ]);

        $expiredPromo = Product::create([
            'sku' => 'PROMO-EXP',
            'name' => 'Gula Aren Promo Lewat',
            'slug' => 'gula-aren-promo-lewat',
            'description' => 'Promo sudah berakhir.',
            'price' => 50000,
            'promo_price' => 35000,
            'promo_start_at' => now()->subDays(5),
            'promo_end_at' => now()->subHour(),
            'stock' => 10,
            'is_active' => true,
        ]);

        $invalidHigherPromo = Product::create([
            'sku' => 'PROMO-INVALID',
            'name' => 'Teh Herbal Harga Naik',
            'slug' => 'teh-herbal-harga-naik',
            'description' => 'Promo >= harga normal tidak valid.',
            'price' => 40000,
            'promo_price' => 45000,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
            'stock' => 10,
            'is_active' => true,
        ]);

        $homepageData = app(HomepageService::class)->getHomepageData();

        $this->assertTrue($homepageData['promoProducts']->contains('id', $activePromo->id));
        $this->assertFalse($homepageData['promoProducts']->contains('id', $expiredPromo->id));
        $this->assertFalse($homepageData['promoProducts']->contains('id', $invalidHigherPromo->id));
        $this->assertSame(75000.0, $activePromo->effectivePrice());
        $this->assertSame(50000.0, $expiredPromo->effectivePrice());
        $this->assertSame(40000.0, $invalidHigherPromo->effectivePrice());
    }

    public function test_variant_product_uses_active_variant_stock_and_lowest_active_price(): void
    {
        $product = Product::create([
            'sku' => 'BATIK-VAR',
            'name' => 'Kemeja Batik Tenun',
            'slug' => 'kemeja-batik-tenun',
            'description' => 'Produk bervarian.',
            'price' => 300000,
            'stock' => 0,
            'reserved_stock' => 0,
            'has_variants' => true,
            'is_featured' => true,
            'is_active' => true,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
        ]);

        // Varian nonaktif paling murah tidak boleh dihitung.
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'BATIK-XS',
            'name' => 'Ukuran XS',
            'price' => 90000,
            'stock' => 20,
            'reserved_stock' => 0,
            'is_active' => false,
        ]);

        // Varian aktif S: stok tersedia = 5 - 2 = 3, harga promo 175000.
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'BATIK-S',
            'name' => 'Ukuran S',
            'price' => 200000,
            'promo_price' => 175000,
            'stock' => 5,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);

        // Varian aktif L: stok tersedia = 8 - 1 = 7, harga 240000.
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'BATIK-L',
            'name' => 'Ukuran L',
            'price' => 240000,
            'stock' => 8,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $freshProduct = Product::with('variants')->findOrFail($product->id);

        $this->assertSame(10, $freshProduct->availableStock());
        $pricing = $freshProduct->displayPricing();
        $this->assertSame(175000.0, $pricing['effective_price']);
        $this->assertSame(200000.0, $pricing['regular_price']);
        $this->assertTrue($pricing['is_promo']);
        $this->assertTrue($pricing['starts_from']);

        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Kemeja Batik Tenun');
        $response->assertSee('Stok: 10');
        $response->assertSee('Rp 175.000');
        $response->assertDontSee('Rp 90.000');
    }

    public function test_testimonials_and_articles_filter_featured_and_published_records(): void
    {
        $author = User::create([
            'name' => 'Penulis',
            'email' => 'penulis@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        Testimonial::create([
            'customer_name' => 'Testimoni Disetujui',
            'rating' => 5,
            'quote' => 'Kualitas produk sangat memuaskan.',
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        Testimonial::create([
            'customer_name' => 'Testimoni Belum Tampil',
            'rating' => 4,
            'quote' => 'Menunggu kurasi.',
            'is_featured' => false,
            'sort_order' => 2,
        ]);

        Article::create([
            'title' => 'Artikel Tayang Sekarang',
            'slug' => 'artikel-tayang-sekarang',
            'content' => '<p>Isi artikel.</p>',
            'author_id' => $author->id,
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        Article::create([
            'title' => 'Artikel Draft Rahasia',
            'slug' => 'artikel-draft-rahasia',
            'content' => '<p>Draft.</p>',
            'author_id' => $author->id,
            'status' => 'draft',
        ]);

        Article::create([
            'title' => 'Artikel Jadwal Besok',
            'slug' => 'artikel-jadwal-besok',
            'content' => '<p>Terjadwal.</p>',
            'author_id' => $author->id,
            'status' => 'published',
            'published_at' => now()->addDay(),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Testimoni Disetujui');
        $response->assertDontSee('Testimoni Belum Tampil');
        $response->assertSee('Artikel Tayang Sekarang');
        $response->assertDontSee('Artikel Draft Rahasia');
        $response->assertDontSee('Artikel Jadwal Besok');
    }

    public function test_homepage_escapes_xss_payloads_sanitizes_cta_urls_and_enforces_seo_tags(): void
    {
        Banner::create([
            'title' => 'Judul <script>alert("xss")</script>',
            'subtitle' => 'Subjudul aman',
            'placement' => 'hero',
            'image_desktop_path' => 'banners/hero.jpg',
            'cta_label' => 'Klik Bahaya',
            'cta_url' => 'javascript:alert(1)',
            'is_active' => true,
        ]);

        Product::create([
            'sku' => 'XSS-01',
            'name' => 'Produk <img src=x onerror=alert(1)>',
            'slug' => 'produk-xss-test',
            'description' => 'Aman dari XSS.',
            'price' => 50000,
            'stock' => 5,
            'is_active' => true,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
        $response->assertDontSee('javascript:alert(1)', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertSame(1, substr_count($html, '"@type":"OnlineStore"'));
        $response->assertSee('<link rel="canonical" href="'.route('storefront.home').'">', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('name="twitter:card"', false);
    }

    public function test_homepage_cache_populates_invalidates_on_model_changes_and_filters_expired_items(): void
    {
        $product = Product::create([
            'sku' => 'CACHE-01',
            'name' => 'Kerajinan Bambu Awal',
            'slug' => 'kerajinan-bambu-awal',
            'description' => 'Produk cache.',
            'price' => 75000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $this->get('/')->assertStatus(200)->assertSee('Kerajinan Bambu Awal');
        $this->assertTrue(Cache::has(HomepageService::CACHE_KEY));

        // Update via Eloquent harus otomatis menghapus cache homepage.
        $product->update(['name' => 'Kerajinan Bambu Terbaru']);
        $this->assertFalse(Cache::has(HomepageService::CACHE_KEY));

        $this->get('/')->assertStatus(200)->assertSee('Kerajinan Bambu Terbaru');
        $this->assertTrue(Cache::has(HomepageService::CACHE_KEY));

        // Simulasikan banner berjadwal hampir habis; setelah waktu melewati ends_at,
        // item harus tersaring keluar meski cache belum kedaluwarsa.
        Banner::create([
            'title' => 'Banner Kilat 1 Menit',
            'placement' => 'hero',
            'image_desktop_path' => 'banners/kilat.jpg',
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addMinute(),
            'is_active' => true,
        ]);

        $this->get('/')->assertSee('Banner Kilat 1 Menit');
        $this->travel(2)->minutes();
        $this->get('/')->assertDontSee('Banner Kilat 1 Menit');

        // Bulk DB update tidak memicu event model, sehingga clearCache() membersihkan cache secara eksplisit.
        DB::table('products')->where('id', $product->id)->update(['name' => 'Kerajinan Bambu Bulk']);
        HomepageService::clearCache();
        $this->get('/')->assertSee('Kerajinan Bambu Bulk');
    }
}
