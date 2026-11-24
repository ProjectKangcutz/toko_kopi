<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\Payment\MidtransWebhookIngestionService;
use App\Services\Payment\PaymentStateSynchronizationService;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

class MidtransPaymentStateSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    protected const TEST_SERVER_KEY = 'SB-Mid-server-SYNC7B-SECRET-8888';

    protected const TEST_CLIENT_KEY = 'SB-Mid-client-SYNC7B-PUBLIC-3333';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->configureMidtrans();
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
            'order_id' => 'PLN-20261122-SYNC01-P1-AAA111',
            'transaction_id' => 'trx-sync-0001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'payment_type' => 'bank_transfer',
            'fraud_status' => 'accept',
            'transaction_time' => '2026-11-22 10:00:00',
            'settlement_time' => '2026-11-22 10:02:00',
            'va_numbers' => [
                ['bank' => 'bca', 'va_number' => '12345678901'],
            ],
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

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{customer: Customer, order: Order, payment: Payment}
     */
    protected function createOrderWithPayment(array $orderOverrides = [], array $paymentOverrides = []): array
    {
        $suffix = bin2hex(random_bytes(3));

        $customer = Customer::create([
            'name' => 'Pelanggan Sinkronisasi',
            'email' => "sync-{$suffix}@example.com",
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $orderNumber = $orderOverrides['order_number'] ?? ('PLN-20261122-'.strtoupper($suffix));

        $order = Order::create(array_merge([
            'order_number' => $orderNumber,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 175000,
            'discount_amount' => 0,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'payment_fee' => 0,
            'grand_total' => 175000,
            'total_weight_grams' => 500,
            'payment_due_at' => now()->addHours(12),
        ], $orderOverrides));

        $payment = Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P1-'.strtoupper($suffix),
            'snap_token' => 'snap-token-'.$suffix,
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ], $paymentOverrides));

        return [
            'customer' => $customer,
            'order' => $order,
            'payment' => $payment,
        ];
    }

    public function test_settlement_synchronizes_payment_order_transaction_history_and_log(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-settle-7b-001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '175000.00',
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $payload);

        $response->assertStatus(200);

        $freshPayment = $payment->fresh();
        $freshOrder = $order->fresh();
        $log = PaymentWebhookLog::firstOrFail();

        $this->assertSame('settlement', $freshPayment->status);
        $this->assertSame('trx-settle-7b-001', $freshPayment->midtrans_transaction_id);
        $this->assertSame('bank_transfer', $freshPayment->payment_type);
        $this->assertSame('bca', $freshPayment->payment_channel);
        $this->assertSame('accept', $freshPayment->fraud_status);
        $this->assertNotNull($freshPayment->paid_at);

        $this->assertSame('paid', $freshOrder->payment_status);
        $this->assertSame('paid', $freshOrder->order_status);
        $this->assertNotNull($freshOrder->paid_at);

        $this->assertTrue($log->processed);
        $this->assertNull($log->processing_error);

        $this->assertSame(1, PaymentTransaction::count());
        $trx = PaymentTransaction::firstOrFail();
        $this->assertSame($payment->id, $trx->payment_id);
        $this->assertSame('trx-settle-7b-001', $trx->transaction_id);
        $this->assertSame('webhook_settlement', $trx->event_type);
        $this->assertSame('settlement', $trx->status);
        $this->assertTrue($trx->signature_verified);
        $this->assertSame('175000.00', (string) $trx->gross_amount);

        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'payment',
            'from_status' => 'unpaid',
            'to_status' => 'paid',
            'actor_type' => 'webhook',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'order',
            'from_status' => 'awaiting_payment',
            'to_status' => 'paid',
            'actor_type' => 'webhook',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'webhook',
            'action' => 'payment.synchronized',
            'subject_id' => $payment->id,
        ]);
    }

    public function test_capture_with_fraud_accept_marks_payment_and_order_paid(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cap-accept-001',
            'transaction_status' => 'capture',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
            'bank' => 'bni',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $freshPayment = $payment->fresh();
        $freshOrder = $order->fresh();

        $this->assertSame('capture', $freshPayment->status);
        $this->assertSame('accept', $freshPayment->fraud_status);
        $this->assertSame('credit_card', $freshPayment->payment_type);
        $this->assertSame('bni', $freshPayment->payment_channel);
        $this->assertNotNull($freshPayment->paid_at);

        $this->assertSame('paid', $freshOrder->payment_status);
        $this->assertSame('paid', $freshOrder->order_status);
        $this->assertNotNull($freshOrder->paid_at);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_capture_with_fraud_challenge_keeps_order_unpaid_and_pending(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cap-challenge-001',
            'transaction_status' => 'capture',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'payment_type' => 'credit_card',
            'fraud_status' => 'challenge',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $freshPayment = $payment->fresh();
        $freshOrder = $order->fresh();

        $this->assertSame('capture', $freshPayment->status);
        $this->assertSame('challenge', $freshPayment->fraud_status);
        $this->assertNull($freshPayment->paid_at);

        $this->assertSame('pending', $freshOrder->payment_status);
        $this->assertSame('awaiting_payment', $freshOrder->order_status);
        $this->assertNull($freshOrder->paid_at);

        $this->assertSame(1, PaymentTransaction::count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_capture_with_fraud_deny_marks_payment_deny_and_order_failed_not_paid(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cap-deny-001',
            'transaction_status' => 'capture',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'payment_type' => 'credit_card',
            'fraud_status' => 'deny',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $freshPayment = $payment->fresh();
        $freshOrder = $order->fresh();

        $this->assertSame('deny', $freshPayment->status);
        $this->assertSame('deny', $freshPayment->fraud_status);
        $this->assertNull($freshPayment->paid_at);

        $this->assertSame('failed', $freshOrder->payment_status);
        $this->assertSame('awaiting_payment', $freshOrder->order_status);
        $this->assertNull($freshOrder->paid_at);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_pending_transitions_order_to_pending_and_awaiting_payment(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-pending-001',
            'transaction_status' => 'pending',
            'status_code' => '201',
            'gross_amount' => '175000.00',
            'fraud_status' => null,
            'settlement_time' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $freshPayment = $payment->fresh();
        $freshOrder = $order->fresh();

        $this->assertSame('pending', $freshPayment->status);
        $this->assertSame('trx-pending-001', $freshPayment->midtrans_transaction_id);
        $this->assertSame('bca', $freshPayment->payment_channel);
        $this->assertNull($freshPayment->paid_at);

        $this->assertSame('pending', $freshOrder->payment_status);
        $this->assertSame('awaiting_payment', $freshOrder->order_status);
        $this->assertNull($freshOrder->paid_at);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'payment',
            'from_status' => 'unpaid',
            'to_status' => 'pending',
            'actor_type' => 'webhook',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'order',
            'from_status' => 'pending',
            'to_status' => 'awaiting_payment',
            'actor_type' => 'webhook',
        ]);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_deny_updates_payment_to_deny_and_order_payment_status_to_failed(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'payment_status' => 'pending',
            'order_status' => 'awaiting_payment',
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-deny-001',
            'transaction_status' => 'deny',
            'status_code' => '202',
            'gross_amount' => '175000.00',
            'fraud_status' => 'deny',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame('deny', $payment->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_cancel_updates_payment_to_cancel_and_order_payment_status_to_failed(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'payment_status' => 'pending',
            'order_status' => 'awaiting_payment',
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cancel-001',
            'transaction_status' => 'cancel',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame('cancel', $payment->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_expire_updates_payment_to_expire_and_order_payment_status_to_expired(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'payment_status' => 'pending',
            'order_status' => 'awaiting_payment',
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-expire-001',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'gross_amount' => '175000.00',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame('expire', $payment->fresh()->status);
        $this->assertSame('expired', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_refund_marks_payment_and_order_refunded_preserving_history_without_fake_refund_row(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now()->subHour(),
        ], [
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-paid-orig',
            'paid_at' => now()->subHour(),
        ]);

        $initialHistory = OrderStatusHistory::create([
            'order_id' => $order->id,
            'status_type' => 'payment',
            'from_status' => 'unpaid',
            'to_status' => 'paid',
            'notes' => 'Histori pelunasan awal.',
            'actor_type' => 'webhook',
            'actor_id' => null,
            'created_at' => now()->subHour(),
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-refund-001',
            'transaction_status' => 'refund',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'refund_amount' => '175000.00',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame('refund', $payment->fresh()->status);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('refunded', $order->fresh()->order_status);

        $this->assertNotNull(OrderStatusHistory::find($initialHistory->id));
        $this->assertSame(3, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'payment',
            'from_status' => 'paid',
            'to_status' => 'refunded',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'order',
            'from_status' => 'paid',
            'to_status' => 'refunded',
        ]);

        $this->assertSame(0, Refund::count());
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_partial_refund_updates_payment_status_without_closing_order_or_creating_fake_refund(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'paid_at' => now()->subHour(),
        ], [
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-paid-orig-2',
            'paid_at' => now()->subHour(),
        ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-partial-refund-001',
            'transaction_status' => 'partial_refund',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'refund_amount' => '50000.00',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame('partial_refund', $payment->fresh()->status);
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->assertSame('processing', $order->fresh()->order_status);

        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_type' => 'payment',
            'from_status' => 'paid',
            'to_status' => 'partially_refunded',
        ]);

        $this->assertSame(0, Refund::count());
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_out_of_order_pending_after_settlement_does_not_downgrade_payment_or_order(): void
    {
        $paidAt = now()->subMinutes(15);
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => $paidAt,
        ], [
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-settled-first',
            'paid_at' => $paidAt,
        ]);

        $pendingPayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-late-pending',
            'transaction_status' => 'pending',
            'status_code' => '201',
            'gross_amount' => '175000.00',
            'fraud_status' => null,
            'settlement_time' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $pendingPayload)->assertStatus(200);

        $freshPayment = $payment->fresh();
        $freshOrder = $order->fresh();

        $this->assertSame('settlement', $freshPayment->status);
        $this->assertSame('trx-settled-first', $freshPayment->midtrans_transaction_id);
        $this->assertNotNull($freshPayment->paid_at);

        $this->assertSame('paid', $freshOrder->payment_status);
        $this->assertSame('paid', $freshOrder->order_status);
        $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->count());

        // Event tetap tercatat pada payment_transactions dan log ditandai processed
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame('pending', PaymentTransaction::firstOrFail()->status);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_expire_on_old_payment_p1_does_not_downgrade_order_when_p2_is_active(): void
    {
        ['order' => $order, 'payment' => $paymentP1] = $this->createOrderWithPayment([
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'payment_due_at' => now()->addHours(10),
        ]);

        $paymentP2 = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P2-NEW001',
            'snap_token' => 'snap-token-p2',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ]);

        $expireP1Payload = $this->buildSignedPayload([
            'order_id' => $paymentP1->midtrans_order_id,
            'transaction_id' => 'trx-p1-expire',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'gross_amount' => '175000.00',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $expireP1Payload)->assertStatus(200);

        $this->assertSame('expire', $paymentP1->fresh()->status);
        $this->assertSame('pending', $paymentP2->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertSame(0, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertSame(1, PaymentTransaction::where('payment_id', $paymentP1->id)->count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_deny_on_old_payment_p1_keeps_order_payable_when_payment_due_at_future_and_p2_exists(): void
    {
        ['order' => $order, 'payment' => $paymentP1] = $this->createOrderWithPayment([
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'payment_due_at' => now()->addHours(10),
        ]);

        $paymentP2 = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P2-RETRY1',
            'snap_token' => 'snap-token-p2-retry',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ]);

        $denyP1Payload = $this->buildSignedPayload([
            'order_id' => $paymentP1->midtrans_order_id,
            'transaction_id' => 'trx-p1-deny',
            'transaction_status' => 'deny',
            'status_code' => '202',
            'gross_amount' => '175000.00',
            'fraud_status' => 'deny',
        ]);

        $this->postJson(route('webhooks.midtrans'), $denyP1Payload)->assertStatus(200);

        $this->assertSame('deny', $paymentP1->fresh()->status);
        $this->assertSame('pending', $paymentP2->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_order_in_fulfillment_processing_or_shipped_cannot_be_downgraded_by_pending_or_failure(): void
    {
        ['order' => $processingOrder, 'payment' => $processingPayment] = $this->createOrderWithPayment([
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'paid_at' => now()->subHours(2),
        ], [
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-proc-paid',
            'paid_at' => now()->subHours(2),
        ]);

        ['order' => $shippedOrder, 'payment' => $shippedPayment] = $this->createOrderWithPayment([
            'order_status' => 'shipped',
            'payment_status' => 'paid',
            'shipment_status' => 'shipped',
            'paid_at' => now()->subHours(4),
        ], [
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-ship-paid',
            'paid_at' => now()->subHours(4),
        ]);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $processingPayment->midtrans_order_id,
            'transaction_id' => 'trx-proc-late-pending',
            'transaction_status' => 'pending',
            'status_code' => '201',
            'fraud_status' => null,
        ]))->assertStatus(200);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $shippedPayment->midtrans_order_id,
            'transaction_id' => 'trx-ship-late-expire',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]))->assertStatus(200);

        $this->assertSame('settlement', $processingPayment->fresh()->status);
        $this->assertSame('paid', $processingOrder->fresh()->payment_status);
        $this->assertSame('processing', $processingOrder->fresh()->order_status);

        $this->assertSame('settlement', $shippedPayment->fresh()->status);
        $this->assertSame('paid', $shippedOrder->fresh()->payment_status);
        $this->assertSame('shipped', $shippedOrder->fresh()->order_status);

        $this->assertSame(0, OrderStatusHistory::count());
        $this->assertSame(2, PaymentTransaction::count());
        $this->assertSame(2, PaymentWebhookLog::where('processed', true)->count());
    }

    public function test_gross_amount_mismatch_is_quarantined_without_marking_paid(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment([], [
            'gross_amount' => 175000,
        ]);

        $tamperedAmountPayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-mismatch-001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '150000.00',
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $tamperedAmountPayload);
        $response->assertStatus(200);

        $log = PaymentWebhookLog::firstOrFail();
        $this->assertTrue($log->processed);
        $this->assertNotNull($log->processing_error);
        $this->assertStringContainsString('gross_amount', $log->processing_error);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $log->processing_error);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_valid_webhook_for_missing_payment_marks_log_processed_with_safe_error_and_returns_200(): void
    {
        $payload = $this->buildSignedPayload([
            'order_id' => 'PLN-20261122-NOTFOUND-P1-999999',
            'transaction_id' => 'trx-missing-payment-001',
            'transaction_status' => 'settlement',
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $payload);
        $response->assertStatus(200);

        $this->assertSame(1, PaymentWebhookLog::count());
        $log = PaymentWebhookLog::firstOrFail();
        $this->assertTrue($log->signature_valid);
        $this->assertTrue($log->processed);
        $this->assertNotNull($log->processing_error);
        $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $log->processing_error);

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_duplicate_identical_webhook_is_idempotent_without_duplicate_transactions_or_history(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-dup-7b-001',
            'transaction_status' => 'settlement',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);
        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'payment.synchronized')->count());
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
    }

    public function test_retry_of_existing_unprocessed_webhook_log_completes_synchronization(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-retry-unprocessed-001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'fraud_status' => 'accept',
        ]);

        $idempotencyKey = MidtransWebhookIngestionService::buildIdempotencyKey(
            $payload['order_id'],
            $payload['transaction_id'],
            $payload['transaction_status'],
            $payload['status_code'],
            $payload['fraud_status'],
            $payload['gross_amount']
        );

        $existingLog = PaymentWebhookLog::create([
            'idempotency_key' => $idempotencyKey,
            'gateway' => 'midtrans',
            'midtrans_order_id' => $payload['order_id'],
            'transaction_id' => $payload['transaction_id'],
            'transaction_status' => $payload['transaction_status'],
            'status_code' => $payload['status_code'],
            'signature_valid' => true,
            'processed' => false,
            'processing_error' => null,
            'headers' => ['content-type' => 'application/json'],
            'payload' => collect($payload)->except('signature_key')->all(),
            'received_at' => now()->subMinutes(2),
        ]);

        $response = $this->postJson(route('webhooks.midtrans'), $payload);
        $response->assertStatus(200);

        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertTrue($existingLog->fresh()->processed);
        $this->assertNull($existingLog->fresh()->processing_error);

        $this->assertSame('settlement', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $order->fresh()->order_status);
        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_database_exception_during_synchronization_rolls_back_all_changes_and_returns_500(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-rollback-001',
            'transaction_status' => 'settlement',
        ]);

        OrderStatusHistory::creating(static function (): void {
            throw new QueryException(
                'mysql',
                'insert into order_status_histories ...',
                [],
                new PDOException('SQLSTATE[HY000]: Simulated write failure with '.self::TEST_SERVER_KEY)
            );
        });

        try {
            $response = $this->postJson(route('webhooks.midtrans'), $payload);

            $response->assertStatus(500)
                ->assertJson(['message' => 'Unable to process webhook notification']);

            $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $response->getContent());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());

            $log = PaymentWebhookLog::firstOrFail();
            $this->assertFalse($log->processed);

            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->paid_at);
            $this->assertNull($payment->fresh()->midtrans_transaction_id);

            $this->assertSame('unpaid', $order->fresh()->payment_status);
            $this->assertSame('awaiting_payment', $order->fresh()->order_status);
            $this->assertNull($order->fresh()->paid_at);

            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame(0, OrderStatusHistory::count());
        } finally {
            OrderStatusHistory::flushEventListeners();
        }
    }

    public function test_phase_7b_never_mutates_inventory_stock_reservations_or_stock_movements(): void
    {
        $product = Product::create([
            'name' => 'Tenun Ikat Sikka',
            'slug' => 'tenun-ikat-sikka',
            'sku' => 'TENUN-7B-001',
            'description' => 'Kain tenun ikat tradisional khas Sikka Nusa Tenggara Timur.',
            'price' => 175000,
            'cost_price' => 95000,
            'stock' => 20,
            'reserved_stock' => 4,
            'weight_grams' => 400,
            'is_active' => true,
            'has_variants' => false,
        ]);

        ['order' => $orderSettle, 'payment' => $paySettle] = $this->createOrderWithPayment();
        ['order' => $orderExpire, 'payment' => $payExpire] = $this->createOrderWithPayment();
        ['order' => $orderCancel, 'payment' => $payCancel] = $this->createOrderWithPayment();
        ['order' => $orderRefund, 'payment' => $payRefund] = $this->createOrderWithPayment([
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now()->subHour(),
        ], [
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-inv-refund-orig',
            'paid_at' => now()->subHour(),
        ]);

        $resSettle = StockReservation::create([
            'order_id' => $orderSettle->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);
        $resExpire = StockReservation::create([
            'order_id' => $orderExpire->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);
        $resCancel = StockReservation::create([
            'order_id' => $orderCancel->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);
        $resRefund = StockReservation::create([
            'order_id' => $orderRefund->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 1,
            'status' => 'committed',
            'expires_at' => now()->addHours(12),
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'type' => 'reservation',
            'quantity' => -4,
            'stock_before' => 20,
            'stock_after' => 16,
            'reference_type' => Order::class,
            'reference_id' => $orderSettle->id,
            'notes' => 'Reservasi awal pengujian isolasi Phase 7B.',
            'created_at' => now(),
        ]);

        $movementCountBefore = StockMovement::count();

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $paySettle->midtrans_order_id,
            'transaction_id' => 'trx-inv-settle',
            'transaction_status' => 'settlement',
        ]))->assertStatus(200);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payExpire->midtrans_order_id,
            'transaction_id' => 'trx-inv-expire',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]))->assertStatus(200);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payCancel->midtrans_order_id,
            'transaction_id' => 'trx-inv-cancel',
            'transaction_status' => 'cancel',
            'fraud_status' => null,
        ]))->assertStatus(200);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payRefund->midtrans_order_id,
            'transaction_id' => 'trx-inv-refund',
            'transaction_status' => 'refund',
        ]))->assertStatus(200);

        $this->assertSame(19, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $product->fresh()->reserved_stock);
        $this->assertSame('committed', $resSettle->fresh()->status);
        $this->assertSame('active', $resExpire->fresh()->status);
        $this->assertSame('active', $resCancel->fresh()->status);
        $this->assertSame('committed', $resRefund->fresh()->status);
    }

    public function test_already_processed_log_is_noop_when_synchronized_directly_or_replayed(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-noop-001',
            'transaction_status' => 'settlement',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $log = PaymentWebhookLog::firstOrFail();
        $this->assertTrue($log->processed);

        /** @var PaymentStateSynchronizationService $syncService */
        $syncService = app(PaymentStateSynchronizationService::class);
        $result = $syncService->synchronizeFromWebhookLog($log);

        $this->assertSame('already_processed', $result['status']);
        $this->assertTrue($result['processed']);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame(1, PaymentTransaction::count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_invalid_signature_returns_403_and_never_triggers_synchronization(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createOrderWithPayment();

        $forgedPayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-forged-7b',
            'transaction_status' => 'settlement',
            'signature_key' => 'forged-invalid-signature',
        ]);

        $this->postJson(route('webhooks.midtrans'), $forgedPayload)
            ->assertStatus(403)
            ->assertJson(['message' => 'Invalid signature']);

        $this->assertSame(0, PaymentWebhookLog::count());
        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, OrderStatusHistory::count());
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
    }
}
