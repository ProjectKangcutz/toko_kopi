<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\OrderTrackingEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentHistory;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Order\OrderLifecycleService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class OrderLifecycleAndFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function createCustomer(string $suffix = ''): Customer
    {
        $id = $suffix !== '' ? $suffix : bin2hex(random_bytes(3));

        return Customer::create([
            'name' => 'Pelanggan '.$id,
            'email' => "cust-{$id}@example.com",
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);
    }

    protected function createAdminWithRole(string $roleSlug = 'super-admin'): User
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = User::create([
            'name' => 'Admin '.$suffix,
            'email' => "admin-{$roleSlug}-{$suffix}@example.com",
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array{order: Order, product: Product, reservation: ?StockReservation, shipment: Shipment}
     */
    protected function createOrderFixture(Customer $customer, array $orderOverrides = [], bool $withActiveReservation = true): array
    {
        $suffix = bin2hex(random_bytes(3));

        $product = Product::create([
            'sku' => 'SKU-LC-'.strtoupper($suffix),
            'name' => 'Produk Nusantara '.$suffix,
            'slug' => 'produk-nusantara-'.$suffix,
            'description' => 'Produk uji lifecycle.',
            'price' => 100000,
            'cost_price' => 60000,
            'stock' => 10,
            'reserved_stock' => $withActiveReservation ? 2 : 0,
            'weight_grams' => 500,
            'is_active' => true,
            'has_variants' => false,
        ]);

        $order = Order::create(array_merge([
            'order_number' => 'PLN-20261122-'.strtoupper($suffix),
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 200000,
            'shipping_cost' => 15000,
            'grand_total' => 215000,
            'total_weight_grams' => 1000,
            'payment_due_at' => now()->addHours(12),
        ], $orderOverrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 100000,
            'cost_price_snapshot' => 60000,
            'weight_grams_snapshot' => 500,
            'quantity' => 2,
            'line_total' => 200000,
        ]);

        OrderAddress::create([
            'order_id' => $order->id,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'address_line' => 'Jl. Merdeka No. 10',
            'province_id' => '9',
            'province_name' => 'Jawa Barat',
            'city_id' => '23',
            'city_name' => 'Kota Bandung',
            'district_id' => '345',
            'district_name' => 'Coblong',
            'subdistrict_id' => '34501',
            'subdistrict_name' => 'Dago',
            'postal_code' => '40135',
        ]);

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier_code' => 'jne',
            'courier_name' => 'JNE Express',
            'service_code' => 'REG',
            'service_name' => 'Layanan Reguler',
            'etd' => '2-3 hari',
            'shipping_cost' => 15000,
            'weight_grams' => 1000,
            'status' => $order->shipment_status === 'delivered' ? 'delivered' : ($order->shipment_status === 'shipped' ? 'shipped' : 'unfulfilled'),
        ]);

        $reservation = null;
        if ($withActiveReservation) {
            $reservation = StockReservation::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'status' => 'active',
                'expires_at' => $order->payment_due_at ?? now()->addHours(12),
            ]);
        }

        return compact('order', 'product', 'reservation', 'shipment');
    }

    public function test_customer_can_cancel_eligible_order_and_stock_release_is_idempotent(): void
    {
        $customer = $this->createCustomer('c1');
        ['order' => $order, 'product' => $product, 'reservation' => $reservation] = $this->createOrderFixture($customer, [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->post(route('customer.orders.cancel', $order), [
                'reason' => 'Ingin ubah pilihan kurir',
            ]);

        $response->assertRedirect(route('customer.orders.show', $order));
        $response->assertSessionHas('success');

        $freshOrder = $order->fresh();
        $this->assertSame('cancelled', $freshOrder->order_status);
        $this->assertSame('failed', $freshOrder->payment_status);
        $this->assertNotNull($freshOrder->cancelled_at);
        $this->assertSame('Ingin ubah pilihan kurir', $freshOrder->cancellation_reason);

        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame(1, StockMovement::where('reference_id', $order->id)->where('type', 'release')->count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
        $this->assertSame(0, Refund::count());

        // Pengulangan pembatalan tidak menggandakan pelepasan stok atau history
        $this->actingAs($customer, 'customer')
            ->post(route('customer.orders.cancel', $order))
            ->assertRedirect(route('customer.orders.show', $order));

        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame(1, StockMovement::where('reference_id', $order->id)->where('type', 'release')->count());
        $this->assertSame(2, OrderStatusHistory::where('order_id', $order->id)->count());
    }

    public function test_customer_cannot_cancel_paid_processing_shipped_or_completed_orders(): void
    {
        $customer = $this->createCustomer('c2');

        foreach (['paid', 'processing', 'shipped', 'delivered', 'completed'] as $status) {
            ['order' => $order] = $this->createOrderFixture($customer, [
                'order_status' => $status,
                'payment_status' => 'paid',
                'paid_at' => now()->subHour(),
            ], false);

            $response = $this->actingAs($customer, 'customer')
                ->from(route('customer.orders.show', $order))
                ->post(route('customer.orders.cancel', $order), ['reason' => 'Coba batal']);

            $response->assertRedirect(route('customer.orders.show', $order));
            $response->assertSessionHasErrors('error');
            $this->assertSame($status, $order->fresh()->order_status);
            $this->assertSame('paid', $order->fresh()->payment_status);
        }
    }

    public function test_customer_isolation_prevents_viewing_cancelling_or_completing_other_customer_orders(): void
    {
        $owner = $this->createCustomer('owner');
        $other = $this->createCustomer('other');
        ['order' => $order] = $this->createOrderFixture($owner, [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($other, 'customer')
            ->get(route('customer.orders.show', $order))
            ->assertForbidden();

        $this->actingAs($other, 'customer')
            ->post(route('customer.orders.cancel', $order))
            ->assertForbidden();

        $this->actingAs($other, 'customer')
            ->post(route('customer.orders.complete', $order))
            ->assertForbidden();

        $this->assertSame('awaiting_payment', $order->fresh()->order_status);
    }

    public function test_admin_cancellation_requires_permission_and_rejects_orders_with_settlement_payment(): void
    {
        $customer = $this->createCustomer('adm-can');
        $productManager = $this->createAdminWithRole('product-manager');
        $orderManager = $this->createAdminWithRole('order-manager');

        ['order' => $unpaidOrder, 'product' => $unpaidProduct] = $this->createOrderFixture($customer, [
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        // Product manager tidak punya permission orders.cancel
        $this->actingAs($productManager, 'admin')
            ->post(route('admin.orders.cancel', $unpaidOrder))
            ->assertForbidden();
        $this->assertSame('pending', $unpaidOrder->fresh()->order_status);

        // Order manager boleh membatalkan order belum bayar
        $this->actingAs($orderManager, 'admin')
            ->post(route('admin.orders.cancel', $unpaidOrder), ['reason' => 'Stok bermasalah'])
            ->assertRedirect(route('admin.orders.show', $unpaidOrder));
        $this->assertSame('cancelled', $unpaidOrder->fresh()->order_status);
        $this->assertSame(0, (int) $unpaidProduct->fresh()->reserved_stock);

        // Order dengan payment settlement tidak boleh dibatalkan lewat jalur cancel biasa
        ['order' => $settledOrder] = $this->createOrderFixture($customer, [
            'order_status' => 'awaiting_payment',
            'payment_status' => 'pending',
        ], false);

        Payment::create([
            'order_id' => $settledOrder->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => $settledOrder->order_number.'-P1',
            'snap_token' => 'snap-settled',
            'gross_amount' => 215000,
            'status' => 'settlement',
            'paid_at' => now(),
        ]);

        $this->actingAs($orderManager, 'admin')
            ->from(route('admin.orders.show', $settledOrder))
            ->post(route('admin.orders.cancel', $settledOrder), ['reason' => 'Paksa batal'])
            ->assertRedirect(route('admin.orders.show', $settledOrder))
            ->assertSessionHasErrors('error');

        $this->assertSame('awaiting_payment', $settledOrder->fresh()->order_status);
        $this->assertSame(0, Refund::count());
    }

    public function test_admin_cannot_process_unpaid_order(): void
    {
        $customer = $this->createCustomer('unpaid-proc');
        $admin = $this->createAdminWithRole('order-manager');
        ['order' => $order] = $this->createOrderFixture($customer, [
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.processing', $order))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('error');

        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_full_fulfillment_lifecycle_paid_processing_shipped_delivered_completed_and_tracking(): void
    {
        $customer = $this->createCustomer('full-flow');
        $admin = $this->createAdminWithRole('order-manager');
        ['order' => $order, 'shipment' => $shipment] = $this->createOrderFixture($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now()->subMinutes(30),
        ], false);

        // 1. paid -> processing
        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.processing', $order), [
                'internal_notes' => 'Siap dikemas oleh tim gudang',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame('processing', $order->fresh()->order_status);
        $this->assertSame('Siap dikemas oleh tim gudang', $order->fresh()->admin_internal_notes);

        // 2. processing -> shipped (simpan nomor resi & shipment history & tracking event)
        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.shipped', $order), [
                'tracking_number' => 'JNE-8899001122',
                'courier_name' => 'JNE Express',
                'service_name' => 'REG',
                'notes' => 'Diserahkan ke kurir sore',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $shipment->refresh();
        $this->assertSame('shipped', $order->order_status);
        $this->assertSame('shipped', $order->shipment_status);
        $this->assertSame('JNE-8899001122', $shipment->tracking_number);
        $this->assertSame('shipped', $shipment->status);
        $this->assertNotNull($shipment->shipped_at);
        $this->assertSame($admin->id, (int) $shipment->updated_by);

        $this->assertSame(1, ShipmentHistory::where('shipment_id', $shipment->id)->where('to_status', 'shipped')->count());
        $this->assertSame(1, OrderTrackingEvent::where('shipment_id', $shipment->id)->where('source', 'admin_manual')->count());

        // 2b. Tambah catatan tracking manual saat status shipped
        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.tracking.store', $order), [
                'location' => 'Hub Sortir Bandung',
                'description' => 'Paket sedang dalam perjalanan menuju kota tujuan.',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame(2, OrderTrackingEvent::where('shipment_id', $shipment->id)->count());

        // Customer detail view menampilkan resi & riwayat pengiriman
        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $order))
            ->assertOk()
            ->assertSee('JNE-8899001122')
            ->assertSee('Hub Sortir Bandung')
            ->assertSee('Paket sedang dalam perjalanan menuju kota tujuan.');

        // 3. shipped -> delivered
        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.delivered', $order), [
                'notes' => 'Diterima oleh pihak keluarga',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $shipment->refresh();
        $this->assertSame('delivered', $order->order_status);
        $this->assertSame('delivered', $order->shipment_status);
        $this->assertSame('delivered', $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
        $this->assertSame(1, ShipmentHistory::where('shipment_id', $shipment->id)->where('to_status', 'delivered')->count());

        // 4. delivered -> completed
        $this->actingAs($admin, 'admin')
            ->post(route('admin.orders.completed', $order), [
                'notes' => 'Transaksi selesai',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $order->refresh();
        $this->assertSame('completed', $order->order_status);
        $this->assertNotNull($order->completed_at);

        // Pastikan OrderStatusHistory tercatat untuk seluruh tahapan order
        $orderHistoryTargets = OrderStatusHistory::where('order_id', $order->id)
            ->where('status_type', 'order')
            ->pluck('to_status')
            ->all();
        $this->assertSame(['processing', 'shipped', 'delivered', 'completed'], $orderHistoryTargets);
    }

    public function test_illegal_transitions_and_repeated_actions_are_rejected(): void
    {
        $customer = $this->createCustomer('illegal');
        $admin = $this->createAdminWithRole('super-admin');
        /** @var OrderLifecycleService $service */
        $service = app(OrderLifecycleService::class);

        ['order' => $order] = $this->createOrderFixture($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ], false);

        // Tidak boleh lompat paid -> shipped
        $this->expectException(RuntimeException::class);
        try {
            $service->markShipped($order, $admin, 'RESI-SKIP');
        } finally {
            $this->assertSame('paid', $order->fresh()->order_status);
        }
    }

    public function test_repeated_fulfillment_actions_and_missing_tracking_number_are_rejected(): void
    {
        $customer = $this->createCustomer('repeat');
        $admin = $this->createAdminWithRole('super-admin');
        /** @var OrderLifecycleService $service */
        $service = app(OrderLifecycleService::class);

        ['order' => $order] = $this->createOrderFixture($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ], false);

        $service->markProcessing($order, $admin);
        $this->assertSame('processing', $order->fresh()->order_status);

        // Mengulang markProcessing harus ditolak
        $repeatProcessingFailed = false;
        try {
            $service->markProcessing($order->fresh(), $admin);
        } catch (RuntimeException) {
            $repeatProcessingFailed = true;
        }
        $this->assertTrue($repeatProcessingFailed);

        // Nomor resi kosong harus ditolak
        $emptyTrackingFailed = false;
        try {
            $service->markShipped($order->fresh(), $admin, '   ');
        } catch (InvalidArgumentException) {
            $emptyTrackingFailed = true;
        }
        $this->assertTrue($emptyTrackingFailed);

        // Tidak boleh lompat processing -> delivered atau processing -> completed
        $skipDeliveredFailed = false;
        try {
            $service->markDelivered($order->fresh(), $admin);
        } catch (RuntimeException) {
            $skipDeliveredFailed = true;
        }
        $this->assertTrue($skipDeliveredFailed);

        $skipCompletedFailed = false;
        try {
            $service->markCompleted($order->fresh(), $admin);
        } catch (RuntimeException) {
            $skipCompletedFailed = true;
        }
        $this->assertTrue($skipCompletedFailed);

        $service->markShipped($order->fresh(), $admin, 'RESI-OK-01');
        // Admin tidak boleh lompat shipped -> completed tanpa delivered
        $adminSkipToCompleted = false;
        try {
            $service->markCompleted($order->fresh(), $admin);
        } catch (RuntimeException) {
            $adminSkipToCompleted = true;
        }
        $this->assertTrue($adminSkipToCompleted);

        $service->markDelivered($order->fresh(), $admin);
        $service->markCompleted($order->fresh(), $admin);

        // Mengulang markCompleted harus ditolak
        $repeatCompletedFailed = false;
        try {
            $service->markCompleted($order->fresh(), $admin);
        } catch (RuntimeException) {
            $repeatCompletedFailed = true;
        }
        $this->assertTrue($repeatCompletedFailed);
    }

    public function test_admin_fulfillment_endpoints_enforce_role_permissions(): void
    {
        $customer = $this->createCustomer('rbac');
        $productManager = $this->createAdminWithRole('product-manager');
        ['order' => $order] = $this->createOrderFixture($customer, [
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ], false);

        $this->actingAs($productManager, 'admin')->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($productManager, 'admin')->get(route('admin.orders.show', $order))->assertForbidden();
        $this->actingAs($productManager, 'admin')->post(route('admin.orders.processing', $order))->assertForbidden();
        $this->actingAs($productManager, 'admin')->post(route('admin.orders.shipped', $order), ['tracking_number' => 'X1'])->assertForbidden();
        $this->actingAs($productManager, 'admin')->post(route('admin.orders.delivered', $order))->assertForbidden();
        $this->actingAs($productManager, 'admin')->post(route('admin.orders.completed', $order))->assertForbidden();

        /** @var OrderLifecycleService $service */
        $service = app(OrderLifecycleService::class);
        $this->expectException(AuthorizationException::class);
        $service->markProcessing($order, $productManager);
    }

    public function test_transaction_failure_during_shipped_or_cancellation_rolls_back_without_partial_changes(): void
    {
        $customer = $this->createCustomer('rollback');
        $admin = $this->createAdminWithRole('super-admin');
        /** @var OrderLifecycleService $service */
        $service = app(OrderLifecycleService::class);

        ['order' => $order, 'shipment' => $shipment] = $this->createOrderFixture($customer, [
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ], false);

        OrderStatusHistory::creating(static function (): void {
            throw new QueryException(
                'mysql',
                'insert into order_status_histories ...',
                [],
                new PDOException('Simulated DB failure')
            );
        });

        try {
            $threw = false;
            try {
                $service->markShipped($order, $admin, 'RESI-ROLLBACK');
            } catch (QueryException) {
                $threw = true;
            }

            $this->assertTrue($threw);
            // Shipment dan Order tidak boleh berubah sebagian
            $this->assertSame('processing', $order->fresh()->order_status);
            $this->assertSame('unfulfilled', $order->fresh()->shipment_status);
            $this->assertNull($shipment->fresh()->tracking_number);
            $this->assertSame('unfulfilled', $shipment->fresh()->status);
            $this->assertSame(0, ShipmentHistory::where('shipment_id', $shipment->id)->count());
            $this->assertSame(0, OrderTrackingEvent::where('shipment_id', $shipment->id)->count());
        } finally {
            OrderStatusHistory::flushEventListeners();
        }
    }

    public function test_admin_orders_index_and_detail_render_with_filters(): void
    {
        $customer = $this->createCustomer('admin-ui');
        $admin = $this->createAdminWithRole('order-manager');
        ['order' => $paidOrder] = $this->createOrderFixture($customer, [
            'order_number' => 'PLN-20261122-FILTER1',
            'order_status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ], false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.index', ['status' => 'paid', 'search' => 'FILTER1']))
            ->assertOk()
            ->assertSee('PLN-20261122-FILTER1');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.orders.show', $paidOrder))
            ->assertOk()
            ->assertSee('Proses Pesanan (Processing)');
    }
}
