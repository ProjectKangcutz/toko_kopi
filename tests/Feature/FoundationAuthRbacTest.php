<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShippingCourier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationAuthRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_storefront_homepage_loads(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Pasar Lokal Nusantara');
    }

    public function test_customer_can_register_login_access_dashboard_and_logout(): void
    {
        $register = $this->post('/akun/daftar', [
            'name' => 'Siti Aminah',
            'email' => 'siti@example.com',
            'phone' => '081234567890',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $register->assertRedirect(route('customer.dashboard'));
        $this->assertAuthenticated('customer');

        $this->get('/akun')->assertStatus(200)->assertSee('Ringkasan Akun');

        $this->post('/akun/keluar')->assertRedirect(route('storefront.home'));
        $this->assertGuest('customer');

        $this->post('/akun/masuk', [
            'email' => 'siti@example.com',
            'password' => 'Rahasia123',
        ])->assertRedirect(route('customer.dashboard'));
        $this->assertAuthenticated('customer');
    }

    public function test_suspended_customer_cannot_access_customer_dashboard(): void
    {
        $customer = Customer::create([
            'name' => 'Akun Nonaktif',
            'email' => 'suspended@example.com',
            'phone' => '081200001111',
            'password' => 'Password!123',
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($customer, 'customer')->get('/akun');

        $response->assertRedirect(route('customer.login'));
        $this->assertGuest('customer');
    }

    public function test_customer_and_admin_guards_are_strictly_isolated(): void
    {
        $customer = Customer::create([
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'phone' => '08123456789',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $this->actingAs($customer, 'customer')
            ->get('/admin')
            ->assertRedirect(route('admin.login'));

        auth('customer')->logout();
        auth()->forgetGuards();
        $this->flushSession();

        $admin = User::create([
            'name' => 'Admin Toko',
            'email' => 'toko@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);
        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        $this->actingAs($admin, 'admin')
            ->get('/akun')
            ->assertRedirect(route('customer.login'));
    }

    public function test_admin_can_login_and_rbac_permission_is_enforced(): void
    {
        $admin = User::create([
            'name' => 'Staff Gudang',
            'email' => 'staff@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertStatus(200)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $adminWithoutRole = User::create([
            'name' => 'User Tanpa Izin',
            'email' => 'noperm@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $this->actingAs($adminWithoutRole, 'admin')
            ->get('/admin')
            ->assertStatus(403);
    }

    public function test_maintenance_mode_blocks_storefront_but_allows_admin(): void
    {
        Setting::updateOrCreate(
            ['group' => 'store', 'key' => 'maintenance_mode'],
            ['value' => '1', 'type' => 'boolean', 'is_public' => false]
        );

        $this->get('/')->assertStatus(503);

        $admin = User::create([
            'name' => 'Owner',
            'email' => 'owner2@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail()->id);

        $this->actingAs($admin, 'admin')->get('/')->assertStatus(200);
        $this->actingAs($admin, 'admin')->get('/admin')->assertStatus(200);
    }

    public function test_database_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstRoleCount = Role::count();
        $firstPageCount = Page::count();
        $firstCourierCount = ShippingCourier::count();
        $firstUserCount = User::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($firstRoleCount, Role::count());
        $this->assertSame($firstPageCount, Page::count());
        $this->assertSame($firstCourierCount, ShippingCourier::count());
        $this->assertSame($firstUserCount, User::count());
    }

    public function test_midtrans_webhook_rejects_invalid_signature(): void
    {
        Setting::updateOrCreate(
            ['group' => 'midtrans', 'key' => 'server_key'],
            ['value' => Setting::encodeValue('SB-Mid-server-test-key', 'encrypted'), 'type' => 'encrypted', 'is_public' => false]
        );

        $response = $this->postJson('/api/webhooks/midtrans', [
            'order_id' => 'PLN-20261119-0001',
            'status_code' => '200',
            'gross_amount' => '150000.00',
            'signature_key' => 'invalid-signature',
        ]);

        $response->assertStatus(403);
    }
}
