<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Payment\MidtransClient;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerDashboardOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    protected const TEST_SERVER_KEY = 'SB-Mid-server-TEST-SECRET-KEY-9999';

    protected const TEST_CLIENT_KEY = 'SB-Mid-client-TEST-PUBLIC-KEY-1111';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function configureMidtrans(
        bool $isActive = true,
        bool $isProduction = false,
        string $clientKey = self::TEST_CLIENT_KEY,
        string $serverKey = self::TEST_SERVER_KEY
    ): void {
        config([
            'services.midtrans.is_production' => $isProduction,
            'services.midtrans.merchant_id' => 'G123456789',
            'services.midtrans.client_key' => $clientKey !== '' ? $clientKey : null,
            'services.midtrans.server_key' => $serverKey !== '' ? $serverKey : null,
        ]);

        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('midtrans', 'is_active', $isActive ? '1' : '0', 'boolean', false);
        $settings->set('midtrans', 'is_production', $isProduction ? '1' : '0', 'boolean', false);
        $settings->set('midtrans', 'merchant_id', 'G123456789', 'string', false);
        $settings->set('midtrans', 'client_key', $clientKey, 'string', false);
        $settings->set('midtrans', 'server_key', $serverKey, 'encrypted', false);
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Customer::create(array_merge([
            'name' => 'Pelanggan '.$suffix,
            'email' => 'dash-cust-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ], $overrides));
    }

    protected function createOrderForCustomer(Customer $customer, array $overrides = []): Order
    {
        $suffix = strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));

        return Order::create(array_merge([
            'order_number' => 'PLN-20261121-'.$suffix,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 150000,
            'discount_amount' => 0,
            'shipping_cost' => 25000,
            'tax_amount' => 0,
            'payment_fee' => 0,
            'grand_total' => 175000,
            'total_weight_grams' => 500,
            'payment_due_at' => now()->addHours(12),
        ], $overrides));
    }

    protected function normalizeHtml(string $html): string
    {
        return str_replace(
            ['\\\\/', '\\/', '\\u002F', '\\u002f'],
            '/',
            html_entity_decode($html, ENT_QUOTES, 'UTF-8')
        );
    }

    public function test_dashboard_renders_detail_and_pay_now_action_for_eligible_order(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $eligibleOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'payment_due_at' => now()->addHours(6),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard'));

        $html = $response->getContent();
        $normalizedHtml = $this->normalizeHtml($html);

        $response->assertStatus(200)
            ->assertSee('Aksi')
            ->assertSee($eligibleOrder->order_number)
            ->assertSee('Detail Pesanan')
            ->assertSee(route('customer.orders.show', $eligibleOrder), false)
            ->assertSee('Bayar Sekarang')
            ->assertSee('orderPaymentPanel(', false)
            ->assertSee(self::TEST_CLIENT_KEY)
            ->assertSee(MidtransClient::SNAP_SANDBOX_JS_URL);

        $this->assertStringContainsString(
            route('customer.orders.payment.initiate', $eligibleOrder),
            $normalizedHtml
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<span\b[^>]*x-show="!?loading"[^>]*\bd-(?:inline-)?flex\b/i',
            $html
        );
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $html);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $normalizedHtml);
    }

    public function test_dashboard_hides_pay_button_and_snap_js_for_paid_cancelled_refunded_and_expired_orders(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();

        $paidOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $cancelledOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'cancelled',
            'payment_status' => 'unpaid',
            'cancelled_at' => now(),
        ]);

        $refundedOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'refunded',
            'payment_status' => 'refunded',
        ]);

        $expiredOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_due_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard'));

        $html = $response->getContent();
        $normalizedHtml = $this->normalizeHtml($html);

        $response->assertStatus(200)
            ->assertSee('Detail Pesanan')
            ->assertDontSee('Bayar Sekarang')
            ->assertDontSee('orderPaymentPanel(', false)
            ->assertDontSee(MidtransClient::SNAP_SANDBOX_JS_URL)
            ->assertDontSee(self::TEST_CLIENT_KEY);

        foreach ([$paidOrder, $cancelledOrder, $refundedOrder, $expiredOrder] as $order) {
            $response->assertSee($order->order_number);
            $response->assertSee(route('customer.orders.show', $order), false);
            $this->assertStringNotContainsString(
                route('customer.orders.payment.initiate', $order),
                $normalizedHtml
            );
        }

        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $html);
    }

    public function test_dashboard_does_not_render_pay_button_or_load_snap_js_when_midtrans_is_inactive(): void
    {
        $this->configureMidtrans(isActive: false);

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer, [
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_due_at' => now()->addHours(12),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard'));

        $html = $response->getContent();
        $normalizedHtml = $this->normalizeHtml($html);

        $response->assertStatus(200)
            ->assertSee($order->order_number)
            ->assertSee('Detail Pesanan')
            ->assertSee(route('customer.orders.show', $order), false)
            ->assertDontSee('Bayar Sekarang')
            ->assertDontSee('orderPaymentPanel(', false)
            ->assertDontSee(MidtransClient::SNAP_SANDBOX_JS_URL)
            ->assertDontSee(self::TEST_CLIENT_KEY);

        $this->assertStringNotContainsString(
            route('customer.orders.payment.initiate', $order),
            $normalizedHtml
        );
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $html);
    }

    public function test_dashboard_handles_mixed_eligible_and_ineligible_orders_correctly(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();

        $eligibleOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_due_at' => now()->addHours(8),
        ]);

        $paidOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $cancelledOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'cancelled',
            'payment_status' => 'unpaid',
            'cancelled_at' => now(),
        ]);

        $refundedOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'refunded',
            'payment_status' => 'refunded',
        ]);

        $expiredOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'payment_due_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard'));

        $html = $response->getContent();
        $normalizedHtml = $this->normalizeHtml($html);

        $response->assertStatus(200)
            ->assertSee(self::TEST_CLIENT_KEY)
            ->assertSee(MidtransClient::SNAP_SANDBOX_JS_URL);

        $this->assertSame(5, substr_count($html, 'Detail Pesanan'));
        $this->assertSame(1, substr_count($html, 'Bayar Sekarang'));
        $this->assertSame(1, substr_count($html, 'orderPaymentPanel('));
        $this->assertSame(1, substr_count($html, MidtransClient::SNAP_SANDBOX_JS_URL));

        $this->assertStringContainsString(
            route('customer.orders.payment.initiate', $eligibleOrder),
            $normalizedHtml
        );

        foreach ([$paidOrder, $cancelledOrder, $refundedOrder, $expiredOrder] as $ineligibleOrder) {
            $response->assertSee(route('customer.orders.show', $ineligibleOrder), false);
            $this->assertStringNotContainsString(
                route('customer.orders.payment.initiate', $ineligibleOrder),
                $normalizedHtml
            );
        }

        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $html);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $normalizedHtml);
    }

    public function test_dashboard_and_order_actions_protect_customer_ownership(): void
    {
        $this->configureMidtrans();

        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();

        $ownOrder = $this->createOrderForCustomer($owner);
        $foreignOrder = $this->createOrderForCustomer($otherCustomer);

        $dashboardResponse = $this->actingAs($owner, 'customer')
            ->get(route('customer.dashboard'));

        $html = $dashboardResponse->getContent();
        $normalizedHtml = $this->normalizeHtml($html);

        $dashboardResponse->assertStatus(200)
            ->assertSee($ownOrder->order_number)
            ->assertSee(route('customer.orders.show', $ownOrder), false)
            ->assertDontSee($foreignOrder->order_number)
            ->assertDontSee(route('customer.orders.show', $foreignOrder), false);

        $this->assertStringNotContainsString(
            route('customer.orders.payment.initiate', $foreignOrder),
            $normalizedHtml
        );

        $this->actingAs($owner, 'customer')
            ->get(route('customer.orders.show', $foreignOrder))
            ->assertStatus(403);

        $this->actingAs($owner, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $foreignOrder))
            ->assertStatus(403);

        Http::assertNothingSent();
    }
}
