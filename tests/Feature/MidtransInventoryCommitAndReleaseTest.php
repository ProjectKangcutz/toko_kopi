<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PDOException;
use Tests\TestCase;

class MidtransInventoryCommitAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected const TEST_SERVER_KEY = 'SB-Mid-server-INV7C-SECRET-9999';

    protected const TEST_CLIENT_KEY = 'SB-Mid-client-INV7C-PUBLIC-4444';

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
            'order_id' => 'PLN-20261122-INV01-P1-AAA111',
            'transaction_id' => 'trx-inv7c-0001',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '175000.00',
            'payment_type' => 'bank_transfer',
            'fraud_status' => 'accept',
            'transaction_time' => '2026-11-22 11:00:00',
            'settlement_time' => '2026-11-22 11:02:00',
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

    protected function createSimpleProduct(int $stock = 10, int $reservedStock = 2): Product
    {
        $suffix = bin2hex(random_bytes(3));

        return Product::create([
            'sku' => 'SMP-7C-'.strtoupper($suffix),
            'name' => 'Kopi Arabika Gayo '.$suffix,
            'slug' => 'kopi-arabika-gayo-'.$suffix,
            'description' => 'Kopi arabika pilihan asli dataran tinggi Gayo.',
            'price' => 87500,
            'cost_price' => 50000,
            'stock' => $stock,
            'reserved_stock' => $reservedStock,
            'weight_grams' => 250,
            'is_active' => true,
            'has_variants' => false,
        ]);
    }

    /**
     * @return array{product: Product, variant: ProductVariant}
     */
    protected function createVariantProduct(int $variantStock = 8, int $variantReserved = 3): array
    {
        $suffix = bin2hex(random_bytes(3));

        $product = Product::create([
            'sku' => 'VARP-7C-'.strtoupper($suffix),
            'name' => 'Batik Cap Pekalongan '.$suffix,
            'slug' => 'batik-cap-pekalongan-'.$suffix,
            'description' => 'Batik cap tradisional Pekalongan dengan pilihan ukuran.',
            'price' => 150000,
            'cost_price' => 90000,
            'stock' => 0,
            'reserved_stock' => 0,
            'weight_grams' => 300,
            'is_active' => true,
            'has_variants' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VARV-7C-'.strtoupper($suffix),
            'name' => 'Ukuran L',
            'cost_price' => 90000,
            'price' => 150000,
            'weight_grams' => 300,
            'stock' => $variantStock,
            'reserved_stock' => $variantReserved,
            'is_active' => true,
        ]);

        return ['product' => $product, 'variant' => $variant];
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{customer: Customer, order: Order, payment: Payment, product: Product, reservation: StockReservation}
     */
    protected function createOrderWithActiveReservation(
        int $stock = 10,
        int $qty = 2,
        array $orderOverrides = [],
        array $paymentOverrides = []
    ): array {
        $suffix = bin2hex(random_bytes(3));

        $customer = Customer::create([
            'name' => 'Pelanggan Inventory 7C',
            'email' => "inv7c-{$suffix}@example.com",
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $product = $this->createSimpleProduct($stock, $qty);
        $orderNumber = $orderOverrides['order_number'] ?? ('PLN-20261122-'.strtoupper($suffix));

        $order = Order::create(array_merge([
            'order_number' => $orderNumber,
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
            'total_weight_grams' => 500,
            'payment_due_at' => now()->addHours(12),
        ], $orderOverrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'product_name_snapshot' => $product->name,
            'variant_name_snapshot' => null,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 87500,
            'cost_price_snapshot' => 50000,
            'weight_grams_snapshot' => 250,
            'quantity' => $qty,
            'line_total' => 175000,
        ]);

        $reservation = StockReservation::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => $qty,
            'status' => 'active',
            'expires_at' => $order->payment_due_at ?? now()->addHours(12),
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'type' => 'reservation',
            'quantity' => -$qty,
            'stock_before' => $stock,
            'stock_after' => max(0, $stock - $qty),
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'notes' => "Reservasi stok untuk pesanan {$order->order_number}",
            'created_at' => now()->subMinutes(10),
        ]);

        $payment = Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P1-'.strtoupper($suffix),
            'snap_token' => 'snap-inv7c-'.$suffix,
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ], $paymentOverrides));

        return [
            'customer' => $customer,
            'order' => $order,
            'payment' => $payment,
            'product' => $product,
            'reservation' => $reservation,
        ];
    }

    public function test_settlement_commits_stock_reservation_once_and_records_out_movement(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-commit-settle-001',
            'transaction_status' => 'settlement',
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $freshProduct = $product->fresh();
        $freshReservation = $reservation->fresh();

        $this->assertSame(8, (int) $freshProduct->stock);
        $this->assertSame(0, (int) $freshProduct->reserved_stock);
        $this->assertSame(8, $freshProduct->availableStock());

        $this->assertSame('committed', $freshReservation->status);
        $this->assertNotNull($freshReservation->committed_at);
        $this->assertNull($freshReservation->released_at);

        $this->assertSame(
            0,
            StockReservation::where('order_id', $order->id)->where('status', 'active')->count()
        );

        $outMovement = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'out')
            ->firstOrFail();

        $this->assertSame(-2, (int) $outMovement->quantity);
        $this->assertSame(10, (int) $outMovement->stock_before);
        $this->assertSame(8, (int) $outMovement->stock_after);
        $this->assertSame('settlement', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $order->fresh()->order_status);
    }

    public function test_capture_with_explicit_fraud_accept_commits_stock_reservation(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 12, qty: 3);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-commit-cap-accept',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame(9, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('committed', $reservation->fresh()->status);
        $this->assertSame(
            1,
            StockMovement::where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('type', 'out')
                ->count()
        );
    }

    public function test_capture_with_fraud_challenge_does_not_commit_or_release_stock(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cap-challenge-inv',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'challenge',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
        $this->assertSame('active', $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->committed_at);
        $this->assertNull($reservation->fresh()->released_at);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order->id)->whereIn('type', ['out', 'release'])->count()
        );
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_capture_with_fraud_deny_does_not_commit_stock(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cap-deny-inv',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'deny',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);

        $this->assertSame('deny', $payment->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
        $this->assertSame('active', $reservation->fresh()->status);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order->id)->whereIn('type', ['out', 'release'])->count()
        );
    }

    public function test_capture_without_or_with_unknown_fraud_status_does_not_commit_stock(): void
    {
        ['order' => $order1, 'payment' => $payment1, 'product' => $product1, 'reservation' => $res1]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        ['order' => $order2, 'payment' => $payment2, 'product' => $product2, 'reservation' => $res2]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        // Kasus 1: capture tanpa fraud_status
        $payloadWithoutFraud = $this->buildSignedPayload([
            'order_id' => $payment1->midtrans_order_id,
            'transaction_id' => 'trx-cap-no-fraud',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => null,
            'va_numbers' => [],
        ]);
        unset($payloadWithoutFraud['fraud_status']);

        $this->postJson(route('webhooks.midtrans'), $payloadWithoutFraud)->assertStatus(200);

        $this->assertSame(10, (int) $product1->fresh()->stock);
        $this->assertSame(2, (int) $product1->fresh()->reserved_stock);
        $this->assertSame('active', $res1->fresh()->status);
        $this->assertNull($payment1->fresh()->paid_at);
        $this->assertNull($order1->fresh()->paid_at);
        $this->assertSame('pending', $order1->fresh()->payment_status);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order1->id)->whereIn('type', ['out', 'release'])->count()
        );

        // Kasus 2: capture dengan fraud_status tidak dikenal
        $payloadUnknownFraud = $this->buildSignedPayload([
            'order_id' => $payment2->midtrans_order_id,
            'transaction_id' => 'trx-cap-unknown-fraud',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'manual_review',
            'va_numbers' => [],
        ]);

        $this->postJson(route('webhooks.midtrans'), $payloadUnknownFraud)->assertStatus(200);

        $this->assertSame(10, (int) $product2->fresh()->stock);
        $this->assertSame(2, (int) $product2->fresh()->reserved_stock);
        $this->assertSame('active', $res2->fresh()->status);
        $this->assertNull($payment2->fresh()->paid_at);
        $this->assertNull($order2->fresh()->paid_at);
        $this->assertSame('pending', $order2->fresh()->payment_status);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order2->id)->whereIn('type', ['out', 'release'])->count()
        );
    }

    public function test_repeated_identical_settlement_webhook_and_capture_then_settlement_do_not_double_commit(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 15, qty: 4);

        $captureAcceptPayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-repeat-cap-001',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
            'va_numbers' => [],
        ]);

        $settlementPayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-repeat-cap-001',
            'transaction_status' => 'settlement',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
            'va_numbers' => [],
        ]);

        // Event 1: capture + accept
        $this->postJson(route('webhooks.midtrans'), $captureAcceptPayload)->assertStatus(200);
        // Event 2: settlement duplikat identik
        $this->postJson(route('webhooks.midtrans'), $settlementPayload)->assertStatus(200);
        // Event 3: replay identik settlement
        $this->postJson(route('webhooks.midtrans'), $settlementPayload)->assertStatus(200);

        $this->assertSame(11, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('committed', $reservation->fresh()->status);
        $this->assertSame(
            1,
            StockMovement::where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('type', 'out')
                ->count()
        );
    }

    public function test_p1_expire_when_p2_is_active_does_not_release_stock(): void
    {
        ['order' => $order, 'payment' => $paymentP1, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2, orderOverrides: [
                'order_status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'payment_due_at' => now()->addHours(10),
            ]);

        $paymentP2 = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P2-ACTIVE',
            'snap_token' => 'snap-p2-active',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->addHours(4),
        ]);

        $expireP1Payload = $this->buildSignedPayload([
            'order_id' => $paymentP1->midtrans_order_id,
            'transaction_id' => 'trx-p1-expire-active-p2',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $expireP1Payload)->assertStatus(200);

        $this->assertSame('expire', $paymentP1->fresh()->status);
        $this->assertSame('pending', $paymentP2->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
        $this->assertSame('active', $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->released_at);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order->id)->where('type', 'release')->count()
        );
    }

    public function test_failure_events_do_not_release_stock_while_order_is_still_payable(): void
    {
        ['order' => $order, 'payment' => $paymentP1, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2, orderOverrides: [
                'order_status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'payment_due_at' => now()->addHours(8),
            ]);

        // Meski belum ada P2, order masih dalam batas payment_due_at sehingga pelanggan masih bisa bayar ulang
        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $paymentP1->midtrans_order_id,
            'transaction_id' => 'trx-single-deny-still-payable',
            'transaction_status' => 'deny',
            'status_code' => '202',
            'fraud_status' => 'deny',
        ]))->assertStatus(200);

        $this->assertSame('deny', $paymentP1->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
        $this->assertSame('active', $reservation->fresh()->status);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order->id)->where('type', 'release')->count()
        );
    }

    public function test_expired_order_with_all_payment_attempts_inactive_releases_stock_reservations(): void
    {
        ['order' => $order, 'payment' => $paymentP1, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(
                stock: 10,
                qty: 3,
                orderOverrides: [
                    'order_status' => 'awaiting_payment',
                    'payment_status' => 'pending',
                    'payment_due_at' => now()->subMinutes(5),
                ],
                paymentOverrides: [
                    'status' => 'expire',
                    'expired_at' => now()->subHours(2),
                ]
            );

        // Percobaan kedua P2 kini kedaluwarsa dan menerima webhook expire terakhir
        $paymentP2 = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P2-LAST',
            'snap_token' => 'snap-p2-last',
            'gross_amount' => 175000,
            'status' => 'pending',
            'expired_at' => now()->subMinutes(2),
        ]);

        // Kini percobaan terakhir P2 menerima webhook expire dan order sudah melewati payment_due_at
        $expireP2Payload = $this->buildSignedPayload([
            'order_id' => $paymentP2->midtrans_order_id,
            'transaction_id' => 'trx-p2-final-expire',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $expireP2Payload)->assertStatus(200);

        $this->assertSame('expire', $paymentP1->fresh()->status);
        $this->assertSame('expire', $paymentP2->fresh()->status);
        $this->assertSame('expired', $order->fresh()->payment_status);

        $freshProduct = $product->fresh();
        $freshReservation = $reservation->fresh();

        $this->assertSame(10, (int) $freshProduct->stock);
        $this->assertSame(0, (int) $freshProduct->reserved_stock);
        $this->assertSame(10, $freshProduct->availableStock());
        $this->assertSame('expired', $freshReservation->status);
        $this->assertNotNull($freshReservation->released_at);

        $releaseMovement = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', 'release')
            ->firstOrFail();

        $this->assertSame(3, (int) $releaseMovement->quantity);
        $this->assertSame(7, (int) $releaseMovement->stock_before);
        $this->assertSame(10, (int) $releaseMovement->stock_after);
    }

    public function test_cancelled_order_with_inactive_payments_releases_stock_with_released_status(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 9, qty: 2, orderOverrides: [
                'order_status' => 'cancelled',
                'payment_status' => 'pending',
                'cancelled_at' => now()->subMinute(),
            ]);

        $cancelPayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-cancelled-order-release',
            'transaction_status' => 'cancel',
            'status_code' => '200',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $cancelPayload)->assertStatus(200);

        $this->assertSame('cancel', $payment->fresh()->status);
        $this->assertSame(9, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->released_at);
        $this->assertSame(
            1,
            StockMovement::where('reference_id', $order->id)->where('type', 'release')->count()
        );
    }

    public function test_repeated_release_does_not_duplicate_stock_movements_or_cause_negative_reserved_stock(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2, orderOverrides: [
                'order_status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'payment_due_at' => now()->subMinutes(10),
            ]);

        $expirePayload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-release-twice-1',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]);

        // Event 1: expire
        $this->postJson(route('webhooks.midtrans'), $expirePayload)->assertStatus(200);
        // Event 2: expire duplikat identik
        $this->postJson(route('webhooks.midtrans'), $expirePayload)->assertStatus(200);
        // Event 3: cancel terlambat untuk order yang sama setelah sudah release
        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-release-twice-2',
            'transaction_status' => 'cancel',
            'status_code' => '200',
            'fraud_status' => null,
        ]))->assertStatus(200);
        // Pemanggilan langsung InventoryReservationService juga harus idempoten
        app(InventoryReservationService::class)->releaseForOrder($order, 'expired');

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertGreaterThanOrEqual(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame(
            1,
            StockMovement::where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('type', 'release')
                ->count()
        );
    }

    public function test_paid_payment_cannot_lose_reservation_from_late_failure_webhook_even_after_due_date(): void
    {
        $paidAt = now()->subHours(2);

        ['order' => $order, 'payment' => $paymentP1, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(
                stock: 10,
                qty: 2,
                orderOverrides: [
                    'order_status' => 'paid',
                    'payment_status' => 'paid',
                    'paid_at' => $paidAt,
                    'payment_due_at' => now()->subMinute(),
                ],
                paymentOverrides: [
                    'status' => 'pending',
                    'expired_at' => now()->subMinutes(10),
                ]
            );

        // Simulasikan P2 sudah sukses melunasi order & reservasi sudah ter-commit (serta kasus ekstrem jika masih active)
        $paymentP2 = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $order->order_number.'-P2-SETTLED',
            'snap_token' => 'snap-p2-settled',
            'gross_amount' => 175000,
            'status' => 'settlement',
            'midtrans_transaction_id' => 'trx-p2-settled',
            'paid_at' => $paidAt,
        ]);

        // Skenario A: reservasi sudah committed -> stok 8, reserved 0
        $product->update(['stock' => 8, 'reserved_stock' => 0]);
        $reservation->update(['status' => 'committed', 'committed_at' => $paidAt]);

        $lateExpireP1Payload = $this->buildSignedPayload([
            'order_id' => $paymentP1->midtrans_order_id,
            'transaction_id' => 'trx-late-p1-expire',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]);

        $this->postJson(route('webhooks.midtrans'), $lateExpireP1Payload)->assertStatus(200);

        $this->assertSame('expire', $paymentP1->fresh()->status);
        $this->assertSame('settlement', $paymentP2->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $order->fresh()->order_status);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('committed', $reservation->fresh()->status);
        $this->assertSame(
            0,
            StockMovement::where('reference_id', $order->id)->where('type', 'release')->count()
        );
    }

    public function test_commit_failure_rolls_back_entire_payment_order_and_inventory_transaction(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-commit-rollback',
            'transaction_status' => 'settlement',
        ]);

        StockMovement::creating(static function (): void {
            throw new QueryException(
                'mysql',
                'insert into stock_movements ...',
                [],
                new PDOException('SQLSTATE[HY000]: Commit stock write failed with '.self::TEST_SERVER_KEY)
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
            $this->assertSame('pending', $order->fresh()->payment_status);
            $this->assertSame('awaiting_payment', $order->fresh()->order_status);
            $this->assertNull($order->fresh()->paid_at);

            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame(0, OrderStatusHistory::count());

            // Stok dan reservasi wajib rollback penuh tanpa perubahan parsial
            $this->assertSame(10, (int) $product->fresh()->stock);
            $this->assertSame(2, (int) $product->fresh()->reserved_stock);
            $this->assertSame('active', $reservation->fresh()->status);
            $this->assertNull($reservation->fresh()->committed_at);
            $this->assertSame(
                0,
                StockMovement::where('reference_id', $order->id)->where('type', 'out')->count()
            );
        } finally {
            StockMovement::flushEventListeners();
        }

        // Retry identik setelah gangguan database pulih harus menyelesaikan sinkronisasi & commit
        $this->postJson(route('webhooks.midtrans'), $payload)->assertStatus(200);
        $this->assertTrue(PaymentWebhookLog::firstOrFail()->processed);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('committed', $reservation->fresh()->status);
    }

    public function test_release_failure_rolls_back_without_partial_stock_or_status_changes(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 3, orderOverrides: [
                'order_status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'payment_due_at' => now()->subMinutes(5),
            ]);

        $payload = $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-release-rollback',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'fraud_status' => null,
        ]);

        StockMovement::creating(static function (): void {
            throw new QueryException(
                'mysql',
                'insert into stock_movements ...',
                [],
                new PDOException('SQLSTATE[HY000]: Release stock write failed with '.self::TEST_SERVER_KEY)
            );
        });

        try {
            $response = $this->postJson(route('webhooks.midtrans'), $payload);

            $response->assertStatus(500)
                ->assertJson(['message' => 'Unable to process webhook notification']);

            $this->assertStringNotContainsString(self::TEST_SERVER_KEY, $response->getContent());

            $this->assertFalse(PaymentWebhookLog::firstOrFail()->processed);
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('pending', $order->fresh()->payment_status);
            $this->assertSame(0, PaymentTransaction::count());
            $this->assertSame(0, OrderStatusHistory::count());

            $this->assertSame(10, (int) $product->fresh()->stock);
            $this->assertSame(3, (int) $product->fresh()->reserved_stock);
            $this->assertSame('active', $reservation->fresh()->status);
            $this->assertNull($reservation->fresh()->released_at);
            $this->assertSame(
                0,
                StockMovement::where('reference_id', $order->id)->where('type', 'release')->count()
            );
        } finally {
            StockMovement::flushEventListeners();
        }
    }

    public function test_commit_and_release_handle_both_simple_and_variant_products_consistently(): void
    {
        $simpleProduct = $this->createSimpleProduct(stock: 10, reservedStock: 4);
        ['product' => $variantParent, 'variant' => $variant] = $this->createVariantProduct(variantStock: 8, variantReserved: 3);

        $customer = Customer::create([
            'name' => 'Pelanggan Mixed Item',
            'email' => 'mixed-7c@example.com',
            'phone' => '081234567891',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        // Order 1 (akan settlement -> commit 2 simple + 1 variant)
        $orderCommit = Order::create([
            'order_number' => 'PLN-20261122-MIXCOM',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 325000,
            'grand_total' => 325000,
            'payment_due_at' => now()->addHours(12),
        ]);

        $resCommitSimple = StockReservation::create([
            'order_id' => $orderCommit->id,
            'product_id' => $simpleProduct->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);
        $resCommitVariant = StockReservation::create([
            'order_id' => $orderCommit->id,
            'product_id' => $variantParent->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'status' => 'active',
            'expires_at' => now()->addHours(12),
        ]);
        $payCommit = Payment::create([
            'order_id' => $orderCommit->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $orderCommit->order_number.'-P1-MIX1',
            'snap_token' => 'snap-mix-1',
            'gross_amount' => 325000,
            'status' => 'pending',
            'expired_at' => now()->addHours(6),
        ]);

        // Order 2 (sudah lewat payment_due_at & akan expire -> release 2 simple + 2 variant)
        $orderRelease = Order::create([
            'order_number' => 'PLN-20261122-MIXREL',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 475000,
            'grand_total' => 475000,
            'payment_due_at' => now()->subMinutes(5),
        ]);

        $resReleaseSimple = StockReservation::create([
            'order_id' => $orderRelease->id,
            'product_id' => $simpleProduct->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5),
        ]);
        $resReleaseVariant = StockReservation::create([
            'order_id' => $orderRelease->id,
            'product_id' => $variantParent->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5),
        ]);
        $payRelease = Payment::create([
            'order_id' => $orderRelease->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $orderRelease->order_number.'-P1-MIX2',
            'snap_token' => 'snap-mix-2',
            'gross_amount' => 475000,
            'status' => 'pending',
            'expired_at' => now()->subMinutes(2),
        ]);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payCommit->midtrans_order_id,
            'transaction_id' => 'trx-mix-commit',
            'transaction_status' => 'settlement',
            'gross_amount' => '325000.00',
        ]))->assertStatus(200);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payRelease->midtrans_order_id,
            'transaction_id' => 'trx-mix-release',
            'transaction_status' => 'expire',
            'status_code' => '202',
            'gross_amount' => '475000.00',
            'fraud_status' => null,
        ]))->assertStatus(200);

        // Simple product: awal stock 10, reserved 4 -> -2 commit (-2 stock, -2 reserved), -2 release (-2 reserved) => stock 8, reserved 0
        $this->assertSame(8, (int) $simpleProduct->fresh()->stock);
        $this->assertSame(0, (int) $simpleProduct->fresh()->reserved_stock);

        // Variant product: awal variant stock 8, reserved 3 -> -1 commit, -2 release => stock 7, reserved 0; parent tetap 0/0
        $this->assertSame(7, (int) $variant->fresh()->stock);
        $this->assertSame(0, (int) $variant->fresh()->reserved_stock);
        $this->assertSame(0, (int) $variantParent->fresh()->stock);
        $this->assertSame(0, (int) $variantParent->fresh()->reserved_stock);

        $this->assertSame('committed', $resCommitSimple->fresh()->status);
        $this->assertSame('committed', $resCommitVariant->fresh()->status);
        $this->assertSame('expired', $resReleaseSimple->fresh()->status);
        $this->assertSame('expired', $resReleaseVariant->fresh()->status);

        $this->assertSame(2, StockMovement::where('reference_id', $orderCommit->id)->where('type', 'out')->count());
        $this->assertSame(2, StockMovement::where('reference_id', $orderRelease->id)->where('type', 'release')->count());
    }

    public function test_challenge_capture_followed_by_settlement_commits_once_and_preserves_consistency(): void
    {
        ['order' => $order, 'payment' => $payment, 'product' => $product, 'reservation' => $reservation]
            = $this->createOrderWithActiveReservation(stock: 10, qty: 2);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-chal-then-settle',
            'transaction_status' => 'capture',
            'payment_type' => 'credit_card',
            'fraud_status' => 'challenge',
            'va_numbers' => [],
        ]))->assertStatus(200);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
        $this->assertSame('active', $reservation->fresh()->status);

        $this->postJson(route('webhooks.midtrans'), $this->buildSignedPayload([
            'order_id' => $payment->midtrans_order_id,
            'transaction_id' => 'trx-chal-then-settle',
            'transaction_status' => 'settlement',
            'payment_type' => 'credit_card',
            'fraud_status' => 'accept',
            'va_numbers' => [],
        ]))->assertStatus(200);

        $this->assertSame('settlement', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $order->fresh()->order_status);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame('committed', $reservation->fresh()->status);
        $this->assertSame(
            1,
            StockMovement::where('reference_id', $order->id)->where('type', 'out')->count()
        );
    }
}
