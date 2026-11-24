<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Storefront\CartService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CartAdminIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        $this->cartService = app(CartService::class);
    }

    protected function createProduct(array $overrides = []): Product
    {
        $suffix = uniqid();

        return Product::create(array_merge([
            'sku' => 'ISO-'.$suffix,
            'name' => 'Produk Isolasi '.$suffix,
            'slug' => 'produk-isolasi-'.$suffix,
            'description' => 'Produk UMKM untuk pengujian isolasi session dan guard.',
            'price' => 75000,
            'stock' => 15,
            'reserved_stock' => 0,
            'weight_grams' => 300,
            'has_variants' => false,
            'is_active' => true,
        ], $overrides));
    }

    protected function createAdmin(array $overrides = []): User
    {
        $suffix = uniqid();

        $admin = User::create(array_merge([
            'name' => 'Admin '.$suffix,
            'email' => 'admin-'.$suffix.'@example.com',
            'password' => 'AdminRahasia123',
            'is_active' => true,
        ], $overrides));

        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = uniqid();

        return Customer::create(array_merge([
            'name' => 'Customer '.$suffix,
            'email' => 'customer-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'CustomerRahasia123',
            'status' => 'active',
        ], $overrides));
    }

    protected function adminBannerText(): string
    {
        return 'Anda sedang login sebagai Administrator. Keranjang ini adalah keranjang pengunjung (guest session) yang terpisah dari akun admin dan tidak akan digabungkan ke akun admin.';
    }

    public function test_guest_cart_remains_visible_with_admin_banner_and_without_merge_when_admin_logs_in(): void
    {
        $product = $this->createProduct([
            'name' => 'Kopi Gayo Saring',
            'price' => 60000,
            'stock' => 10,
        ]);
        $admin = $this->createAdmin(['email' => 'owner.isolasi@example.com']);

        // 1. Guest menambahkan produk ke keranjang & banner tidak muncul untuk guest biasa
        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'cart_item_count' => 2]);

        $this->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Kopi Gayo Saring')
            ->assertSee('Keranjang belanja (2 item)', false)
            ->assertDontSee($this->adminBannerText());

        // 2. Admin login pada browser/session yang sama
        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated('admin');
        $this->assertGuest('customer');
        $this->assertNull($this->cartService->currentCustomer());

        // 9. Admin login tidak menjalankan merge guest cart ke database
        $this->assertSame(0, Cart::count());
        $this->assertSame(0, CartItem::count());

        // 3, 4, 5. Guest cart tetap tersedia pada /keranjang, banner admin tampil, badge header konsisten
        $cartResponse = $this->get(route('storefront.cart'));
        $cartResponse->assertStatus(200)
            ->assertSee('Kopi Gayo Saring')
            ->assertSee('Rp 120.000')
            ->assertSee('Keranjang belanja (2 item)', false)
            ->assertSee($this->adminBannerText());
    }

    public function test_admin_logout_preserves_guest_cart_and_rotates_session_and_csrf_token(): void
    {
        $product = $this->createProduct(['name' => 'Sambal Roa Manado', 'stock' => 10]);
        $admin = $this->createAdmin(['email' => 'logout.admin@example.com']);

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 3,
        ])->assertStatus(200);

        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $expectedGuestCart = session(CartService::GUEST_SESSION_KEY);
        $this->assertNotEmpty($expectedGuestCart);

        $sessionIdBeforeLogout = session()->getId();
        $csrfBeforeLogout = session()->token();

        // 6. Admin logout tanpa kehilangan guest cart, namun session ID & CSRF token tetap terotasi
        $this->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->assertNotSame($sessionIdBeforeLogout, session()->getId());
        $this->assertNotSame($csrfBeforeLogout, session()->token());
        $this->assertSame($expectedGuestCart, session(CartService::GUEST_SESSION_KEY));

        // Guest cart tetap tampil, badge tetap 3, dan banner admin tidak lagi muncul
        $this->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Sambal Roa Manado')
            ->assertSee('Keranjang belanja (3 item)', false)
            ->assertDontSee($this->adminBannerText());
    }

    public function test_inactive_admin_middleware_logout_preserves_guest_cart_and_customer_session(): void
    {
        $product = $this->createProduct(['name' => 'Madu Hutan Sumbawa', 'stock' => 10]);
        $admin = $this->createAdmin(['email' => 'deactivated.admin@example.com']);
        $customer = $this->createCustomer(['email' => 'active.cust@example.com']);

        // Customer & admin sama-sama login melalui alur session HTTP nyata
        $this->post(route('customer.login.submit'), [
            'email' => $customer->email,
            'password' => 'CustomerRahasia123',
        ])->assertRedirect(route('customer.dashboard'));

        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $guestCartKey = CartService::buildItemKey($product->id, null);
        $expectedGuestCart = [
            $guestCartKey => [
                'product_id' => $product->id,
                'product_variant_id' => null,
                'quantity' => 1,
            ],
        ];
        session()->put(CartService::GUEST_SESSION_KEY, $expectedGuestCart);

        // Simulasikan admin dinonaktifkan ketika sesi masih berjalan.
        // Bersihkan cache guard in-memory agar request berikutnya me-resolve ulang user dari session + DB.
        $admin->update(['is_active' => false]);
        auth()->forgetGuards();

        $sessionIdBefore = session()->getId();
        $csrfBefore = session()->token();

        // 7. Admin nonaktif diarahkan ke login admin tanpa menghapus guest cart & sesi customer
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertNotSame($csrfBefore, session()->token());
        $this->assertSame($expectedGuestCart, session(CartService::GUEST_SESSION_KEY));
        $this->get(route('customer.dashboard'))->assertStatus(200);
    }

    public function test_customer_can_still_merge_guest_cart_after_admin_login_and_logout_cycle(): void
    {
        $product = $this->createProduct(['name' => 'Teh Melati Solo', 'price' => 35000, 'stock' => 10]);
        $admin = $this->createAdmin(['email' => 'cycle.admin@example.com']);
        $customer = $this->createCustomer(['email' => 'merge.cust@example.com']);

        $this->postJson(route('storefront.cart.add'), [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        // 8. Customer login tetap dapat melakukan merge guest cart
        $this->post(route('customer.login.submit'), [
            'email' => $customer->email,
            'password' => 'CustomerRahasia123',
        ])->assertRedirect(route('customer.dashboard'));

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertEmpty(session(CartService::GUEST_SESSION_KEY, []));

        $customerCart = Cart::where('customer_id', $customer->id)->first();
        $this->assertNotNull($customerCart);
        $this->assertSame(2, (int) CartItem::where('cart_id', $customerCart->id)->value('quantity'));

        // 14. Banner admin tidak muncul ketika customer login
        $this->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Teh Melati Solo')
            ->assertSee('Keranjang belanja (2 item)', false)
            ->assertDontSee($this->adminBannerText());
    }

    public function test_admin_and_customer_logout_are_strictly_isolated_and_preserve_guest_cart_and_other_guard(): void
    {
        $product = $this->createProduct(['name' => 'Kain Lurik Klaten', 'stock' => 10]);
        $admin = $this->createAdmin(['email' => 'dual.admin@example.com']);
        $customer = $this->createCustomer(['email' => 'dual.cust@example.com']);

        // Customer login terlebih dahulu
        $this->post(route('customer.login.submit'), [
            'email' => $customer->email,
            'password' => 'CustomerRahasia123',
        ])->assertRedirect(route('customer.dashboard'));

        // Admin juga login pada browser/session yang sama
        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated('customer');
        $this->assertAuthenticated('admin');

        // 14. Banner tidak muncul jika customer sedang login walaupun admin juga login
        $this->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertDontSee($this->adminBannerText());

        // 10. Logout admin tidak menghapus sesi customer
        $this->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->get(route('customer.dashboard'))->assertStatus(200);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));

        // Login ulang admin, lalu pasang guest_cart di session untuk menguji logout customer (11 & 12)
        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $guestCartKey = CartService::buildItemKey($product->id, null);
        $guestCartPayload = [
            $guestCartKey => [
                'product_id' => $product->id,
                'product_variant_id' => null,
                'quantity' => 2,
            ],
        ];
        session()->put(CartService::GUEST_SESSION_KEY, $guestCartPayload);

        $sessionIdBeforeCustomerLogout = session()->getId();
        $csrfBeforeCustomerLogout = session()->token();

        // 11 & 12. Logout customer tidak menghapus sesi admin maupun guest cart
        $this->post(route('customer.logout'))
            ->assertRedirect(route('storefront.home'));

        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotSame($sessionIdBeforeCustomerLogout, session()->getId());
        $this->assertNotSame($csrfBeforeCustomerLogout, session()->token());
        $this->assertSame($guestCartPayload, session(CartService::GUEST_SESSION_KEY));

        $this->get(route('admin.dashboard'))->assertStatus(200);
        $this->get(route('customer.dashboard'))->assertRedirect(route('customer.login'));

        // Karena customer sudah logout & admin masih login, banner kembali tampil bersama guest cart
        $this->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Kain Lurik Klaten')
            ->assertSee('Keranjang belanja (2 item)', false)
            ->assertSee($this->adminBannerText());
    }

    public function test_admin_cannot_access_or_modify_customer_cart(): void
    {
        $product = $this->createProduct(['name' => 'Gula Semut Kulonprogo', 'stock' => 10]);
        $customer = $this->createCustomer(['email' => 'victim.cust@example.com']);
        $admin = $this->createAdmin(['email' => 'nosy.admin@example.com']);

        $added = $this->cartService->addItem($product->id, null, 4, $customer);
        $customerItemId = (int) $added['cart_item_id'];

        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        // 15. Admin bukan customer & tidak memperoleh akses ke cart customer
        $this->assertNull(Auth::guard('customer')->user());
        $this->assertNull($this->cartService->currentCustomer());

        $this->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertDontSee('Gula Semut Kulonprogo')
            ->assertSee('Keranjang belanja (0 item)', false)
            ->assertSee($this->adminBannerText());

        // Upaya mutasi ID CartItem milik customer saat hanya admin yang login tidak boleh mengubah DB customer
        $this->patchJson(route('storefront.cart.update', ['item' => $customerItemId]), [
            'quantity' => 9,
        ])->assertStatus(422);

        $this->deleteJson(route('storefront.cart.remove', ['item' => $customerItemId]))
            ->assertStatus(200)
            ->assertJson(['success' => false]);

        $this->assertSame(4, CartItem::findOrFail($customerItemId)->quantity);
    }

    public function test_admin_and_customer_login_redirects_never_fall_back_to_storefront_root(): void
    {
        $admin = $this->createAdmin(['email' => 'redirect.admin@example.com']);
        $customer = $this->createCustomer(['email' => 'redirect.cust@example.com']);

        // 1. Jika admin sudah login lalu mengakses /admin/login, redirect ke admin.dashboard (bukan '/')
        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->get(route('admin.login'))
            ->assertRedirect(route('admin.dashboard'));

        // 2. Jika admin login saat url.intended di session mengarah ke '/' atau '/akun', tetap redirect ke admin.dashboard
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        session()->put('url.intended', url('/'));

        $this->post(route('admin.login.submit'), [
            'email' => $admin->email,
            'password' => 'AdminRahasia123',
        ])->assertRedirect(route('admin.dashboard'));

        // 3. Jika customer sudah login lalu mengakses /akun/masuk, redirect ke customer.dashboard (bukan '/')
        session()->put('url.intended', route('admin.dashboard'));
        $this->post(route('customer.login.submit'), [
            'email' => $customer->email,
            'password' => 'CustomerRahasia123',
        ])->assertRedirect(route('customer.dashboard'));

        $this->get(route('customer.login'))
            ->assertRedirect(route('customer.dashboard'));
    }
}
