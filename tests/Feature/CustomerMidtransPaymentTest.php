<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Payment\MidtransClient;
use App\Services\Payment\MidtransPaymentException;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerMidtransPaymentTest extends TestCase
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
            'email' => 'pay-cust-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ], $overrides));
    }

    protected function createOrderForCustomer(Customer $customer, array $overrides = []): Order
    {
        $suffix = strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));

        $order = Order::create(array_merge([
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

        $order->items()->create([
            'product_name_snapshot' => 'Kopi Arabika Nusantara 250g',
            'variant_name_snapshot' => 'Bubuk Halus',
            'sku_snapshot' => 'KOPI-'.$suffix,
            'unit_price_snapshot' => 75000,
            'cost_price_snapshot' => 45000,
            'weight_grams_snapshot' => 250,
            'quantity' => 2,
            'line_total' => 150000,
        ]);

        $order->address()->create([
            'recipient_name' => $customer->name,
            'recipient_phone' => '081234567890',
            'address_line' => 'Jl. Braga No. 12',
            'province_id' => '9',
            'province_name' => 'Jawa Barat',
            'city_id' => '23',
            'city_name' => 'Kota Bandung',
            'district_id' => '345',
            'district_name' => 'Sumur Bandung',
            'subdistrict_id' => '73539',
            'subdistrict_name' => 'Braga',
            'postal_code' => '40111',
        ]);

        $order->shipment()->create([
            'courier_code' => 'jne',
            'courier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Layanan Reguler',
            'etd' => '2-3 hari',
            'shipping_cost' => 25000,
            'weight_grams' => 1000,
            'status' => 'unfulfilled',
        ]);

        return $order;
    }

    public function test_guest_and_suspended_customer_are_rejected_from_initiating_payment(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer);

        // Guest -> 401
        $this->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(401);

        // Suspended customer -> 403
        $suspended = $this->createCustomer(['status' => 'suspended']);
        $suspendedOrder = $this->createOrderForCustomer($suspended);

        $this->actingAs($suspended, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $suspendedOrder))
            ->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
    }

    public function test_customer_cannot_initiate_payment_for_other_customers_order(): void
    {
        $this->configureMidtrans();

        $owner = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $order = $this->createOrderForCustomer($owner);

        $this->actingAs($otherCustomer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_customer_successfully_initiates_snap_payment_with_server_derived_amount_and_transitions_order(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer, [
            'subtotal' => 150000,
            'shipping_cost' => 25000,
            'grand_total' => 175000,
        ]);

        $capturedPayload = null;
        $capturedAuthHeader = null;

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => function (Request $request) use (&$capturedPayload, &$capturedAuthHeader) {
                $capturedPayload = $request->data();
                $capturedAuthHeader = $request->header('Authorization')[0] ?? null;

                return Http::response([
                    'token' => 'snap-token-abc-123',
                    'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-abc-123',
                ], 201);
            },
        ]);

        // Kirim field manipulasi dari browser untuk memastikan server mengabaikannya
        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order), [
                'gross_amount' => 100,
                'order_status' => 'paid',
                'payment_status' => 'paid',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'snap_token' => 'snap-token-abc-123',
                    'snap_redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-abc-123',
                    'gross_amount' => 175000,
                    'order_status' => 'awaiting_payment',
                    'payment_status' => 'unpaid',
                    'reused_token' => false,
                    'client_key' => self::TEST_CLIENT_KEY,
                    'snap_js_url' => MidtransClient::SNAP_SANDBOX_JS_URL,
                ],
            ]);

        // Pastikan server_key tidak pernah muncul di response JSON
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $response->getContent());

        // Validasi payload yang dikirim ke Midtrans menggunakan HTTP Basic Auth server_key & grand_total dari server
        $this->assertSame('Basic '.base64_encode(self::TEST_SERVER_KEY.':'), $capturedAuthHeader);
        $this->assertNotNull($capturedPayload);
        $this->assertSame(175000, $capturedPayload['transaction_details']['gross_amount']);
        $this->assertStringStartsWith($order->order_number.'-P1-', $capturedPayload['transaction_details']['order_id']);
        $this->assertSame(175000, array_sum(array_map(
            static fn (array $item): int => $item['price'] * $item['quantity'],
            $capturedPayload['item_details']
        )));

        $payment = Payment::where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('midtrans', $payment->payment_gateway);
        $this->assertSame('snap-token-abc-123', $payment->snap_token);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(175000.0, (float) $payment->gross_amount);
        $this->assertSame($capturedPayload['transaction_details']['order_id'], $payment->midtrans_order_id);

        $freshOrder = $order->fresh();
        $this->assertSame('awaiting_payment', $freshOrder->order_status);
        $this->assertSame('unpaid', $freshOrder->payment_status);

        $this->assertTrue(OrderStatusHistory::where('order_id', $order->id)
            ->where('from_status', 'pending')
            ->where('to_status', 'awaiting_payment')
            ->exists());

        $this->assertTrue(PaymentTransaction::where('payment_id', $payment->id)
            ->where('event_type', 'snap_created')
            ->where('status', 'pending')
            ->exists());

        $this->assertTrue(ActivityLog::where('action', 'payment.initiated')
            ->where('subject_id', $payment->id)
            ->exists());
    }

    public function test_active_pending_snap_token_is_reused_without_calling_midtrans_again(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-reusable-01',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-reusable-01',
            ], 201),
        ]);

        $first = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));
        $first->assertStatus(201)
            ->assertJsonPath('data.reused_token', false);

        $firstMidtransOrderId = $first->json('data.midtrans_order_id');

        $second = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));
        $second->assertStatus(200)
            ->assertJsonPath('data.reused_token', true)
            ->assertJsonPath('data.snap_token', 'snap-token-reusable-01')
            ->assertJsonPath('data.midtrans_order_id', $firstMidtransOrderId);

        Http::assertSentCount(1);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_expired_pending_payment_creates_new_payment_with_distinct_midtrans_order_id(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer, [
            'order_status' => 'awaiting_payment',
            'payment_due_at' => now()->addHours(6),
        ]);

        $oldPayment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P1-OLD001',
            'snap_token' => 'old-expired-token',
            'snap_redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/old-expired-token',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->subMinutes(10),
        ]);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'fresh-snap-token-02',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/fresh-snap-token-02',
            ], 201),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $response->assertStatus(201)
            ->assertJsonPath('data.reused_token', false)
            ->assertJsonPath('data.snap_token', 'fresh-snap-token-02');

        $newMidtransOrderId = $response->json('data.midtrans_order_id');
        $this->assertNotSame($oldPayment->midtrans_order_id, $newMidtransOrderId);
        $this->assertStringStartsWith($order->order_number.'-P2-', $newMidtransOrderId);
        $this->assertSame(2, Payment::where('order_id', $order->id)->count());
        Http::assertSentCount(1);
    }

    public function test_paid_cancelled_and_expired_orders_cannot_initiate_payment(): void
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
            'cancelled_at' => now(),
        ]);

        $expiredOrder = $this->createOrderForCustomer($customer, [
            'payment_due_at' => now()->subMinute(),
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $paidOrder))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'payment_already_paid');

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $cancelledOrder))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'payment_order_cancelled');

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $expiredOrder))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'payment_order_expired');

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
    }

    public function test_inactive_or_unconfigured_midtrans_returns_503_safely_without_mutating_order(): void
    {
        $this->configureMidtrans(isActive: false);

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer);

        $resInactive = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $resInactive->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'midtrans_integration_inactive');
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $resInactive->getContent());

        // Aktif tetapi server_key kosong
        $this->configureMidtrans(isActive: true, clientKey: self::TEST_CLIENT_KEY, serverKey: '');

        $resUnconfigured = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $resUnconfigured->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'midtrans_service_unconfigured');

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_midtrans_api_failure_and_timeout_leave_no_orphan_payment_and_preserve_order_status(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer);

        $attempt = 0;

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => function () use (&$attempt) {
                $attempt++;

                if ($attempt === 1) {
                    // 1. Midtrans HTTP 500 error (menyertakan server_key untuk memastikan sanitasi error bekerja)
                    return Http::response([
                        'error_messages' => ['Invalid server_key='.self::TEST_SERVER_KEY],
                    ], 500);
                }

                if ($attempt === 2) {
                    // 2. Malformed response tanpa token -> 502
                    return Http::response([
                        'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/no-token',
                    ], 201);
                }

                // 3. Connection timeout -> 503
                throw new ConnectionException('cURL error 28: Operation timed out with '.self::TEST_SERVER_KEY);
            },
        ]);

        // 1. HTTP 500 -> 503
        $resError = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $resError->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'midtrans_upstream_unavailable');
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $resError->getContent());
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $order->fresh()->order_status);

        // 2. Malformed response tanpa token -> 502
        $resMalformed = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $resMalformed->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'midtrans_malformed_response');
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $order->fresh()->order_status);

        // 3. Connection timeout -> 503
        $resTimeout = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $resTimeout->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'midtrans_timeout');
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $resTimeout->getContent());
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_snap_expiry_is_capped_to_order_payment_due_at(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $dueAt = now()->addMinutes(25);
        $order = $this->createOrderForCustomer($customer, [
            'payment_due_at' => $dueAt,
        ]);

        $capturedPayload = null;

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => function (Request $request) use (&$capturedPayload) {
                $capturedPayload = $request->data();

                return Http::response([
                    'token' => 'snap-token-capped-expiry',
                    'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-capped-expiry',
                ], 201);
            },
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order));

        $response->assertStatus(201);

        $this->assertNotNull($capturedPayload);
        $this->assertSame('minute', $capturedPayload['custom_expiry']['unit']);
        $this->assertLessThanOrEqual(25, (int) $capturedPayload['custom_expiry']['expiry_duration']);
        $this->assertGreaterThanOrEqual(24, (int) $capturedPayload['custom_expiry']['expiry_duration']);

        $payment = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertNotNull($payment->expired_at);
        $this->assertTrue($payment->expired_at->lessThanOrEqualTo($dueAt));
    }

    public function test_order_detail_page_renders_snap_ui_when_payable_and_never_exposes_server_key(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $payableOrder = $this->createOrderForCustomer($customer);

        $page = $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $payableOrder));

        $html = $page->getContent();
        $normalizedHtml = str_replace(
            ['\\\\/', '\\/', '\\u002F', '\\u002f'],
            '/',
            html_entity_decode($html, ENT_QUOTES, 'UTF-8')
        );

        $page->assertStatus(200)
            ->assertSee('Bayar Sekarang')
            ->assertSee('orderPaymentPanel(', false)
            ->assertSee(self::TEST_CLIENT_KEY)
            ->assertSee(MidtransClient::SNAP_SANDBOX_JS_URL);

        $paymentUi = $page->viewData('paymentUi');
        $this->assertTrue($paymentUi['can_pay']);
        $this->assertSame(
            route('customer.orders.payment.initiate', $payableOrder),
            $paymentUi['js_config']['initiateUrl']
        );

        $this->assertStringContainsString(route('customer.orders.payment.initiate', $payableOrder), $normalizedHtml);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $html);
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);

        // Order yang sudah dibayar tidak menampilkan tombol Bayar Sekarang atau snap.js
        $paidOrder = $this->createOrderForCustomer($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $paidPage = $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $paidOrder));

        $paidPage->assertStatus(200)
            ->assertDontSee('Bayar Sekarang')
            ->assertDontSee(MidtransClient::SNAP_SANDBOX_JS_URL)
            ->assertDontSee(self::TEST_CLIENT_KEY);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $paidPage->getContent());
    }

    public function test_midtrans_client_get_transaction_status_uses_core_api_and_sanitizes_errors(): void
    {
        $this->configureMidtrans(isActive: true, isProduction: true);

        Http::fake([
            'https://api.midtrans.com/v2/PLN-ORDER-999/status' => Http::response([
                'status_code' => '200',
                'transaction_status' => 'pending',
                'order_id' => 'PLN-ORDER-999',
            ], 200),
            'https://api.midtrans.com/v2/PLN-ERR-401/status' => Http::response([
                'status_message' => 'Unauthorized server_key='.self::TEST_SERVER_KEY.' Basic '.base64_encode(self::TEST_SERVER_KEY.':'),
            ], 401),
        ]);

        /** @var MidtransClient $client */
        $client = app(MidtransClient::class);
        $statusPayload = $client->getTransactionStatus('PLN-ORDER-999');

        $this->assertSame('pending', $statusPayload['transaction_status']);
        $this->assertSame('PLN-ORDER-999', $statusPayload['order_id']);

        try {
            $client->getTransactionStatus('PLN-ERR-401');
            $this->fail('Expected MidtransPaymentException was not thrown.');
        } catch (MidtransPaymentException $e) {
            $this->assertSame(503, $e->statusCode());
            $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::TEST_SERVER_KEY, json_encode($e->diagnostics()) ?: '');
        }
    }

    public function test_env_credentials_activate_midtrans_with_seeded_defaults_and_respect_explicit_env_disable(): void
    {
        // Kondisi default SettingSeeder: midtrans.is_active = 0 dan key DB kosong,
        // lalu developer mengisi MIDTRANS_CLIENT_KEY & MIDTRANS_SERVER_KEY di .env.
        config([
            'services.midtrans.is_active' => null,
            'services.midtrans.is_production' => false,
            'services.midtrans.merchant_id' => 'G111222333',
            'services.midtrans.client_key' => self::TEST_CLIENT_KEY,
            'services.midtrans.server_key' => self::TEST_SERVER_KEY,
        ]);

        $customer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer);

        $page = $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $order));

        $page->assertStatus(200)
            ->assertSee('Bayar Sekarang')
            ->assertDontSee('Pembayaran Belum Aktif');

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-env-fallback',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-env-fallback',
            ], 201),
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(201)
            ->assertJsonPath('data.snap_token', 'snap-token-env-fallback');

        // Jika MIDTRANS_IS_ACTIVE=false diset eksplisit di .env, integrasi tetap nonaktif.
        config(['services.midtrans.is_active' => false]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $order))
            ->assertStatus(200)
            ->assertSee('Pembayaran Belum Aktif')
            ->assertDontSee('Bayar Sekarang');

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'midtrans_integration_inactive');
    }

    public function test_midtrans_check_command_validates_connectivity_and_reports_auth_errors_without_leaking_keys(): void
    {
        $this->configureMidtrans();

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/PLN-HEALTHCHECK-PROBE/status' => Http::sequence()
                ->push(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404)
                ->push(['status_message' => 'Access denied due to unauthorized transaction, server_key='.self::TEST_SERVER_KEY], 401),
        ]);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('Mode           : SANDBOX')
            ->expectsOutputToContain('VALID')
            ->doesntExpectOutputToContain(self::TEST_SERVER_KEY)
            ->assertExitCode(0);

        $this->artisan('midtrans:check')
            ->expectsOutputToContain('HTTP Status : 401')
            ->doesntExpectOutputToContain(self::TEST_SERVER_KEY)
            ->assertExitCode(1);
    }

    public function test_customer_can_sync_settlement_status_via_core_api_check_endpoint_and_hides_pay_button(): void
    {
        $this->configureMidtrans();

        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $order = $this->createOrderForCustomer($customer, [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P1-CORE01',
            'snap_token' => 'snap-core-sync-01',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ]);

        // Isolasi kepemilikan: customer lain ditolak 403
        $this->actingAs($otherCustomer, 'customer')
            ->postJson(route('customer.orders.payment.status', $order))
            ->assertStatus(403);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/'.$payment->midtrans_order_id.'/status' => Http::sequence()
                ->push(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404)
                ->push([
                    'status_code' => '200',
                    'transaction_id' => 'trx-core-sync-999',
                    'order_id' => $payment->midtrans_order_id,
                    'gross_amount' => '175000.00',
                    'payment_type' => 'bank_transfer',
                    'transaction_status' => 'settlement',
                    'fraud_status' => 'accept',
                    'transaction_time' => '2026-11-22 19:00:00',
                    'settlement_time' => '2026-11-22 19:05:00',
                ], 200),
        ]);

        // 1. Jika belum dipilih di Midtrans (404), tetap aman 200 tanpa error 503
        $notFoundRes = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.status', $order));
        $notFoundRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.found_on_midtrans', false)
            ->assertJsonPath('data.is_paid', false);

        // 2. Setelah settlement di Midtrans Core API, order & payment langsung tersinkronisasi lunas
        $paidRes = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.status', $order));
        $paidRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.found_on_midtrans', true)
            ->assertJsonPath('data.is_paid', true)
            ->assertJsonPath('data.order_status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $paidRes->getContent());
        $this->assertSame('paid', $order->fresh()->order_status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('settlement', $payment->fresh()->status);
        $this->assertTrue(PaymentTransaction::where('payment_id', $payment->id)
            ->where('event_type', 'status_check_settlement')
            ->exists());

        // 3. Halaman detail pesanan tidak lagi menampilkan tombol Bayar Sekarang
        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $order))
            ->assertStatus(200)
            ->assertDontSee('Bayar Sekarang')
            ->assertDontSee('Sudah Bayar? Cek Status Pembayaran');
    }
}
