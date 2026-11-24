<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReleaseExpiredReservationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_expired_reservations_command_releases_expired_orders_is_idempotent_and_protects_paid_and_active_attempts(): void
    {
        $customer = Customer::create([
            'name' => 'Pelanggan Scheduler',
            'email' => 'sched@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Kopi Gayo Scheduler',
            'slug' => 'kopi-gayo-scheduler',
            'sku' => 'SCHED-001',
            'description' => 'Kopi uji scheduler',
            'price' => 50000,
            'cost_price' => 30000,
            'stock' => 20,
            'reserved_stock' => 8,
            'weight_grams' => 250,
            'is_active' => true,
            'has_variants' => false,
        ]);

        // Order 1: Kedaluwarsa (payment_due_at di masa lalu, tidak ada pembayaran aktif)
        $orderExpired = Order::create([
            'order_number' => 'PLN-20261122-SCHED1',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'payment_due_at' => now()->subMinutes(15),
        ]);

        $resExpired = StockReservation::create([
            'order_id' => $orderExpired->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->subMinutes(15),
        ]);

        // Order 2: Masih segar (payment_due_at di masa depan)
        $orderFresh = Order::create([
            'order_number' => 'PLN-20261122-SCHED2',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'payment_due_at' => now()->addHours(2),
        ]);

        $resFresh = StockReservation::create([
            'order_id' => $orderFresh->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addHours(2),
        ]);

        // Order 3: Sudah memiliki pembayaran settlement (terlindungi meskipun tanggal lewat)
        $orderSettled = Order::create([
            'order_number' => 'PLN-20261122-SCHED3',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'payment_due_at' => now()->subMinutes(5),
        ]);

        $resSettled = StockReservation::create([
            'order_id' => $orderSettled->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->subMinutes(5),
        ]);

        Payment::create([
            'order_id' => $orderSettled->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => 'PLN-20261122-SCHED3-P1',
            'snap_token' => 'snap-settled-protect',
            'gross_amount' => 50000,
            'status' => 'settlement',
            'paid_at' => now()->subMinutes(10),
        ]);

        // Order 4: Masih memiliki percobaan pembayaran pending yang aktif di masa depan
        $orderActiveAttempt = Order::create([
            'order_number' => 'PLN-20261122-SCHED4',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'payment_due_at' => now()->subMinute(),
        ]);

        $resActiveAttempt = StockReservation::create([
            'order_id' => $orderActiveAttempt->id,
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        Payment::create([
            'order_id' => $orderActiveAttempt->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => 'PLN-20261122-SCHED4-P1',
            'snap_token' => 'snap-active-attempt',
            'gross_amount' => 50000,
            'status' => 'pending',
            'expired_at' => now()->addMinutes(30),
        ]);

        // Eksekusi pertama
        $exitCode = Artisan::call('inventory:release-expired', ['--limit' => 50]);
        $this->assertSame(0, $exitCode);

        // Order 1 expired: stok dilepas & status order/payment diperbarui
        $this->assertSame('expired', $resExpired->fresh()->status);
        $this->assertNotNull($resExpired->fresh()->released_at);
        $this->assertSame('cancelled', $orderExpired->fresh()->order_status);
        $this->assertSame('expired', $orderExpired->fresh()->payment_status);
        $this->assertNotNull($orderExpired->fresh()->cancelled_at);
        $this->assertSame(2, OrderStatusHistory::where('order_id', $orderExpired->id)->count());

        // Order 2, 3, 4 terlindungi
        $this->assertSame('active', $resFresh->fresh()->status);
        $this->assertSame('awaiting_payment', $orderFresh->fresh()->order_status);

        $this->assertSame('active', $resSettled->fresh()->status);
        $this->assertSame('awaiting_payment', $orderSettled->fresh()->order_status);

        $this->assertSame('active', $resActiveAttempt->fresh()->status);
        $this->assertSame('awaiting_payment', $orderActiveAttempt->fresh()->order_status);

        // Produk: awal stock 20, reserved 8 -> hanya orderExpired (2) yang dilepas -> reserved = 6
        $this->assertSame(20, (int) $product->fresh()->stock);
        $this->assertSame(6, (int) $product->fresh()->reserved_stock);

        $this->assertSame(
            1,
            StockMovement::where('reference_id', $orderExpired->id)->where('type', 'release')->count()
        );

        // Eksekusi kedua harus idempoten (tidak menggandakan pelepasan atau history)
        $exitCodeSecond = Artisan::call('inventory:release-expired', ['--limit' => 50]);
        $this->assertSame(0, $exitCodeSecond);
        $this->assertSame(6, (int) $product->fresh()->reserved_stock);
        $this->assertSame(
            1,
            StockMovement::where('reference_id', $orderExpired->id)->where('type', 'release')->count()
        );
        $this->assertSame(2, OrderStatusHistory::where('order_id', $orderExpired->id)->count());
    }
}
