<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use App\Services\Storefront\StorefrontContentService;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPagesAndPoliciesTest extends TestCase
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
            'email' => "adm-pages-{$roleSlug}-{$suffix}@example.com",
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    public function test_four_builtin_policy_pages_can_be_edited_with_locked_slug_and_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $storeAdmin = $this->createAdminWithRole('store-admin');

        $indexResponse = $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.pages.index'))
            ->assertOk();

        foreach (StorefrontContentService::POLICY_SLUGS as $slug) {
            $indexResponse->assertSee('/'.$slug);
        }

        $privacy = Page::where('slug', 'kebijakan-privasi')->firstOrFail();

        // Admin cannot change built-in policy slug even if crafted slug is submitted
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.pages.update', $privacy), [
                'title' => 'Kebijakan Privasi Terbaru',
                'slug' => 'slug-palsu-diubah',
                'content' => '<h2>Privasi Pelanggan</h2><p>Isi kebijakan privasi yang telah diperbarui.</p>',
                'banner_image' => UploadedFile::fake()->image('privacy-banner.jpg', 1200, 400),
                'seo_title' => 'Privasi Resmi — Pasar Lokal Nusantara',
                'seo_description' => 'Dokumen kebijakan privasi resmi pelanggan.',
                'is_published' => '1',
                'show_in_footer' => '0', // Built-in policies always remain eligible for footer when enabled
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.pages.edit', $privacy));

        $privacy->refresh();
        $this->assertSame('kebijakan-privasi', $privacy->slug);
        $this->assertSame('Kebijakan Privasi Terbaru', $privacy->title);
        $this->assertTrue($privacy->show_in_footer);
        Storage::disk('public')->assertExists($privacy->banner_image_path);

        // Built-in policy page cannot be deleted (PagePolicy::delete returns false -> 403)
        $this->actingAs($storeAdmin, 'admin')
            ->delete(route('admin.pages.destroy', $privacy))
            ->assertForbidden();

        $this->assertNotNull(Page::where('slug', 'kebijakan-privasi')->first());
    }

    public function test_admin_can_create_preview_search_paginate_edit_and_delete_custom_page(): void
    {
        Storage::fake('public');
        $storeAdmin = $this->createAdminWithRole('store-admin');

        // Preview draft before saving
        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.preview.draft'), [
                'title' => 'Program Kemitraan UMKM',
                'slug' => 'program-kemitraan-umkm',
                'content' => '<p>Daftarkan produk lokal terbaik Anda.</p><script>alert(1)</script>',
                'is_published' => '0',
                'show_in_footer' => '1',
            ])
            ->assertOk()
            ->assertSee('Mode Pratinjau Admin')
            ->assertSee('Program Kemitraan UMKM')
            ->assertDontSee('<script>alert(1)</script>', false);

        // Create custom page with auto-slug & optional banner
        $createResponse = $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.store'), [
                'title' => 'Program Reseller Nusantara',
                'slug' => '',
                'content' => '<h2>Cara Bergabung</h2><p>Nikmati harga grosir khusus mitra reseller.</p>',
                'banner_image' => UploadedFile::fake()->image('reseller.png', 1000, 350),
                'seo_title' => 'Program Reseller — Pasar Lokal',
                'seo_description' => 'Gabung menjadi reseller resmi Pasar Lokal Nusantara.',
                'is_published' => '1',
                'show_in_footer' => '1',
                'sort_order' => 15,
            ]);

        $customPage = Page::where('slug', 'program-reseller-nusantara')->firstOrFail();
        $createResponse->assertRedirect(route('admin.pages.edit', $customPage));
        Storage::disk('public')->assertExists($customPage->banner_image_path);
        $oldBanner = $customPage->banner_image_path;

        // Saved preview works
        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.pages.preview', $customPage))
            ->assertOk()
            ->assertSee('Mode Pratinjau Admin')
            ->assertSee('Program Reseller Nusantara');

        // Edit custom page & remove banner
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.pages.update', $customPage), [
                'title' => 'Program Reseller Resmi',
                'slug' => 'program-reseller-resmi',
                'content' => '<p>Syarat reseller resmi telah diperbarui.</p>',
                'remove_banner_image' => '1',
                'seo_title' => 'Reseller Resmi',
                'seo_description' => 'Info kemitraan reseller resmi.',
                'is_published' => '1',
                'show_in_footer' => '1',
                'sort_order' => 15,
            ])
            ->assertRedirect(route('admin.pages.edit', $customPage));

        $customPage->refresh();
        $this->assertSame('program-reseller-resmi', $customPage->slug);
        $this->assertNull($customPage->banner_image_path);
        Storage::disk('public')->assertMissing($oldBanner);

        // Old slug returns 404, new slug returns 200
        $this->get('/program-reseller-nusantara')->assertNotFound();
        $this->get('/program-reseller-resmi')->assertOk()->assertSee('Program Reseller Resmi');

        // Search & pagination check (> 12 pages)
        for ($i = 1; $i <= 10; $i++) {
            Page::create([
                'title' => "Halaman Panduan {$i}",
                'slug' => "halaman-panduan-{$i}",
                'content' => '<p>Konten panduan.</p>',
                'template' => 'custom',
                'is_published' => true,
                'show_in_footer' => false,
                'sort_order' => 20 + $i,
            ]);
        }

        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.pages.index', ['search' => 'Reseller Resmi']))
            ->assertOk()
            ->assertSee('Program Reseller Resmi')
            ->assertDontSee('Halaman Panduan 1');

        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.pages.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Halaman Panduan');

        // Delete custom page -> soft deleted + hidden from storefront & footer
        $this->actingAs($storeAdmin, 'admin')
            ->delete(route('admin.pages.destroy', $customPage))
            ->assertRedirect(route('admin.pages.index'));

        $trashed = Page::withTrashed()->findOrFail($customPage->id);
        $this->assertSoftDeleted($trashed);
        $this->assertFalse($trashed->is_published);
        $this->assertFalse($trashed->show_in_footer);

        $this->get('/program-reseller-resmi')->assertNotFound();
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('Program Reseller Resmi');
    }

    public function test_slug_uniqueness_and_reserved_system_slug_protection(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');

        $reservedSlugs = [
            'admin',
            'akun',
            'api',
            'produk',
            'kategori',
            'keranjang',
            'artikel',
            'faq',
            'kontak',
            'tentang-kami',
            'kebijakan-privasi',
            'syarat-ketentuan',
        ];

        foreach ($reservedSlugs as $reserved) {
            $this->actingAs($storeAdmin, 'admin')
                ->post(route('admin.pages.store'), [
                    'title' => 'Halaman Uji Sistem',
                    'slug' => $reserved,
                    'content' => '<p>Konten uji.</p>',
                    'is_published' => '1',
                    'sort_order' => 10,
                ])
                ->assertSessionHasErrors('slug');
        }

        // Blank slug with reserved-sounding title auto-suffixes instead of colliding
        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.store'), [
                'title' => 'Produk',
                'slug' => '',
                'content' => '<p>Informasi kualitas produk.</p>',
                'is_published' => '1',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $autoSuffixed = Page::where('slug', 'produk-2')->firstOrFail();

        // Duplicate slug rejected
        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.store'), [
                'title' => 'Produk Duplikat',
                'slug' => $autoSuffixed->slug,
                'content' => '<p>Duplikat.</p>',
                'is_published' => '1',
                'sort_order' => 11,
            ])
            ->assertSessionHasErrors('slug');

        // Pastikan guard admin kembali guest sebelum memverifikasi route login admin
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));

        // Core storefront & portal routes remain intact
        $this->get(route('storefront.catalog'))->assertOk();
        $this->get(route('storefront.about'))->assertOk();
        $this->get(route('storefront.faq'))->assertOk();
        $this->get(route('storefront.contact'))->assertOk();
        $this->get(route('admin.login'))->assertOk();
        $this->get(route('customer.login'))->assertOk();
    }

    public function test_toggle_enable_and_disable_updates_public_access_and_footer_visibility(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');
        $shippingPolicy = Page::where('slug', 'kebijakan-pengiriman')->firstOrFail();

        // Initially enabled
        $this->get('/kebijakan-pengiriman')->assertOk();
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('/kebijakan-pengiriman');

        // Toggle Disable -> returns 404 publicly and disappears from footer
        $this->actingAs($storeAdmin, 'admin')
            ->patch(route('admin.pages.toggle', $shippingPolicy))
            ->assertRedirect();

        $shippingPolicy->refresh();
        $this->assertFalse($shippingPolicy->is_published);

        $this->get('/kebijakan-pengiriman')->assertNotFound();
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('/kebijakan-pengiriman');

        // Toggle Enable back -> returns 200 publicly and reappears in footer
        $this->actingAs($storeAdmin, 'admin')
            ->patch(route('admin.pages.toggle', $shippingPolicy))
            ->assertRedirect();

        $shippingPolicy->refresh();
        $this->assertTrue($shippingPolicy->is_published);

        $this->get('/kebijakan-pengiriman')->assertOk();
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('/kebijakan-pengiriman');

        // Disabling ALL policy pages does NOT render hardcoded fallback links
        foreach (StorefrontContentService::POLICY_SLUGS as $slug) {
            $policy = Page::where('slug', $slug)->firstOrFail();
            if ($policy->is_published) {
                $this->actingAs($storeAdmin, 'admin')
                    ->patch(route('admin.pages.toggle', $policy))
                    ->assertRedirect();
            }
        }

        $homeWhenAllDisabled = $this->get(route('storefront.home'))->assertOk();
        foreach (StorefrontContentService::POLICY_SLUGS as $slug) {
            $homeWhenAllDisabled->assertDontSee('/'.$slug);
        }
        $homeWhenAllDisabled->assertSee('Tidak ada halaman kebijakan aktif.');
    }

    public function test_custom_page_footer_visibility_respects_show_in_footer_flag_and_order(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');

        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.store'), [
                'title' => 'Panduan Belanja Footer',
                'slug' => 'panduan-belanja-footer',
                'content' => '<p>Panduan belanja lengkap.</p>',
                'is_published' => '1',
                'show_in_footer' => '1',
                'sort_order' => 5,
            ])
            ->assertRedirect();

        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.store'), [
                'title' => 'Halaman Tersembunyi Footer',
                'slug' => 'halaman-tersembunyi-footer',
                'content' => '<p>Dapat diakses langsung via URL.</p>',
                'is_published' => '1',
                'show_in_footer' => '0',
                'sort_order' => 6,
            ])
            ->assertRedirect();

        // Ikuti redirect admin agar flash session admin dikonsumsi sebelum memeriksa storefront
        $hiddenPage = Page::where('slug', 'halaman-tersembunyi-footer')->firstOrFail();
        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.pages.edit', $hiddenPage))
            ->assertOk();

        // Enabled + show_in_footer = true -> appears in footer and accessible via URL
        // Enabled + show_in_footer = false -> hidden from footer, still accessible via URL
        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('/panduan-belanja-footer')
            ->assertSee('Panduan Belanja Footer')
            ->assertDontSee('/halaman-tersembunyi-footer')
            ->assertDontSee('Halaman Tersembunyi Footer');

        $this->get('/panduan-belanja-footer')
            ->assertOk()
            ->assertSee('Panduan Belanja Footer');

        $this->get('/halaman-tersembunyi-footer')
            ->assertOk()
            ->assertSee('Halaman Tersembunyi Footer');

        // Disable the footer custom page -> disappears from footer & returns 404
        $footerCustom = Page::where('slug', 'panduan-belanja-footer')->firstOrFail();
        $this->actingAs($storeAdmin, 'admin')
            ->patch(route('admin.pages.toggle', $footerCustom))
            ->assertRedirect();

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertDontSee('/panduan-belanja-footer');
        $this->get('/panduan-belanja-footer')->assertNotFound();
    }

    public function test_rbac_blocks_unauthorized_admin_roles_from_pages_management(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');
        $productManager = $this->createAdminWithRole('product-manager');
        $orderManager = $this->createAdminWithRole('order-manager');
        $support = $this->createAdminWithRole('customer-support');

        $customPage = Page::create([
            'title' => 'Halaman Uji RBAC',
            'slug' => 'halaman-uji-rbac',
            'content' => '<p>Konten uji.</p>',
            'template' => 'custom',
            'is_published' => true,
            'show_in_footer' => false,
            'sort_order' => 20,
        ]);

        // Store admin sees Halaman & Kebijakan sidebar link
        $this->actingAs($storeAdmin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.pages.index'), false);

        foreach ([$productManager, $orderManager, $support] as $unauthorized) {
            $this->actingAs($unauthorized, 'admin')->get(route('admin.pages.index'))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->get(route('admin.pages.create'))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->post(route('admin.pages.store'), [
                'title' => 'Unauthorized',
                'content' => '<p>X</p>',
            ])->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->post(route('admin.pages.preview.draft'), [
                'title' => 'Unauthorized',
                'content' => '<p>X</p>',
            ])->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->get(route('admin.pages.edit', $customPage))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->get(route('admin.pages.preview', $customPage))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->put(route('admin.pages.update', $customPage), [
                'title' => 'Unauthorized',
                'content' => '<p>X</p>',
            ])->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->patch(route('admin.pages.toggle', $customPage))->assertForbidden();
            $this->actingAs($unauthorized, 'admin')->delete(route('admin.pages.destroy', $customPage))->assertForbidden();
        }

        $this->actingAs($productManager, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.pages.index'), false);
    }

    public function test_html_sanitization_seo_metadata_and_cache_invalidation_on_public_pages(): void
    {
        $storeAdmin = $this->createAdminWithRole('store-admin');

        $this->actingAs($storeAdmin, 'admin')
            ->post(route('admin.pages.store'), [
                'title' => 'Halaman Keamanan Konten',
                'slug' => 'keamanan-konten',
                'content' => '<h2>Aman</h2><p>Teks aman</p><script>alert("xss")</script><img src="javascript:alert(1)" onerror="alert(2)"><a href="javascript:void(0)">Tautan Jahat</a>',
                'seo_title' => 'Judul SEO Keamanan Konten',
                'seo_description' => 'Meta deskripsi SEO halaman custom.',
                'is_published' => '1',
                'show_in_footer' => '1',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $page = Page::where('slug', 'keamanan-konten')->firstOrFail();

        $this->assertStringNotContainsString('<script>', $page->content);
        $this->assertStringNotContainsString('onerror', $page->content);
        $this->assertStringNotContainsString('javascript:', $page->content);

        // Prime public cache
        $this->get('/keamanan-konten')
            ->assertOk()
            ->assertSee('Judul SEO Keamanan Konten')
            ->assertSee('Meta deskripsi SEO halaman custom.')
            ->assertSee('Teks aman')
            ->assertDontSee('<script>', false);

        // Update page -> cache must invalidate immediately on next request
        $this->actingAs($storeAdmin, 'admin')
            ->put(route('admin.pages.update', $page), [
                'title' => 'Halaman Keamanan Versi Dua',
                'slug' => 'keamanan-konten',
                'content' => '<p>Konten terbaru pasca invalidasi cache.</p>',
                'seo_title' => 'SEO Versi Dua',
                'seo_description' => 'Deskripsi SEO versi dua.',
                'is_published' => '1',
                'show_in_footer' => '1',
                'sort_order' => 10,
            ])
            ->assertRedirect();

        $this->get('/keamanan-konten')
            ->assertOk()
            ->assertSee('Halaman Keamanan Versi Dua')
            ->assertSee('Konten terbaru pasca invalidasi cache.')
            ->assertSee('SEO Versi Dua')
            ->assertSee('Deskripsi SEO versi dua.')
            ->assertDontSee('Teks aman');

        $this->get(route('storefront.home'))
            ->assertOk()
            ->assertSee('Halaman Keamanan Versi Dua');
    }
}
