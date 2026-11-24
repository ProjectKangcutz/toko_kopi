<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\OrderTrackingEvent;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentHistory;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase9EndToEndAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected const TEST_MIDTRANS_SERVER_KEY = 'SB-Mid-server-PHASE9-SECRET-KEY-8888';

    protected const TEST_MIDTRANS_CLIENT_KEY = 'SB-Mid-client-PHASE9-PUBLIC-KEY-4444';

    protected const TEST_RAJAONGKIR_API_KEY = 'RAJAONGKIR-PHASE9-SECRET-KEY-7777';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ShippingCourierSeeder::class);

        $this->configureExternalIntegrations();
    }

    protected function configureExternalIntegrations(): void
    {
        config([
            'services.midtrans.is_production' => false,
            'services.midtrans.merchant_id' => 'G999888777',
            'services.midtrans.client_key' => self::TEST_MIDTRANS_CLIENT_KEY,
            'services.midtrans.server_key' => self::TEST_MIDTRANS_SERVER_KEY,
            'services.rajaongkir.base_url' => 'https://rajaongkir.komerce.id/api/v1',
            'services.rajaongkir.api_key' => self::TEST_RAJAONGKIR_API_KEY,
            'services.rajaongkir.origin_id' => '4879',
            'services.rajaongkir.timeout' => 10,
        ]);

        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('midtrans', 'is_active', '1', 'boolean', false);
        $settings->set('midtrans', 'is_production', '0', 'boolean', false);
        $settings->set('midtrans', 'merchant_id', 'G999888777', 'string', false);
        $settings->set('midtrans', 'client_key', self::TEST_MIDTRANS_CLIENT_KEY, 'string', false);
        $settings->set('midtrans', 'server_key', self::TEST_MIDTRANS_SERVER_KEY, 'encrypted', false);

        $settings->set('rajaongkir', 'is_active', '1', 'boolean', false);
        $settings->set('rajaongkir', 'base_url', 'https://rajaongkir.komerce.id/api/v1', 'string', false);
        $settings->set('rajaongkir', 'api_key', self::TEST_RAJAONGKIR_API_KEY, 'encrypted', false);
        $settings->set('rajaongkir', 'origin_id', '4879', 'string', false);
        $settings->set('rajaongkir', 'timeout', '10', 'integer', false);
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = bin2hex(random_bytes(4));

        return Customer::create(array_merge([
            'name' => 'Pelanggan Uji '.$suffix,
            'email' => "p9-cust-{$suffix}@example.com",
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ], $overrides));
    }

    protected function createAdmin(string $roleSlug = 'super-admin'): User
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = User::create([
            'name' => 'Admin Uji '.$suffix,
            'email' => "p9-admin-{$suffix}@example.com",
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    protected function createAddress(Customer $customer): CustomerAddress
    {
        return $customer->addresses()->create([
            'label' => 'Alamat Utama',
            'recipient_name' => $customer->name,
            'recipient_phone' => '081299988877',
            'address_line' => 'Jl. Malioboro No. 45',
            'province_id' => '5',
            'province_name' => 'DI Yogyakarta',
            'city_id' => '501',
            'city_name' => 'Kota Yogyakarta',
            'district_id' => '6991',
            'district_name' => 'Gedongtengen',
            'subdistrict_id' => '73539',
            'subdistrict_name' => 'Sosromenduran',
            'postal_code' => '55271',
            'is_primary' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function buildSignedMidtransWebhook(array $overrides = []): array
    {
        $payload = array_merge([
            'order_id' => 'PLN-20261122-TEST01-P1-ABCDEF',
            'transaction_id' => 'trx-p9-'.bin2hex(random_bytes(4)),
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '194000.00',
            'payment_type' => 'bank_transfer',
            'fraud_status' => 'accept',
            'transaction_time' => '2026-11-22 10:00:00',
            'settlement_time' => '2026-11-22 10:05:00',
            'va_numbers' => [
                ['bank' => 'bca', 'va_number' => '98765432100'],
            ],
        ], $overrides);

        if (! array_key_exists('signature_key', $overrides)) {
            $payload['signature_key'] = hash(
                'sha512',
                (string) $payload['order_id']
                .(string) $payload['status_code']
                .(string) $payload['gross_amount']
                .self::TEST_MIDTRANS_SERVER_KEY
            );
        }

        return $payload;
    }

    public function test_full_end_to_end_order_payment_webhook_and_fulfillment_flow(): void
    {
        $customer = $this->createCustomer();
        $admin = $this->createAdmin('super-admin');
        $address = $this->createAddress($customer);

        $product = Product::create([
            'sku' => 'KOPI-VAR-E2E',
            'name' => 'Kopi Nusantara Pilihan',
            'slug' => 'kopi-nusantara-pilihan-e2e',
            'description' => 'Biji kopi arabika panen petani lokal.',
            'cost_price' => 50000,
            'price' => 85000,
            'stock' => 0,
            'reserved_stock' => 0,
            'weight_grams' => 500,
            'has_variants' => true,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'KOPI-VAR-E2E-250G',
            'name' => 'Giling Halus 250g',
            'cost_price' => 50000,
            'price' => 85000,
            'weight_grams' => 450,
            'stock' => 10,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => [
                    'message' => 'Success Calculate Domestic Shipping Cost',
                    'code' => 200,
                    'status' => 'success',
                ],
                'data' => [
                    [
                        'name' => 'Jalur Nugraha Ekakurir (JNE)',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost' => 24000,
                        'etd' => '2-3 day',
                    ],
                ],
            ], 200),
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-e2e-sandbox-001',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-e2e-sandbox-001',
            ], 201),
        ]);

        // 1. Pelanggan melihat produk dan menambahkan varian ke keranjang.
        $this->actingAs($customer, 'customer')
            ->get(route('storefront.product', ['slug' => $product->slug]))
            ->assertOk()
            ->assertSee('Kopi Nusantara Pilihan');

        $this->actingAs($customer, 'customer')
            ->postJson(route('storefront.cart.add'), [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart_item_count', 2);

        // 2. Cek ongkir server-side.
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rates.0.courier_code', 'jne')
            ->assertJsonPath('data.rates.0.cost', 24000);

        // 3. Checkout membuat order & reservasi stok secara atomik (2 x 85.000 + 24.000 = 194.000).
        $checkoutRes = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => 'e2e-checkout-key-000001',
                'customer_notes' => 'Tolong kemas pakai bubble wrap.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.grand_total', 194000);

        $orderId = (int) $checkoutRes->json('data.order_id');
        /** @var Order $order */
        $order = Order::with(['items', 'address', 'shipment', 'stockReservations'])->findOrFail($orderId);

        $this->assertSame(10, (int) $variant->fresh()->stock);
        $this->assertSame(2, (int) $variant->fresh()->reserved_stock);
        $this->assertSame('active', $order->stockReservations->first()->status);

        // 4. Pelanggan memulai pembayaran Midtrans Snap Sandbox (server-side calculation only).
        $initRes = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.snap_token', 'snap-token-e2e-sandbox-001')
            ->assertJsonPath('data.order_status', 'awaiting_payment')
            ->assertJsonPath('data.client_key', self::TEST_MIDTRANS_CLIENT_KEY);

        $this->assertStringNotContainsString(self::TEST_MIDTRANS_SERVER_KEY, $initRes->getContent());

        /** @var Payment $payment */
        $payment = Payment::where('order_id', $order->id)->firstOrFail();

        Http::assertSent(function (HttpClientRequest $request) use ($payment): bool {
            if ($request->url() !== 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return false;
            }

            $data = $request->data();

            return ($data['transaction_details']['order_id'] ?? null) === $payment->midtrans_order_id
                && (int) ($data['transaction_details']['gross_amount'] ?? 0) === 194000
                && ! empty($data['custom_expiry']['expiry_duration']);
        });

        // 5. Webhook Midtrans pending -> lalu settlement.
        $pendingPayload = $this->buildSignedMidtransWebhook([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-e2e-va-01',
            'transaction_status' => 'pending',
            'status_code' => '201',
            'gross_amount' => '194000.00',
        ]);
        $this->postJson(route('webhooks.midtrans'), $pendingPayload)->assertStatus(200);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('awaiting_payment', $order->order_status);
        $this->assertSame(10, (int) $variant->fresh()->stock);

        $settlementPayload = $this->buildSignedMidtransWebhook([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-e2e-va-01',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '194000.00',
        ]);
        $this->postJson(route('webhooks.midtrans'), $settlementPayload)->assertStatus(200);

        $order->refresh();
        $payment->refresh();
        $variant->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->order_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('settlement', $payment->status);
        $this->assertSame('bca', $payment->payment_channel);
        $this->assertSame(8, (int) $variant->stock);
        $this->assertSame(0, (int) $variant->reserved_stock);
        $this->assertSame('committed', StockReservation::where('order_id', $order->id)->value('status'));
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'out',
            'quantity' => -2,
            'stock_before' => 10,
            'stock_after' => 8,
            'reference_id' => $order->id,
        ]);

        // 6. Admin fulfillment flow: paid -> processing -> shipped -> tracking event -> delivered -> completed (oleh customer).
        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.processing', $order), ['internal_notes' => 'Siap dipacking.'])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame('processing', $order->fresh()->order_status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.shipped', $order), [
                'tracking_number' => 'JNE-E2E-998877',
                'notes' => 'Diserahkan ke gerai JNE.',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $this->assertSame('shipped', $order->order_status);
        $this->assertSame('shipped', $order->shipment_status);
        $this->assertSame('JNE-E2E-998877', $order->shipment->tracking_number);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.tracking.store', $order), [
                'location' => 'Hub Yogyakarta',
                'description' => 'Paket tiba di hub tujuan.',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertDatabaseHas('order_tracking_events', [
            'shipment_id' => $order->shipment->id,
            'location' => 'Hub Yogyakarta',
            'description' => 'Paket tiba di hub tujuan.',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.delivered', $order), ['notes' => 'Diterima penerima.'])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame('delivered', $order->fresh()->order_status);
        $this->assertSame('delivered', $order->fresh()->shipment_status);

        $this->actingAs($customer, 'customer')
            ->post(route('customer.orders.complete', $order))
            ->assertRedirect(route('customer.orders.show', $order));

        $order->refresh();
        $this->assertSame('completed', $order->order_status);
        $this->assertNotNull($order->completed_at);

        $orderStatuses = OrderStatusHistory::where('order_id', $order->id)
            ->where('status_type', 'order')
            ->orderBy('id')
            ->pluck('to_status')
            ->all();

        $this->assertSame(
            ['pending', 'awaiting_payment', 'paid', 'processing', 'shipped', 'delivered', 'completed'],
            $orderStatuses
        );
    }

    public function test_midtrans_sandbox_failure_expire_and_retry_p2_to_settlement_flow(): void
    {
        $customer = $this->createCustomer();
        $product = Product::create([
            'sku' => 'RETRY-PROD-01',
            'name' => 'Batik Tulis Madura',
            'slug' => 'batik-tulis-madura-retry',
            'description' => 'Kain batik tulis asli.',
            'cost_price' => 100000,
            'price' => 150000,
            'stock' => 5,
            'reserved_stock' => 1,
            'weight_grams' => 300,
            'has_variants' => false,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261122-RETRY01',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 150000,
            'shipping_cost' => 20000,
            'grand_total' => 170000,
            'total_weight_grams' => 300,
            'payment_due_at' => now()->addHours(10),
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 150000,
            'cost_price_snapshot' => 100000,
            'weight_grams_snapshot' => 300,
            'quantity' => 1,
            'line_total' => 150000,
        ]);

        StockReservation::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => $order->payment_due_at,
        ]);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::sequence()
                ->push(['token' => 'snap-p1-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/p1'], 201)
                ->push(['token' => 'snap-p2-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/p2'], 201),
        ]);

        // Attempt P1 dibuat
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(201)
            ->assertJsonPath('data.snap_token', 'snap-p1-token');

        $p1 = Payment::where('order_id', $order->id)->firstOrFail();
        $this->assertStringContainsString('-P1-', $p1->midtrans_order_id);

        // P1 expire lewat webhook ketika payment_due_at order masih jauh di masa depan
        $this->postJson(route('webhooks.midtrans'), $this->buildSignedMidtransWebhook([
            'order_id' => $p1->midtrans_order_id,
            'transaction_id' => 'trx-p1-exp',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'gross_amount' => '170000.00',
            'fraud_status' => null,
        ]))->assertStatus(200);

        $order->refresh();
        $p1->refresh();
        $this->assertSame('expire', $p1->status);
        $this->assertSame('expired', $order->payment_status);
        $this->assertSame('awaiting_payment', $order->order_status);
        $this->assertSame('active', StockReservation::where('order_id', $order->id)->value('status'));

        // Pelanggan retry -> membuat P2 baru
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order))
            ->assertStatus(201)
            ->assertJsonPath('data.snap_token', 'snap-p2-token')
            ->assertJsonPath('data.reused_token', false);

        $p2 = Payment::where('order_id', $order->id)->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($p1->id, $p2->id);
        $this->assertStringContainsString('-P2-', $p2->midtrans_order_id);

        // P2 berhasil dibayar via credit_card capture accept
        $this->postJson(route('webhooks.midtrans'), $this->buildSignedMidtransWebhook([
            'order_id' => $p2->midtrans_order_id,
            'transaction_id' => 'trx-p2-cc',
            'transaction_status' => 'capture',
            'status_code' => '200',
            'gross_amount' => '170000.00',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
        ]))->assertStatus(200);

        $order->refresh();
        $p1->refresh();
        $p2->refresh();
        $product->refresh();

        $this->assertSame('expire', $p1->status);
        $this->assertSame('capture', $p2->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->order_status);
        $this->assertSame(4, (int) $product->stock);
        $this->assertSame(0, (int) $product->reserved_stock);
        $this->assertSame('committed', StockReservation::where('order_id', $order->id)->value('status'));
    }

    public function test_browser_callbacks_cannot_override_payment_or_order_status(): void
    {
        $customer = $this->createCustomer();
        $order = Order::create([
            'order_number' => 'PLN-20261122-TAMPER01',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 250000,
            'shipping_cost' => 15000,
            'grand_total' => 265000,
            'total_weight_grams' => 500,
            'payment_due_at' => now()->addHours(8),
        ]);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-tamper-check',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/tamper',
            ], 201),
        ]);

        // Kirim field manipulatif dari browser ke endpoint initiate payment
        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.orders.payment.initiate', $order), [
                'gross_amount' => 1,
                'payment_status' => 'paid',
                'order_status' => 'paid',
                'transaction_status' => 'settlement',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.gross_amount', 265000)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.order_status', 'awaiting_payment');

        $this->assertSame(265000.0, (float) $response->json('data.gross_amount'));

        Http::assertSent(fn (HttpClientRequest $req): bool => (int) ($req->data()['transaction_details']['gross_amount'] ?? 0) === 265000);

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    public function test_duplicate_and_out_of_order_webhooks_preserve_payment_and_inventory_integrity(): void
    {
        $customer = $this->createCustomer();
        $product = Product::create([
            'sku' => 'CONC-PROD-01',
            'name' => 'Gula Aren Organik',
            'slug' => 'gula-aren-organik-conc',
            'description' => 'Gula aren cetak tradisional.',
            'cost_price' => 20000,
            'price' => 35000,
            'stock' => 20,
            'reserved_stock' => 3,
            'weight_grams' => 500,
            'has_variants' => false,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261122-CONC01',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 105000,
            'shipping_cost' => 15000,
            'grand_total' => 120000,
            'total_weight_grams' => 1500,
            'payment_due_at' => now()->subMinute(), // sudah lewat saat late webhook tiba
        ]);

        StockReservation::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => 'PLN-20261122-CONC01-P1-111AAA',
            'snap_token' => 'snap-conc-01',
            'gross_amount' => 120000,
            'status' => 'pending',
            'expired_at' => now()->addMinutes(30),
        ]);

        // 1. Settlement pertama berhasil dan meng-commit stok tepat 1x.
        $settlementPayload = $this->buildSignedMidtransWebhook([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-conc-001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '120000.00',
        ]);

        $this->postJson(route('webhooks.midtrans'), $settlementPayload)->assertStatus(200);
        // 2. Duplicate settlement identik -> idempotent replay, tidak double commit.
        $this->postJson(route('webhooks.midtrans'), $settlementPayload)->assertStatus(200);

        // 3. Out-of-order expire webhook datang setelah settlement -> tidak menurunkan status atau melepas stok.
        $lateExpirePayload = $this->buildSignedMidtransWebhook([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-conc-001',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'gross_amount' => '120000.00',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $lateExpirePayload)->assertStatus(200);

        // 4. Scheduler pelepasan kedaluwarsa tidak menyentuh order yang sudah paid.
        $this->artisan('inventory:release-expired')->assertExitCode(0);

        $order->refresh();
        $payment->refresh();
        $product->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->order_status);
        $this->assertSame('settlement', $payment->status);
        $this->assertSame(17, (int) $product->stock);
        $this->assertSame(0, (int) $product->reserved_stock);
        $this->assertSame('committed', StockReservation::where('order_id', $order->id)->value('status'));
        $this->assertSame(1, StockMovement::where('reference_id', $order->id)->where('type', 'out')->count());
        $this->assertSame(2, PaymentWebhookLog::where('midtrans_order_id', $payment->midtrans_order_id)->count());
    }

    public function test_security_headers_secret_redaction_wildcard_escaping_and_cross_guard_isolation(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $admin = $this->createAdmin('super-admin');

        // 1. Security headers di storefront & HSTS di HTTPS.
        $storefrontRes = $this->get(route('storefront.home'));
        $storefrontRes->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->assertStringNotContainsString(self::TEST_MIDTRANS_SERVER_KEY, $storefrontRes->getContent());
        $this->assertStringNotContainsString(self::TEST_RAJAONGKIR_API_KEY, $storefrontRes->getContent());

        $httpsRes = $this->get('https://localhost/');
        $httpsRes->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // 2. Noindex + Cache-Control no-store di /akun dan /admin.
        $customerDash = $this->actingAs($customer, 'customer')
            ->get(route('customer.dashboard'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $customerDash->headers->get('Cache-Control'));

        $adminLogin = $this->get(route('admin.login'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $adminLogin->headers->get('Cache-Control'));

        // 3. Isolasi kepemilikan & cross-guard.
        $otherOrder = Order::create([
            'order_number' => 'PLN-20261122-SEC001',
            'customer_id' => $otherCustomer->id,
            'customer_name_snapshot' => $otherCustomer->name,
            'customer_email_snapshot' => $otherCustomer->email,
            'customer_phone_snapshot' => $otherCustomer->phone,
            'is_guest' => false,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 100000,
            'shipping_cost' => 10000,
            'grand_total' => 110000,
            'total_weight_grams' => 500,
            'payment_due_at' => now()->addHours(12),
        ]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $otherOrder))
            ->assertForbidden();

        $this->actingAs($customer, 'customer')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        // 4. Escaping wildcard LIKE '%' di pencarian pesanan admin.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.index', ['search' => '%']))
            ->assertOk()
            ->assertDontSee('PLN-20261122-SEC001');

        // 5. Webhook palsu ditolak 403 tanpa mutasi DB.
        $invalidWebhook = $this->buildSignedMidtransWebhook([
            'order_id' => 'PLN-20261122-SEC001-P1-FAKE',
            'signature_key' => str_repeat('a', 128),
        ]);

        $this->postJson(route('webhooks.midtrans'), $invalidWebhook)
            ->assertStatus(403);
        $this->assertSame(0, PaymentWebhookLog::count());
    }
}
