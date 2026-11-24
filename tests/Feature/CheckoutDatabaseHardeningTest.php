<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutDatabaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function createOrder(array $overrides = []): Order
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Order::create(array_merge([
            'order_number' => 'ORD-HARDEN-'.$suffix,
            'customer_name_snapshot' => 'Budi Santoso',
            'customer_email_snapshot' => 'budi-'.$suffix.'@example.com',
            'customer_phone_snapshot' => '081234567890',
            'is_guest' => true,
            'subtotal' => 100000,
            'grand_total' => 100000,
        ], $overrides));
    }

    protected function createOrderItem(Order $order, array $overrides = []): OrderItem
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_name_snapshot' => 'Batik Tulis Pekalongan',
            'sku_snapshot' => 'SKU-'.$suffix,
            'unit_price_snapshot' => 50000,
            'quantity' => 2,
            'line_total' => 100000,
        ], $overrides));
    }

    protected function createCoupon(array $overrides = []): Coupon
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Coupon::create(array_merge([
            'code' => 'HEMAT'.$suffix,
            'name' => 'Promo Lokal '.$suffix,
            'discount_type' => 'fixed',
            'discount_value' => 10000,
            'min_purchase_amount' => 50000,
            'applicable_scope' => 'all',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ], $overrides));
    }

    protected function createPayment(Order $order, array $overrides = []): Payment
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => 'MID-'.$suffix,
            'gross_amount' => $order->grand_total,
            'status' => 'pending',
        ], $overrides));
    }

    protected function findSchemaColumn(string $table, string $columnName): ?array
    {
        foreach (Schema::getColumns($table) as $column) {
            if (strtolower((string) ($column['name'] ?? '')) === strtolower($columnName)) {
                return $column;
            }
        }

        return null;
    }

    protected function findSchemaIndex(string $table, string $indexName): ?array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strtolower((string) ($index['name'] ?? '')) === strtolower($indexName)) {
                return $index;
            }
        }

        return null;
    }

    public function test_hardening_columns_and_snap_redirect_url_length_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'checkout_idempotency_key'));
        $this->assertTrue(Schema::hasColumn('order_items', 'product_image_snapshot'));
        $this->assertTrue(Schema::hasColumn('order_items', 'regular_price_snapshot'));
        $this->assertTrue(Schema::hasColumn('order_items', 'is_promo_snapshot'));
        $this->assertTrue(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
        $this->assertTrue(Schema::hasColumn('payments', 'snap_redirect_url'));

        $checkoutKeyColumn = $this->findSchemaColumn('orders', 'checkout_idempotency_key');
        $imageSnapshotColumn = $this->findSchemaColumn('order_items', 'product_image_snapshot');
        $snapRedirectColumn = $this->findSchemaColumn('payments', 'snap_redirect_url');

        $this->assertNotNull($checkoutKeyColumn);
        $this->assertSame('varchar(64)', strtolower((string) ($checkoutKeyColumn['type'] ?? '')));
        $this->assertTrue((bool) ($checkoutKeyColumn['nullable'] ?? false));

        $this->assertNotNull($imageSnapshotColumn);
        $this->assertSame('varchar(500)', strtolower((string) ($imageSnapshotColumn['type'] ?? '')));
        $this->assertTrue((bool) ($imageSnapshotColumn['nullable'] ?? false));

        $this->assertNotNull($snapRedirectColumn);
        $this->assertSame('varchar(500)', strtolower((string) ($snapRedirectColumn['type'] ?? '')));
        $this->assertTrue((bool) ($snapRedirectColumn['nullable'] ?? false));
    }

    public function test_defaults_and_nullable_columns_behave_safely(): void
    {
        $order = $this->createOrder();
        $orderItem = $this->createOrderItem($order);
        $coupon = $this->createCoupon();

        $freshOrder = $order->fresh();
        $freshOrderItem = $orderItem->fresh();
        $freshCoupon = $coupon->fresh();

        $this->assertNull($freshOrder->checkout_idempotency_key);
        $this->assertNull($freshOrderItem->product_image_snapshot);
        $this->assertNull($freshOrderItem->regular_price_snapshot);
        $this->assertFalse($freshOrderItem->is_promo_snapshot);
        $this->assertFalse($freshCoupon->stackable_with_product_promo);

        $this->assertSame(0, (int) DB::table('order_items')->where('id', $orderItem->id)->value('is_promo_snapshot'));
        $this->assertSame(0, (int) DB::table('coupons')->where('id', $coupon->id)->value('stackable_with_product_promo'));
    }

    public function test_long_urls_up_to_450_chars_are_stored_without_truncation(): void
    {
        $order = $this->createOrder();

        $longImageUrl = 'https://cdn.pasarlokal.test/storage/products/'.str_repeat('gambar-batik-nusantara-', 17).'end.webp';
        $longSnapUrl = 'https://app.sandbox.midtrans.com/snap/v4/redirection/'.str_repeat('token-pembayaran-umkm-', 17).'checkout';

        $this->assertGreaterThanOrEqual(440, strlen($longImageUrl));
        $this->assertLessThanOrEqual(500, strlen($longImageUrl));
        $this->assertGreaterThanOrEqual(420, strlen($longSnapUrl));
        $this->assertLessThanOrEqual(500, strlen($longSnapUrl));

        // Pastikan tepat 450 karakter diuji tanpa pemotongan
        $exact450ImageUrl = str_pad($longImageUrl, 450, 'x');
        $exact450SnapUrl = str_pad($longSnapUrl, 450, 'y');

        $orderItem = $this->createOrderItem($order, [
            'product_image_snapshot' => substr($exact450ImageUrl, 0, 450),
        ]);
        $payment = $this->createPayment($order, [
            'snap_redirect_url' => substr($exact450SnapUrl, 0, 450),
        ]);

        $this->assertSame(450, strlen((string) $orderItem->fresh()->product_image_snapshot));
        $this->assertSame(substr($exact450ImageUrl, 0, 450), $orderItem->fresh()->product_image_snapshot);

        $this->assertSame(450, strlen((string) $payment->fresh()->snap_redirect_url));
        $this->assertSame(substr($exact450SnapUrl, 0, 450), $payment->fresh()->snap_redirect_url);
    }

    public function test_multiple_orders_with_null_idempotency_key_allowed_but_duplicate_non_null_rejected(): void
    {
        $firstNullOrder = $this->createOrder(['checkout_idempotency_key' => null]);
        $secondNullOrder = $this->createOrder(['checkout_idempotency_key' => null]);

        $this->assertNull($firstNullOrder->checkout_idempotency_key);
        $this->assertNull($secondNullOrder->checkout_idempotency_key);

        $this->createOrder(['checkout_idempotency_key' => 'idem-checkout-key-001']);

        $this->expectException(QueryException::class);
        $this->createOrder(['checkout_idempotency_key' => 'idem-checkout-key-001']);
    }

    public function test_coupon_usages_rejects_duplicate_coupon_and_order_pair_but_allows_distinct_combinations(): void
    {
        $orderOne = $this->createOrder();
        $orderTwo = $this->createOrder();
        $couponOne = $this->createCoupon();
        $couponTwo = $this->createCoupon();

        CouponUsage::create([
            'coupon_id' => $couponOne->id,
            'order_id' => $orderOne->id,
            'discount_applied' => 10000,
        ]);

        // Kupon berbeda pada order yang sama tetap diizinkan
        CouponUsage::create([
            'coupon_id' => $couponTwo->id,
            'order_id' => $orderOne->id,
            'discount_applied' => 5000,
        ]);

        // Kupon sama pada order berbeda tetap diizinkan
        CouponUsage::create([
            'coupon_id' => $couponOne->id,
            'order_id' => $orderTwo->id,
            'discount_applied' => 10000,
        ]);

        // Kombinasi coupon_id + order_id yang sama wajib ditolak DB
        $this->expectException(QueryException::class);
        CouponUsage::create([
            'coupon_id' => $couponOne->id,
            'order_id' => $orderOne->id,
            'discount_applied' => 10000,
        ]);
    }

    public function test_unique_index_definitions_match_expected_columns_and_order(): void
    {
        $ordersIdx = $this->findSchemaIndex('orders', 'orders_checkout_idempotency_key_unique');
        $couponUsagesIdx = $this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique');

        $this->assertNotNull($ordersIdx);
        $this->assertTrue((bool) ($ordersIdx['unique'] ?? false));
        $this->assertSame(
            ['checkout_idempotency_key'],
            array_values(array_map('strtolower', $ordersIdx['columns'] ?? []))
        );

        $this->assertNotNull($couponUsagesIdx);
        $this->assertTrue((bool) ($couponUsagesIdx['unique'] ?? false));
        $this->assertSame(
            ['coupon_id', 'order_id'],
            array_values(array_map('strtolower', $couponUsagesIdx['columns'] ?? []))
        );
    }

    public function test_legacy_create_calls_without_new_fields_and_model_casts_work_properly(): void
    {
        $legacyOrder = Order::create([
            'order_number' => 'ORD-LEGACY-'.uniqid(),
            'customer_name_snapshot' => 'Siti Aminah',
            'customer_email_snapshot' => 'siti-'.uniqid().'@example.com',
            'customer_phone_snapshot' => '081300001111',
            'subtotal' => 75000,
            'grand_total' => 75000,
        ]);

        $legacyItem = OrderItem::create([
            'order_id' => $legacyOrder->id,
            'product_name_snapshot' => 'Tenun Ikat Flores',
            'sku_snapshot' => 'TNN-001',
            'unit_price_snapshot' => 75000,
            'quantity' => 1,
            'line_total' => 75000,
        ]);

        $legacyCoupon = Coupon::create([
            'code' => 'LEGACY'.strtoupper(uniqid()),
            'name' => 'Kupon Lama',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertNull($legacyOrder->fresh()->checkout_idempotency_key);
        $this->assertFalse($legacyItem->fresh()->is_promo_snapshot);
        $this->assertFalse($legacyCoupon->fresh()->stackable_with_product_promo);

        // Uji model casts pada nilai eksplisit baru
        $promoItem = OrderItem::create([
            'order_id' => $legacyOrder->id,
            'product_name_snapshot' => 'Kopi Toraja Arabika',
            'product_image_snapshot' => 'products/kopi-toraja.webp',
            'sku_snapshot' => 'KPI-002',
            'regular_price_snapshot' => 95000.5,
            'unit_price_snapshot' => 80000,
            'is_promo_snapshot' => 1,
            'quantity' => 1,
            'line_total' => 80000,
        ])->fresh();

        $stackableCoupon = Coupon::create([
            'code' => 'STACK'.strtoupper(uniqid()),
            'name' => 'Kupon Stackable',
            'discount_type' => 'fixed',
            'discount_value' => 15000,
            'stackable_with_product_promo' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ])->fresh();

        $this->assertSame('95000.50', $promoItem->regular_price_snapshot);
        $this->assertIsBool($promoItem->is_promo_snapshot);
        $this->assertTrue($promoItem->is_promo_snapshot);
        $this->assertSame('products/kopi-toraja.webp', $promoItem->product_image_snapshot);

        $this->assertIsBool($stackableCoupon->stackable_with_product_promo);
        $this->assertTrue($stackableCoupon->stackable_with_product_promo);
    }
}
