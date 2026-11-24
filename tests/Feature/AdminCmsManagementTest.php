<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCmsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ContentSeeder::class);
    }

    protected function createAdminWithRole(string $roleSlug): User
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = User::create([
            'name' => 'Admin '.$suffix,
            'email' => "adm-{$roleSlug}-{$suffix}@example.com",
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    public function test_admin_article_crud_preview_publish_sanitization_and_storefront_visibility(): void
    {
        Storage::fake('public');

        $storeAdmin = $this->createAdminWithRole('store-admin');

        // 1. Create draft article with XSS attempt and featured image
        $response = $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => 'Cerita Kopi Gayo Asli',
                'slug' => '',
                'excerpt' => 'Kisah petani kopi dataran tinggi Aceh.',
                'content' => '<h2>Panen Pertama</h2><p>Kualitas biji terbaik.</p><script>alert("xss")</script><img src="javascript:alert(1)" onerror="alert(2)">',
                'featured_image' => UploadedFile::fake()->image('gayo.jpg', 800, 450),
                'featured_image_alt' => 'Kebun Kopi Gayo',
                'author_id' => $storeAdmin->id,
                'status' => 'draft',
                'seo_title' => 'Kopi Gayo Pilihan — Pasar Lokal',
                'seo_description' => 'Artikel seputar kopi Gayo pilihan UMKM.',
            ]);

        $article = Article::where('slug', 'cerita-kopi-gayo-asli')->firstOrFail();
        $response->assertRedirect(route('admin.articles.edit', $article));
        Storage::disk('public')->assertExists($article->featured_image_path);

        // Sanitizer stripped dangerous tags/urls
        $this->assertStringNotContainsString('<script>', $article->content);
        $this->assertStringNotContainsString('javascript:', $article->content);
        $this->assertStringContainsString('<h2>Panen Pertama</h2>', $article->content);

        // 2. Draft article MUST NOT be visible on storefront
        $this->get(route('storefront.articles'))
            ->assertOk()
            ->assertDontSee('Cerita Kopi Gayo Asli');

        $this->get(route('storefront.article', $article->slug))
            ->assertNotFound();

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('Cerita Kopi Gayo Asli');

        // 3. Admin can preview draft article (both saved preview and unsaved draft preview)
        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.articles.preview', $article))
            ->assertOk()
            ->assertSee('Mode Pratinjau Admin')
            ->assertSee('Cerita Kopi Gayo Asli');

        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.articles.preview.draft'), [
                'article_id' => $article->id,
                'title' => 'Judul Pratinjau Belum Disimpan',
                'content' => '<p>Konten pratinjau aman</p><script>alert(1)</script>',
                'status' => 'draft',
            ])
            ->assertOk()
            ->assertSee('Judul Pratinjau Belum Disimpan')
            ->assertDontSee('<script>alert(1)</script>', false);

        // 4. Duplicate slug is rejected
        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => 'Artikel Lain',
                'slug' => 'cerita-kopi-gayo-asli',
                'content' => '<p>Isi artikel lain.</p>',
                'status' => 'draft',
            ])
            ->assertSessionHasErrors('slug');

        // 5. Future-scheduled published article MUST NOT appear on storefront yet
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.articles.update', $article), [
                'title' => 'Cerita Kopi Gayo Asli',
                'slug' => 'cerita-kopi-gayo-asli',
                'excerpt' => 'Kisah petani kopi dataran tinggi Aceh.',
                'content' => '<p>Konten terbit terjadwal.</p>',
                'author_id' => $storeAdmin->id,
                'status' => 'published',
                'published_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect(route('admin.articles.edit', $article));

        $this->get(route('storefront.article', $article->slug))->assertNotFound();

        // 6. Publish immediately -> visible on storefront & home
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.articles.update', $article), [
                'title' => 'Cerita Kopi Gayo Asli',
                'slug' => 'cerita-kopi-gayo-asli',
                'excerpt' => 'Kisah petani kopi dataran tinggi Aceh.',
                'content' => '<h2>Panen Pertama</h2><p>Kualitas biji terbaik siap seduh.</p>',
                'author_id' => $storeAdmin->id,
                'status' => 'published',
                'published_at' => now()->subMinute()->format('Y-m-d\TH:i'),
                'seo_title' => 'SEO Kopi Gayo',
                'seo_description' => 'Meta deskripsi Kopi Gayo.',
            ])
            ->assertRedirect(route('admin.articles.edit', $article));

        $this->get(route('storefront.articles'))
            ->assertOk()
            ->assertSee('Cerita Kopi Gayo Asli');

        $this->get(route('storefront.article', $article->slug))
            ->assertOk()
            ->assertSee('Cerita Kopi Gayo Asli')
            ->assertSee('Kualitas biji terbaik siap seduh.')
            ->assertSee('SEO Kopi Gayo')
            ->assertSee('Meta deskripsi Kopi Gayo.');

        // 7. Unpublish -> immediately hidden on storefront
        $this->actingAs($storeAdmin, 'admin')
            ->patch(route('admin.articles.unpublish', $article))
            ->assertRedirect();

        $this->get(route('storefront.article', $article->slug))->assertNotFound();

        // 8. Re-publish then safe delete (soft delete + draft)
        $this->actingAs($storeAdmin, 'admin')
            ->patch(route('admin.articles.publish', $article))
            ->assertRedirect();

        $this->actingAs($storeAdmin, 'admin')
            ->delete(route('admin.articles.destroy', $article))
            ->assertRedirect(route('admin.articles.index'));

        $trashed = Article::withTrashed()->findOrFail($article->id);
        $this->assertSoftDeleted($trashed);
        $this->assertSame('draft', $trashed->status);
        $this->get(route('storefront.article', $article->slug))->assertNotFound();
    }

    public function test_admin_about_page_update_preview_and_storefront_sync(): void
    {
        Storage::fake('public');

        $storeAdmin = $this->createAdminWithRole('store-admin');

        // Preview unsaved changes before publishing
        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.about.preview.draft'), [
                'title' => 'Tentang Pasar Lokal Preview',
                'subtitle' => 'Subjudul Pratinjau',
                'content' => '<p>Cerita pratinjau</p><script>alert(9)</script>',
                'vision' => 'Visi Nusantara',
                'mission' => 'Misi UMKM Mandiri',
                'is_published' => '0',
            ])
            ->assertOk()
            ->assertSee('Mode Pratinjau Admin')
            ->assertSee('Tentang Pasar Lokal Preview')
            ->assertSee('Visi Nusantara')
            ->assertDontSee('<script>alert(9)</script>', false);

        // Save unpublished Tentang Kami -> Admin preview works, storefront returns 404
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.about.update'), [
                'title' => 'Tentang Pasar Lokal Nusantara',
                'subtitle' => 'Menguatkan Ekonomi Kreatif Daerah',
                'content' => '<h2>Profil Kami</h2><p>Menghadirkan produk pengrajin lokal.</p>',
                'banner_image' => UploadedFile::fake()->image('about-banner.png', 1200, 400),
                'vision' => 'Menjadi jembatan utama UMKM Indonesia.',
                'mission' => 'Kurasi produk lokal berkualitas tinggi.',
                'business_profile' => 'Berdiri sejak 2024 bersama 120 mitra UMKM.',
                'is_published' => '0',
                'seo_title' => 'Tentang Kami — Pasar Lokal',
                'seo_description' => 'Profil lengkap Pasar Lokal Nusantara.',
            ])
            ->assertRedirect(route('admin.about.edit'));

        $this->get(route('storefront.about'))->assertNotFound();

        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.about.preview'))
            ->assertOk()
            ->assertSee('Tentang Pasar Lokal Nusantara');

        // Publish Tentang Kami -> storefront renders all updated fields & SEO
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.about.update'), [
                'title' => 'Tentang Pasar Lokal Nusantara',
                'subtitle' => 'Menguatkan Ekonomi Kreatif Daerah',
                'content' => '<h2>Profil Kami</h2><p>Menghadirkan produk pengrajin lokal.</p>',
                'vision' => 'Menjadi jembatan utama UMKM Indonesia.',
                'mission' => 'Kurasi produk lokal berkualitas tinggi.',
                'business_profile' => 'Berdiri sejak 2024 bersama 120 mitra UMKM.',
                'is_published' => '1',
                'seo_title' => 'Tentang Kami — Pasar Lokal',
                'seo_description' => 'Profil lengkap Pasar Lokal Nusantara.',
            ])
            ->assertRedirect(route('admin.about.edit'));

        $this->get(route('storefront.about'))
            ->assertOk()
            ->assertSee('Tentang Pasar Lokal Nusantara')
            ->assertSee('Menguatkan Ekonomi Kreatif Daerah')
            ->assertSee('Menjadi jembatan utama UMKM Indonesia.')
            ->assertSee('Berdiri sejak 2024 bersama 120 mitra UMKM.')
            ->assertSee('Tentang Kami — Pasar Lokal')
            ->assertSee('Profil lengkap Pasar Lokal Nusantara.');
    }

    public function test_admin_faq_crud_toggle_and_storefront_visibility(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');

        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.faqs.store'), [
                'category' => 'Pengiriman',
                'question' => 'Apakah tersedia pengiriman same-day?',
                'answer' => '<p>Ya, tersedia untuk area tertentu.</p><script>bad()</script>',
                'sort_order' => 10,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.faqs.index'));

        $faq = Faq::where('question', 'Apakah tersedia pengiriman same-day?')->firstOrFail();
        $this->assertStringNotContainsString('<script>', $faq->answer);

        // Active FAQ appears on storefront /faq
        $this->get(route('storefront.faq'))
            ->assertOk()
            ->assertSee('Apakah tersedia pengiriman same-day?')
            ->assertSee('Ya, tersedia untuk area tertentu.');

        // Toggle inactive -> hidden from storefront /faq
        $this->actingAs($storeAdmin, 'admin')
            ->patch(route('admin.faqs.toggle', $faq))
            ->assertRedirect();

        $faq->refresh();
        $this->assertFalse($faq->is_active);

        $this->get(route('storefront.faq'))
            ->assertOk()
            ->assertDontSee('Apakah tersedia pengiriman same-day?');

        // Delete FAQ
        $this->actingAs($storeAdmin, 'admin')
            ->delete(route('admin.faqs.destroy', $faq))
            ->assertRedirect(route('admin.faqs.index'));

        $this->assertNull(Faq::find($faq->id));
    }

    public function test_admin_contact_update_validation_and_storefront_sync(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');

        // Reject unsafe URL scheme on social link
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.contact.update'), [
                'title' => 'Hubungi Kami',
                'is_published' => '1',
                'social_instagram' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('social_instagram');

        // Valid contact update syncs to /kontak and footer
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.contact.update'), [
                'title' => 'Hubungi Tim Kami',
                'content' => '<p>Kami siap melayani pesanan UMKM Anda setiap hari kerja.</p>',
                'is_published' => '1',
                'seo_title' => 'Kontak Resmi Pasar Lokal',
                'seo_description' => 'Alamat, WhatsApp, email, dan jam operasional toko.',
                'store_address' => 'Jl. Braga No. 88, Bandung',
                'store_whatsapp' => '081299998888',
                'store_phone' => '02287654321',
                'store_email' => 'halo@pasarlokal.test',
                'store_hours' => 'Senin - Minggu, 08:00 - 20:00 WIB',
                'social_instagram' => 'https://instagram.com/pasarlokalnusantara',
                'social_facebook' => 'https://facebook.com/pasarlokalnusantara',
                'contact_additional_info' => 'Respon chat rata-rata dalam 15 menit.',
            ])
            ->assertRedirect(route('admin.contact.edit'));

        $this->get(route('storefront.contact'))
            ->assertOk()
            ->assertSee('Hubungi Tim Kami')
            ->assertSee('Jl. Braga No. 88, Bandung')
            ->assertSee('081299998888')
            ->assertSee('halo@pasarlokal.test')
            ->assertSee('https://instagram.com/pasarlokalnusantara')
            ->assertSee('Respon chat rata-rata dalam 15 menit.');

        // Policy pages seeded by ContentSeeder also work properly
        $this->get(route('storefront.page', 'kebijakan-privasi'))
            ->assertOk()
            ->assertSee('Kebijakan Privasi');
    }

    public function test_rbac_blocks_unauthorized_admin_roles_from_cms_routes_and_sidebar(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');
        $productManager = $this->createAdminWithRole('product-manager');
        $orderManager = $this->createAdminWithRole('order-manager');
        $support = $this->createAdminWithRole('customer-support');

        $article = Article::create([
            'title' => 'Artikel Uji RBAC',
            'slug' => 'artikel-uji-rbac',
            'content' => '<p>Konten uji.</p>',
            'author_id' => $storeAdmin->id,
            'status' => 'draft',
        ]);
        $faq = Faq::firstOrFail();

        // Store admin sees CMS menu in dashboard sidebar
        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.about.edit'), false)
            ->assertSee(route('admin.faqs.index'), false)
            ->assertSee(route('admin.contact.edit'), false);

        foreach ([$productManager, $orderManager, $support] as $unauthorized) {
            $this->actingAs($unauthorized, 'admin')->get(route('admin.articles.index'))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->post(route('admin.articles.store'), ['title' => 'X', 'content' => 'Y', 'status' => 'draft'])->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->get(route('admin.articles.preview', $article))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->delete(route('admin.articles.destroy', $article))->assertForbidden();

            $this->actingAs($unauthorized, 'admin')->get(route('admin.about.edit'))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->put(route('admin.about.update'), ['title' => 'X', 'content' => 'Y'])->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->get(route('admin.about.preview'))->assertForbidden();

            $this->actingAs($unauthorized, 'admin')->get(route('admin.faqs.index'))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->post(route('admin.faqs.store'), ['category' => 'Umum', 'question' => 'Q', 'answer' => 'A', 'sort_order' => 1])->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->patch(route('admin.faqs.toggle', $faq))->assertForbidden();

            $this->actingAs($unauthorized, 'admin')->get(route('admin.contact.edit'))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->put(route('admin.contact.update'), ['title' => 'Kontak'])->assertForbidden();
        }

        // Product manager does not see CMS links in sidebar
        $this->actingAs($productManager, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.articles.index'), false)
            ->assertDontSee(route('admin.faqs.index'), false);
    }
}
