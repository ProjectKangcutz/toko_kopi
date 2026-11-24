<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CheckoutDatabaseHardeningMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function loadHardeningMigration(): object
    {
        return require database_path('migrations/2026_11_20_000011_harden_checkout_orders_coupons_and_payments_tables.php');
    }

    protected function createOrder(array $overrides = []): Order
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Order::create(array_merge([
            'order_number' => 'ORD-MIG-'.$suffix,
            'customer_name_snapshot' => 'Budi Santoso',
            'customer_email_snapshot' => 'budi-'.$suffix.'@example.com',
            'customer_phone_snapshot' => '081234567890',
            'is_guest' => true,
            'subtotal' => 100000,
            'grand_total' => 100000,
        ], $overrides));
    }

    protected function createCoupon(array $overrides = []): Coupon
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Coupon::create(array_merge([
            'code' => 'MIG'.$suffix,
            'name' => 'Promo Migration '.$suffix,
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
            'midtrans_order_id' => 'MID-MIG-'.$suffix,
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

    public function test_preflight_rejects_missing_required_column_before_any_ddl_runs(): void
    {
        $migration = $this->loadHardeningMigration();
        $downCompleted = false;
        $columnDropped = false;

        try {
            $migration->down();
            $downCompleted = true;

            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('snap_redirect_url');
            });
            $columnDropped = true;

            $exceptionThrown = false;
            try {
                $migration->up();
            } catch (RuntimeException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('payments.snap_redirect_url', $e->getMessage());
            }

            $this->assertTrue($exceptionThrown, 'Pre-flight wajib melempar RuntimeException ketika payments.snap_redirect_url hilang.');
            $this->assertFalse(Schema::hasColumn('orders', 'checkout_idempotency_key'));
            $this->assertFalse(Schema::hasColumn('order_items', 'product_image_snapshot'));
            $this->assertFalse(Schema::hasColumn('order_items', 'regular_price_snapshot'));
            $this->assertFalse(Schema::hasColumn('order_items', 'is_promo_snapshot'));
            $this->assertFalse(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
            $this->assertNull($this->findSchemaIndex('orders', 'orders_checkout_idempotency_key_unique'));
            $this->assertNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));
        } finally {
            if ($columnDropped && ! Schema::hasColumn('payments', 'snap_redirect_url')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->string('snap_redirect_url')->nullable()->after('snap_token');
                });
            }

            if ($downCompleted) {
                $migration->up();
            }
        }
    }

    public function test_preflight_rejects_unrecognized_snap_redirect_url_column_type_before_any_ddl_runs(): void
    {
        $migration = $this->loadHardeningMigration();
        $downCompleted = false;
        $typeChanged = false;

        try {
            $migration->down();
            $downCompleted = true;

            Schema::table('payments', function (Blueprint $table) {
                $table->tinyText('snap_redirect_url')->nullable()->change();
            });
            $typeChanged = true;

            $exceptionThrown = false;
            try {
                $migration->up();
            } catch (RuntimeException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('snap_redirect_url', $e->getMessage());
            }

            $this->assertTrue($exceptionThrown, 'Pre-flight wajib menolak tipe kolom snap_redirect_url yang tidak dikenali sebelum DDL berjalan.');
            $this->assertFalse(Schema::hasColumn('orders', 'checkout_idempotency_key'));
            $this->assertFalse(Schema::hasColumn('order_items', 'product_image_snapshot'));
            $this->assertFalse(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
            $this->assertNull($this->findSchemaIndex('orders', 'orders_checkout_idempotency_key_unique'));
            $this->assertNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));
        } finally {
            if ($typeChanged) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->string('snap_redirect_url', 255)->nullable()->change();
                });
            }

            if ($downCompleted) {
                $migration->up();
            }
        }
    }

    public function test_preflight_rejects_duplicate_coupon_usages_and_duplicate_idempotency_keys(): void
    {
        $migration = $this->loadHardeningMigration();
        $downCompleted = false;
        $createdOrderIds = [];
        $createdCouponIds = [];

        try {
            $migration->down();
            $downCompleted = true;

            $order = $this->createOrder();
            $createdOrderIds[] = $order->id;
            $coupon = $this->createCoupon();
            $createdCouponIds[] = $coupon->id;

            CouponUsage::create([
                'coupon_id' => $coupon->id,
                'order_id' => $order->id,
                'discount_applied' => 5000,
            ]);
            CouponUsage::create([
                'coupon_id' => $coupon->id,
                'order_id' => $order->id,
                'discount_applied' => 5000,
            ]);

            $couponDupException = false;
            try {
                $migration->up();
            } catch (RuntimeException $e) {
                $couponDupException = true;
                $this->assertStringContainsString('coupon_usages', $e->getMessage());
            }

            $this->assertTrue($couponDupException, 'Pre-flight wajib menolak duplikat coupon_usages sebelum DDL.');
            $this->assertFalse(Schema::hasColumn('orders', 'checkout_idempotency_key'));
            $this->assertFalse(Schema::hasColumn('order_items', 'product_image_snapshot'));
            $this->assertFalse(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
            $this->assertNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));

            // Bersihkan duplikat coupon_usages, lalu uji pre-flight duplikat orders.checkout_idempotency_key
            CouponUsage::where('order_id', $order->id)->delete();

            Schema::table('orders', function (Blueprint $table) {
                $table->string('checkout_idempotency_key', 64)->nullable()->after('guest_token');
            });

            $dupOrderOne = $this->createOrder(['checkout_idempotency_key' => 'dup-pre-flight-key']);
            $dupOrderTwo = $this->createOrder(['checkout_idempotency_key' => 'dup-pre-flight-key']);
            $createdOrderIds[] = $dupOrderOne->id;
            $createdOrderIds[] = $dupOrderTwo->id;

            $orderDupException = false;
            try {
                $migration->up();
            } catch (RuntimeException $e) {
                $orderDupException = true;
                $this->assertStringContainsString('checkout_idempotency_key', $e->getMessage());
            }

            $this->assertTrue($orderDupException, 'Pre-flight wajib menolak duplikat non-null orders.checkout_idempotency_key sebelum DDL.');
            $this->assertFalse(Schema::hasColumn('order_items', 'product_image_snapshot'));
            $this->assertFalse(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
            $this->assertNull($this->findSchemaIndex('orders', 'orders_checkout_idempotency_key_unique'));
            $this->assertNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));
        } finally {
            if ($createdOrderIds !== []) {
                CouponUsage::whereIn('order_id', $createdOrderIds)->delete();
                Order::whereIn('id', $createdOrderIds)->delete();
            }
            if ($createdCouponIds !== []) {
                Coupon::whereIn('id', $createdCouponIds)->delete();
            }

            if ($downCompleted) {
                $migration->up();
            }
        }
    }

    public function test_rollback_protects_long_snap_redirect_url_exceeding_255_chars(): void
    {
        $migration = $this->loadHardeningMigration();
        $order = $this->createOrder();
        $longUrl = 'https://app.midtrans.com/snap/v4/redirection/'.str_repeat('a', 260);
        $payment = $this->createPayment($order, [
            'snap_redirect_url' => $longUrl,
        ]);

        try {
            $exceptionThrown = false;
            try {
                $migration->down();
            } catch (RuntimeException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('255', $e->getMessage());
            }

            $this->assertTrue($exceptionThrown, 'Rollback wajib gagal jika ada snap_redirect_url > 255 karakter.');
            $this->assertSame($longUrl, $payment->fresh()->snap_redirect_url);
            $this->assertTrue(Schema::hasColumn('orders', 'checkout_idempotency_key'));
            $this->assertNotNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));
            $this->assertSame('varchar(500)', strtolower((string) ($this->findSchemaColumn('payments', 'snap_redirect_url')['type'] ?? '')));
        } finally {
            Payment::where('id', $payment->id)->delete();
            Order::where('id', $order->id)->delete();
            $migration->up();
        }
    }

    public function test_rollback_does_not_shrink_varchar_larger_than_500_or_text(): void
    {
        $migration = $this->loadHardeningMigration();
        $typeChanged = false;

        try {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('snap_redirect_url', 600)->nullable()->change();
            });
            $typeChanged = true;

            $migration->down();
            $this->assertSame(
                'varchar(600)',
                strtolower((string) ($this->findSchemaColumn('payments', 'snap_redirect_url')['type'] ?? ''))
            );

            $migration->up();
            $this->assertSame(
                'varchar(600)',
                strtolower((string) ($this->findSchemaColumn('payments', 'snap_redirect_url')['type'] ?? ''))
            );

            Schema::table('payments', function (Blueprint $table) {
                $table->text('snap_redirect_url')->nullable()->change();
            });

            $migration->down();
            $this->assertSame(
                'text',
                strtolower((string) ($this->findSchemaColumn('payments', 'snap_redirect_url')['type_name'] ?? ''))
            );
        } finally {
            if ($typeChanged) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->string('snap_redirect_url', 255)->nullable()->change();
                });

                $migration->up();
            }
        }
    }

    public function test_rollback_preserves_fk_supporting_indexes_on_coupon_usages_and_supports_idempotent_reapply(): void
    {
        $migration = $this->loadHardeningMigration();

        try {
            $migration->down();

            $this->assertFalse(Schema::hasColumn('orders', 'checkout_idempotency_key'));
            $this->assertFalse(Schema::hasColumn('order_items', 'product_image_snapshot'));
            $this->assertFalse(Schema::hasColumn('order_items', 'regular_price_snapshot'));
            $this->assertFalse(Schema::hasColumn('order_items', 'is_promo_snapshot'));
            $this->assertFalse(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
            $this->assertNull($this->findSchemaIndex('orders', 'orders_checkout_idempotency_key_unique'));
            $this->assertNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));
            $this->assertSame('varchar(255)', strtolower((string) ($this->findSchemaColumn('payments', 'snap_redirect_url')['type'] ?? '')));

            $remainingIndexes = Schema::getIndexes('coupon_usages');
            $hasCouponIdSupport = false;
            $hasOrderIdSupport = false;

            foreach ($remainingIndexes as $index) {
                $firstCol = strtolower((string) ($index['columns'][0] ?? ''));
                if ($firstCol === 'coupon_id') {
                    $hasCouponIdSupport = true;
                }
                if ($firstCol === 'order_id') {
                    $hasOrderIdSupport = true;
                }
            }

            $this->assertTrue($hasCouponIdSupport, 'Index pendukung FK coupon_id pada coupon_usages wajib tetap ada setelah rollback.');
            $this->assertTrue($hasOrderIdSupport, 'Index pendukung FK order_id pada coupon_usages wajib tetap ada setelah rollback.');

            // Uji down() lalu up() berhasil serta pemanggilan up() dua kali tidak membuat duplikasi schema
            $migration->up();
            $indexCountAfterFirstUp = count(Schema::getIndexes('coupon_usages'));
            $orderIndexCountAfterFirstUp = count(Schema::getIndexes('orders'));

            $migration->up();

            $this->assertTrue(Schema::hasColumn('orders', 'checkout_idempotency_key'));
            $this->assertTrue(Schema::hasColumn('order_items', 'product_image_snapshot'));
            $this->assertTrue(Schema::hasColumn('order_items', 'regular_price_snapshot'));
            $this->assertTrue(Schema::hasColumn('order_items', 'is_promo_snapshot'));
            $this->assertTrue(Schema::hasColumn('coupons', 'stackable_with_product_promo'));
            $this->assertNotNull($this->findSchemaIndex('orders', 'orders_checkout_idempotency_key_unique'));
            $this->assertNotNull($this->findSchemaIndex('coupon_usages', 'coupon_usages_coupon_order_unique'));
            $this->assertSame('varchar(500)', strtolower((string) ($this->findSchemaColumn('payments', 'snap_redirect_url')['type'] ?? '')));
            $this->assertCount($indexCountAfterFirstUp, Schema::getIndexes('coupon_usages'));
            $this->assertCount($orderIndexCountAfterFirstUp, Schema::getIndexes('orders'));
        } finally {
            $migration->up();
        }
    }
}
