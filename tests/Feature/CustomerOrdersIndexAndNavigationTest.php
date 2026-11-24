<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerOrdersIndexAndNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function configureMidtrans(): void
    {
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('midtrans', 'is_active', '1', 'boolean', false);
        $settings->set('midtrans', 'is_production', '0', 'boolean', false);
        $settings->set('midtrans', 'merchant_id', 'G123456789', 'string', false);
        $settings->set('midtrans', 'client_key', 'SB-Mid-client-TEST-1234', 'string', false);
        $settings->set('midtrans', 'server_key', 'SB-Mid-server-TEST-5678', 'encrypted', false);
    }

    protected function createCustomer(string $suffix): Customer
    {
        return Customer::create([
            'name' => 'Pelanggan '.$suffix,
            'email' => "cust-{$suffix}@example.com",
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);
    }

    protected function createOrder(Customer $customer, string $orderNumber, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => $orderNumber,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 100000,
            'shipping_cost' => 15000,
            'grand_total' => 115000,
            'total_weight_grams' => 500,
            'payment_due_at' => now()->addHours(12),
        ], $overrides));
    }

    public function test_customer_orders_index_enforces_ownership_pagination_status_filter_and_pay_button(): void
    {
        $this->configureMidtrans();

        $custA = $this->createCustomer('alpha');
        $custB = $this->createCustomer('beta');

        // 11 orders for Customer A to exercise pagination
        for ($i = 1; $i <= 10; $i++) {
            $this->createOrder($custA, sprintf('PLN-20261123-PAID-%02d', $i), [
                'order_status' => 'paid',
                'payment_status' => 'paid',
                'paid_at' => now()->subMinutes($i),
            ]);
        }

        $unpaidOrder = $this->createOrder($custA, 'PLN-20261123-UNPAID-01', [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'payment_due_at' => now()->addHours(6),
        ]);

        // Order belonging to Customer B must NEVER leak into Customer A's list
        $this->createOrder($custB, 'PLN-20261123-OTHERCUST-99', [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
        ]);

        // Guest redirected to customer login
        $this->get(route('customer.orders.index'))
            ->assertRedirect(route('customer.login'));

        // Page 1 shows unpaid order with Bayar Sekarang & hides Customer B order
        $page1 = $this->actingAs($custA, 'customer')
            ->get(route('customer.orders.index'))
            ->assertOk()
            ->assertSee($unpaidOrder->order_number)
            ->assertSee('Bayar Sekarang')
            ->assertDontSee('PLN-20261123-OTHERCUST-99');

        // Page 2 exists due to pagination (11 orders total, 10 per page -> oldest PAID-01 is on page 2)
        $this->actingAs($custA, 'customer')
            ->get(route('customer.orders.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('PLN-20261123-PAID-01')
            ->assertDontSee($unpaidOrder->order_number);

        // Status filter 'paid' hides unpaid order and shows NO Bayar Sekarang button
        $this->actingAs($custA, 'customer')
            ->get(route('customer.orders.index', ['status' => 'paid']))
            ->assertOk()
            ->assertSee('PLN-20261123-PAID-01')
            ->assertDontSee($unpaidOrder->order_number)
            ->assertDontSee('Bayar Sekarang');
    }

    public function test_customer_and_admin_navigation_cleanups_reflect_completed_modules(): void
    {
        $cust = $this->createCustomer('nav');

        $this->actingAs($cust, 'customer')
            ->get(route('customer.dashboard'))
            ->assertOk()
            ->assertSee('Pesanan Saya')
            ->assertSee(route('customer.orders.index'), false)
            ->assertSee('Lihat Semua Pesanan')
            ->assertSee('Alamat Pengiriman')
            ->assertSee('Kembali ke Toko')
            ->assertDontSee('Alamat &amp; Profil', false)
            ->assertDontSee('Pesan Belum Dibaca')
            ->assertDontSee('Lacak &amp; Cek Ongkir', false)
            ->assertDontSee('Hubungi Admin');

        $superAdmin = User::create([
            'name' => 'Super Admin Nav',
            'email' => 'supernav@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);
        $superAdmin->roles()->attach(Role::where('slug', 'super-admin')->firstOrFail()->id);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.products.index'), false)
            ->assertSee(route('admin.categories.index'), false)
            ->assertSee(route('admin.inventory.index'), false)
            ->assertSee(route('admin.orders.index'), false)
            ->assertSee(route('admin.settings.edit'), false)
            ->assertDontSee('Customer Support')
            ->assertDontSee('Pembayaran Midtrans</span>', false);
    }
}
