<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\Payment\MidtransWebhookIngestionService;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

class MidtransWebhookVerificationAndLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected const TEST_SERVER_KEY = 'SB-Mid-server-WEBHOOK-SECRET-7777';

    protected const TEST_CLIENT_KEY = 'SB-Mid-client-WEBHOOK-PUBLIC-2222';

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
        string $serverKey = self::TEST_SERVER_KEY,
        string $clientKey = self::TEST_CLIENT_KEY
    ): void {
        config([
            'services.midtrans.is_production' => false,
            'services.midtrans.merchant_id' => 'G987654321',
            'services.midtrans.client_key' => $clientKey !== '' ? $clientKey : null,
            'services.midtrans.server_key' => $serverKey !== '' ? $serverKey : null,
        ]);

        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('midtrans', 'is_active', $isActive ? '1' : '0', 'boolean', false);
        $settings->set('midtrans', 'is_production', '0', 'boolean', false);
        $settings->set('midtrans', 'merchant_id', 'G987654321', 'string', false);
        $settings->set('midtrans', 'client_key', $clientKey, 'string', false);
        $settings->set('midtrans', 'server_key', $serverKey, 'encrypted', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function buildSignedPayload(array $overrides = [], string $serverKey = self::TEST_SERVER_KEY): array
    {
        $payload = array_merge([
            'order_id' => 'PLN-20261121-TEST01-P1-ABCDEF',
            'transaction_id' => 'trx-midtrans-0001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'payment_type' => 'bank_transfer',
            'fraud_status' => 'accept',
            'transaction_time' => '2026-11-21 15:00:00',
            'settlement_time' => '2026-11-21 15:01:00',
        ], $overrides);

        if (! array_key_exists('signature_key', $overrides)) {
            $payload['signature_key'] = hash(
                'sha512',
                (string) $payload['order_id']
                .(string) $payload['status_code']
                .(string) $payload['gross_amount']
                .$serverKey
            );
        }

        return $payload;
    }

    public function test_valid_signature_returns_200_and_creates_single_unprocessed_log(): void
    {
        $this->configureMidtrans();

        $customer = Customer::create([
            'name' => 'Pelanggan Webhook Log',
            'email' => 'log-test@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261121-TEST01',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 175000,
            'grand_total' => 175000,
            'payment_due_at' => now()->addHours(12),
        ]);

        Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => 'PLN-20261121-TEST01-P1-ABCDEF',
            'snap_token' => 'snap-token-log-test',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-TEST01-P1-ABCDEF',
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $payload);

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);

        $this->assertSame(1, PaymentWebhookLog::count());

        $log = PaymentWebhookLog::firstOrFail();
        $expectedIdempotencyKey = MidtransWebhookIngestionService::buildIdempotencyKey(
            $payload['order_id'],
            $payload['transaction_id'],
            $payload['transaction_status'],
            $payload['status_code'],
            $payload['fraud_status'],
            $payload['gross_amount']
        );

        $this->assertSame(64, strlen($log->idempotency_key));
        $this->assertSame($expectedIdempotencyKey, $log->idempotency_key);
        $this->assertSame('midtrans', $log->gateway);
        $this->assertSame($payload['order_id'], $log->midtrans_order_id);
        $this->assertSame($payload['transaction_id'], $log->transaction_id);
        $this->assertSame($payload['transaction_status'], $log->transaction_status);
        $this->assertSame($payload['status_code'], $log->status_code);
        $this->assertTrue($log->signature_valid);
        $this->assertTrue($log->processed);
        $this->assertNull($log->processing_error);
        $this->assertNotNull($log->received_at);
        $this->assertSame('accept', $log->payload['fraud_status'] ?? null);
        $this->assertSame('bank_transfer', $log->payload['payment_type'] ?? null);
        $this->assertSame('2026-11-21 15:01:00', $log->payload['settlement_time'] ?? null);
        $this->assertArrayNotHasKey('signature_key', $log->payload);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $response->getContent());
    }

    public function test_invalid_signature_returns_403_and_does_not_create_log(): void
    {
        $this->configureMidtrans();

        $payload = $this->buildSignedPayload([
            'signature_key' => 'invalid-signature-value',
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $payload);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Invalid signature']);

        $this->assertSame(0, PaymentWebhookLog::count());
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $response->getContent());
    }

    public function test_non_scalar_array_payload_does_not_cause_500_and_is_rejected(): void
    {
        $this->configureMidtrans();

        $cases = [
            ['order_id' => ['nested'], 'status_code' => '200', 'gross_amount' => '10000.00', 'signature_key' => 'abc', 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => ['200'], 'gross_amount' => '10000.00', 'signature_key' => 'abc', 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => ['10000.00'], 'signature_key' => 'abc', 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => '10000.00', 'signature_key' => ['abc'], 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => '10000.00', 'signature_key' => 'abc', 'transaction_status' => ['pending']],
            ['order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => 10000, 'signature_key' => 'abc', 'transaction_status' => 'pending'],
        ];

        foreach ($cases as $badPayload) {
            $response = $this->postJson(route('webhooks.midtrans'), $badPayload);
            $this->assertContains($response->getStatusCode(), [400, 422]);
        }

        // Array pada field opsional setelah signature valid juga ditolak tanpa 500
        $signedBadOptional = $this->buildSignedPayload([
            'transaction_id' => ['bad-trx'],
        ]);
        $optionalResponse = $this->postJson(route('webhooks.midtrans'), $signedBadOptional);
        $this->assertContains($optionalResponse->getStatusCode(), [400, 422]);

        $this->assertSame(0, PaymentWebhookLog::count());
    }

    public function test_empty_or_missing_required_fields_are_rejected(): void
    {
        $this->configureMidtrans();

        $emptyFieldCases = [
            ['order_id' => '', 'status_code' => '200', 'gross_amount' => '10000.00', 'signature_key' => 'abc', 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => '   ', 'gross_amount' => '10000.00', 'signature_key' => 'abc', 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => '', 'signature_key' => 'abc', 'transaction_status' => 'pending'],
            ['order_id' => 'ORD-1', 'status_code' => '200', 'gross_amount' => '10000.00', 'signature_key' => '', 'transaction_status' => 'pending'],
            $this->buildSignedPayload(['transaction_status' => '']),
        ];

        foreach ($emptyFieldCases as $payload) {
            $response = $this->postJson(route('webhooks.midtrans'), $payload);
            $this->assertContains($response->getStatusCode(), [400, 422]);
        }

        // Valid signature tanpa transaction_status juga wajib ditolak
        $missingStatusPayload = $this->buildSignedPayload();
        unset($missingStatusPayload['transaction_status']);
        $missingResponse = $this->postJson(route('webhooks.midtrans'), $missingStatusPayload);
        $this->assertContains($missingResponse->getStatusCode(), [400, 422]);

        $this->assertSame(0, PaymentWebhookLog::count());
    }

    public function test_values_exceeding_column_limits_are_rejected_without_truncation(): void
    {
        $this->configureMidtrans();

        $tooLongOrderId = $this->buildSignedPayload([
            'order_id' => str_repeat('A', 81),
        ]);
        $this->assertContains($this->postJson(route('webhooks.midtrans'), $tooLongOrderId)->getStatusCode(), [400, 422]);

        $tooLongStatusCode = $this->buildSignedPayload([
            'status_code' => str_repeat('2', 11),
        ]);
        $this->assertContains($this->postJson(route('webhooks.midtrans'), $tooLongStatusCode)->getStatusCode(), [400, 422]);

        $tooLongTransactionStatus = $this->buildSignedPayload([
            'transaction_status' => str_repeat('s', 41),
        ]);
        $this->assertContains($this->postJson(route('webhooks.midtrans'), $tooLongTransactionStatus)->getStatusCode(), [400, 422]);

        $tooLongTransactionId = $this->buildSignedPayload([
            'transaction_id' => str_repeat('T', 101),
        ]);
        $this->assertContains($this->postJson(route('webhooks.midtrans'), $tooLongTransactionId)->getStatusCode(), [400, 422]);

        $this->assertSame(0, PaymentWebhookLog::count());
    }

    public function test_empty_server_key_returns_503_without_writing_log(): void
    {
        $this->configureMidtrans(isActive: true, serverKey: '');

        $response = $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([], 'any'));

        $response->assertStatus(503)
            ->assertJson(['message' => 'Payment gateway not configured']);

        $this->assertSame(0, PaymentWebhookLog::count());
    }

    public function test_webhook_is_accepted_when_midtrans_is_inactive_if_server_key_is_configured(): void
    {
        $this->configureMidtrans(isActive: false, serverKey: self::TEST_SERVER_KEY);

        $payload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-INACTIVE-P1-XYZ123',
            'transaction_status' => 'pending',
            'status_code' => '201',
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $payload);

        $response->assertStatus(200);
        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_identical_notifications_sent_twice_produce_single_log_row(): void
    {
        $this->configureMidtrans();

        $payload = $this->buildSignedPayload();

        $first = $this->postJson(route('webhooks.midtrans'), $payload);
        $second = $this->postJson(route('webhooks.midtrans'), $payload);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertSame(1, PaymentWebhookLog::count());
    }

    public function test_different_events_for_same_order_produce_two_distinct_log_rows(): void
    {
        $this->configureMidtrans();

        $pendingPayload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-MULTI-P1-112233',
            'transaction_id' => 'trx-multi-001',
            'transaction_status' => 'pending',
            'status_code' => '201',
            'fraud_status' => null,
        ]);

        $settlementPayload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-MULTI-P1-112233',
            'transaction_id' => 'trx-multi-001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'fraud_status' => 'accept',
        ]);

        $this->postJson(route('webhooks.midtrans'), $pendingPayload)->assertStatus(200);
        $this->postJson(route('webhooks.midtrans'), $settlementPayload)->assertStatus(200);

        $this->assertSame(2, PaymentWebhookLog::count());

        $keys = PaymentWebhookLog::pluck('idempotency_key')->all();
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_unique_constraint_violation_exception_returns_200_without_duplicate_rows(): void
    {
        $this->configureMidtrans();

        $payload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-RACE-P1-998877',
        ]);

        // Simulasikan race condition saat insert paralel memicu UniqueConstraintViolationException
        PaymentWebhookLog::creating(static function (): void {
            throw new UniqueConstraintViolationException(
                'mysql',
                'insert into payment_webhook_logs ...',
                [],
                new PDOException('Duplicate entry for key payment_webhook_logs_idempotency_key_unique', 23000)
            );
        });

        try {
            $response = $this->postJson(route('webhooks.midtrans'), $payload);
            $response->assertStatus(200);
            $this->assertSame(0, PaymentWebhookLog::count());
        } finally {
            PaymentWebhookLog::flushEventListeners();
        }
    }

    public function test_non_duplicate_database_failure_returns_500_without_leaking_credentials(): void
    {
        $this->configureMidtrans();

        $payload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-DBERR-P1-445566',
        ]);

        PaymentWebhookLog::creating(static function (): void {
            throw new QueryException(
                'mysql',
                'insert into payment_webhook_logs ...',
                [],
                new PDOException('SQLSTATE[HY000]: General error with '.self::TEST_SERVER_KEY)
            );
        });

        try {
            $response = $this->postJson(route('webhooks.midtrans'), $payload);
            $response->assertStatus(500)
                ->assertJson(['message' => 'Unable to process webhook notification']);

            $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $response->getContent());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertSame(0, PaymentWebhookLog::count());
        } finally {
            PaymentWebhookLog::flushEventListeners();
        }
    }

    public function test_sensitive_headers_and_arbitrary_secret_payload_fields_are_not_stored(): void
    {
        $this->configureMidtrans();

        $payload = $this->buildSignedPayload([
            'server_key' => self::TEST_SERVER_KEY,
            'secret_note' => 'should-not-be-saved',
        ]);

        $response = $this->withHeaders([
            'Content-Type' => 'application/json',
            'User-Agent' => 'Midtrans-Webhook/1.0',
            'X-Request-Id' => 'req-midtrans-12345',
            'Authorization' => 'Bearer top-secret-bearer',
            'Cookie' => 'laravel_session=secret-cookie',
            'Set-Cookie' => 'secret=1',
            'X-Api-Key' => 'secret-api-key-999',
        ])->postJson(route('webhooks.midtrans'), $payload);

        $response->assertStatus(200);

        $log = PaymentWebhookLog::firstOrFail();

        $this->assertSame('Midtrans-Webhook/1.0', $log->headers['user-agent'] ?? null);
        $this->assertSame('req-midtrans-12345', $log->headers['x-request-id'] ?? null);
        $this->assertArrayNotHasKey('authorization', $log->headers);
        $this->assertArrayNotHasKey('cookie', $log->headers);
        $this->assertArrayNotHasKey('set-cookie', $log->headers);
        $this->assertArrayNotHasKey('x-api-key', $log->headers);

        $this->assertArrayNotHasKey('signature_key', $log->payload);
        $this->assertArrayNotHasKey('server_key', $log->payload);
        $this->assertArrayNotHasKey('secret_note', $log->payload);
    }

    public function test_invalid_signature_cannot_poison_or_block_subsequent_valid_event(): void
    {
        $this->configureMidtrans();

        $validPayload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-POISON-P1-123456',
            'transaction_id' => 'trx-poison-001',
            'transaction_status' => 'settlement',
        ]);

        $forgedPayload = array_merge($validPayload, [
            'signature_key' => 'forged-signature-attempt',
        ]);

        // Penyerang mencoba mengirim event yang sama lebih dulu dengan signature palsu
        $this->postJson(route('webhooks.midtrans'), $forgedPayload)->assertStatus(403);
        $this->assertSame(0, PaymentWebhookLog::count());

        // Webhook asli dari Midtrans untuk event tersebut tetap harus diterima dan dicatat
        $this->postJson(route('webhooks.midtrans'), $validPayload)->assertStatus(200);
        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->signature_valid);
    }

    public function test_phase_7a_through_7c_end_to_end_webhook_synchronization_and_inventory_commit(): void
    {
        $this->configureMidtrans();

        $customer = Customer::create([
            'name' => 'Pelanggan Integrasi Penuh',
            'email' => 'integrasi-7c@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Batik Tulis Nusantara',
            'slug' => 'batik-tulis-nusantara',
            'sku' => 'BTK-7A-001',
            'description' => 'Batik tulis khas pengrajin lokal Nusantara.',
            'price' => 175000,
            'cost_price' => 100000,
            'stock' => 10,
            'reserved_stock' => 2,
            'weight_grams' => 300,
            'is_active' => true,
            'has_variants' => false,
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261121-INTEG7C',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 175000,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'payment_fee' => 0,
            'grand_total' => 175000,
            'total_weight_grams' => 600,
            'payment_due_at' => now()->addHours(12),
        ]);

        $midtransOrderId = $order->order_number.'-P1-INTEG7C';

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $midtransOrderId,
            'snap_token' => 'snap-integ-7c',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ]);

        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'event_type' => 'snap_created',
            'status' => 'pending',
            'gross_amount' => 175000,
            'signature_verified' => true,
            'occurred_at' => now(),
        ]);

        $reservation = StockReservation::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'type' => 'reservation',
            'quantity' => -2,
            'stock_before' => 10,
            'stock_after' => 8,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'notes' => 'Reservasi awal',
            'created_at' => now(),
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $midtransOrderId,
            'transaction_id' => 'trx-integ-7c-001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '175000.00',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $freshOrder = $order->fresh();
        $freshPayment = $payment->fresh();
        $freshProduct = $product->fresh();
        $freshReservation = $reservation->fresh();

        $this->assertSame('paid', $freshOrder->order_status);
        $this->assertSame('paid', $freshOrder->payment_status);
        $this->assertNotNull($freshOrder->paid_at);

        $this->assertSame('settlement', $freshPayment->status);
        $this->assertNotNull($freshPayment->paid_at);
        $this->assertSame('trx-integ-7c-001', $freshPayment->midtrans_transaction_id);

        $this->assertSame(8, (int) $freshProduct->stock);
        $this->assertSame(0, (int) $freshProduct->reserved_stock);
        $this->assertSame('committed', $freshReservation->status);

        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_endpoint_is_accessible_without_auth_or_csrf_token(): void
    {
        $this->configureMidtrans();

        $this->assertGuest('customer');
        $this->assertGuest('admin');

        $payload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261121-NOCSRF-P1-000111',
        ]);

        $response = $this->postJson('/api/webhooks/midtrans', $payload);

        $response->assertStatus(200);
        $this->assertSame(1, PaymentWebhookLog::count());
    }
}
