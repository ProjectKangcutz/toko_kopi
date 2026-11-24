<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\ShipmentHistory;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Checkout\OrderCreationService;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\CartService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CustomerOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ShippingCourierSeeder::class);
    }

    protected function configureRajaOngkir(string $secretKey = 'order-test-secret-key-6d2'): void
    {
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('rajaongkir', 'is_active', '1', 'boolean', false);
        $settings->set('rajaongkir', 'base_url', 'https://rajaongkir.komerce.id/api/v1', 'string', false);
        $settings->set('rajaongkir', 'api_key', $secretKey, 'encrypted', false);
        $settings->set('rajaongkir', 'origin_id', '4879', 'string', false);
        $settings->set('rajaongkir', 'timeout', '10', 'integer', false);
    }

    protected function createCustomer(array $overrides = []): Customer
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Customer::create(array_merge([
            'name' => 'Pelanggan '.$suffix,
            'email' => 'order-cust-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ], $overrides));
    }

    protected function createAdmin(): User
    {
        $suffix = str_replace('.', '', uniqid('', true));

        $admin = User::create([
            'name' => 'Admin '.$suffix,
            'email' => 'order-admin-'.$suffix.'@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    protected function createAddress(Customer $customer, array $overrides = []): CustomerAddress
    {
        return $customer->addresses()->create(array_merge([
            'label' => 'Rumah Utama',
            'recipient_name' => 'Siti Rahmawati',
            'recipient_phone' => '081298765432',
            'address_line' => 'Jl. Asia Afrika No. 88',
            'province_id' => '9',
            'province_name' => 'Jawa Barat',
            'city_id' => '23',
            'city_name' => 'Kota Bandung',
            'district_id' => '345',
            'district_name' => 'Sumur Bandung',
            'subdistrict_id' => '73539',
            'subdistrict_name' => 'Braga',
            'postal_code' => '40111',
            'is_primary' => true,
        ], $overrides));
    }

    protected function createProduct(array $overrides = []): Product
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return Product::create(array_merge([
            'sku' => 'ORD-'.$suffix,
            'name' => 'Produk Order '.$suffix,
            'slug' => 'produk-order-'.$suffix,
            'description' => 'Produk UMKM berkualitas.',
            'cost_price' => 30000,
            'price' => 50000,
            'stock' => 15,
            'reserved_stock' => 0,
            'weight_grams' => 350,
            'has_variants' => false,
            'is_active' => true,
        ], $overrides));
    }

    protected function createVariant(Product $product, array $overrides = []): ProductVariant
    {
        $suffix = str_replace('.', '', uniqid('', true));

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'ORDV-'.$suffix,
            'name' => 'Varian '.$suffix,
            'cost_price' => 45000,
            'price' => 75000,
            'weight_grams' => 450,
            'stock' => 12,
            'reserved_stock' => 0,
            'is_active' => true,
        ], $overrides));
    }

    protected function fakeRajaOngkirRates(array $rates = null): void
    {
        $data = $rates ?? [
            [
                'name' => 'JNE Express',
                'code' => 'jne',
                'service' => 'REG',
                'description' => 'Layanan Reguler',
                'cost' => 19000,
                'etd' => '2-3 day',
            ],
            [
                'name' => 'J&T Express',
                'code' => 'jnt',
                'service' => 'EZ',
                'description' => 'Reguler',
                'cost' => 17000,
                'etd' => '',
            ],
        ];

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'],
                'data' => $data,
            ], 200),
        ]);
    }

    public function test_test_database_never_uses_tokoonline(): void
    {
        $this->assertNotSame('tokoonline', config('database.connections.mysql.database'));
        $this->assertSame('umkm_shop_test', config('database.connections.mysql.database'));
    }

    public function test_guest_admin_suspended_customer_and_cross_customer_access_are_blocked(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $addressB = $this->createAddress($customerB);
        $admin = $this->createAdmin();
        $suspended = $this->createCustomer(['status' => 'suspended']);

        $payload = [
            'address_id' => $addressB->id,
            'courier_code' => 'jne',
            'service_code' => 'REG',
            'idempotency_key' => '11111111-1111-4111-8111-111111111111',
        ];

        // 1. Guest
        $this->postJson(route('customer.checkout.store'), $payload)
            ->assertStatus(401);

        // 2. Admin only
        $this->actingAs($admin, 'admin')
            ->postJson(route('customer.checkout.store'), $payload)
            ->assertStatus(401);

        // 3. Suspended customer
        $this->actingAs($suspended, 'customer')
            ->postJson(route('customer.checkout.store'), $payload)
            ->assertStatus(403);
        $this->assertGuest('customer');

        // 4. Customer A memakai address milik Customer B -> 403
        $this->actingAs($customerA, 'customer')
            ->postJson(route('customer.checkout.store'), $payload)
            ->assertStatus(403);

        // 5. Soft-deleted address milik Customer A -> 422
        $deletedAddr = $this->createAddress($customerA, ['is_primary' => false]);
        $deletedAddr->delete();
        $this->actingAs($customerA, 'customer')
            ->postJson(route('customer.checkout.store'), array_merge($payload, ['address_id' => $deletedAddr->id]))
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, Order::count());
    }

    public function test_successful_order_creation_persists_full_snapshots_weights_taxes_reserves_stock_and_clears_cart(): void
    {
        $this->configureRajaOngkir();
        $this->fakeRajaOngkirRates();

        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('store', 'tax_percentage', '10', 'decimal', true);

        $customer = $this->createCustomer([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081111222333',
        ]);
        $address = $this->createAddress($customer, [
            'recipient_name' => 'Dewi Lestari',
            'recipient_phone' => '089999888777',
            'postal_code' => '40115',
        ]);

        // Produk 1: non-varian dengan promo aktif
        $simpleProduct = $this->createProduct([
            'sku' => 'KOPI-GAYO-01',
            'name' => 'Kopi Arabika Gayo 250g',
            'cost_price' => 28000,
            'price' => 60000,
            'promo_price' => 50000,
            'promo_start_at' => now()->subDay(),
            'promo_end_at' => now()->addDay(),
            'weight_grams' => 300,
            'stock' => 10,
            'reserved_stock' => 0,
            'has_variants' => false,
        ]);
        $simpleProduct->images()->create([
            'image_path' => 'products/kopi-gayo.jpg',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        // Produk 2: bervarian (stok parent = 0, stok varian = 8)
        $parentProduct = $this->createProduct([
            'sku' => 'BATIK-PARENT',
            'name' => 'Kemeja Batik Tulis',
            'cost_price' => 80000,
            'price' => 150000,
            'stock' => 0,
            'reserved_stock' => 0,
            'weight_grams' => 200,
            'has_variants' => true,
        ]);
        $variant = $this->createVariant($parentProduct, [
            'sku' => 'BATIK-L-MERAH',
            'name' => 'Ukuran L - Merah Marun',
            'cost_price' => 95000,
            'price' => 175000,
            'weight_grams' => 350,
            'stock' => 8,
            'reserved_stock' => 0,
            'image_path' => 'products/batik-merah.jpg',
        ]);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($simpleProduct->id, null, 2, $customer); // 2 * 50.000 = 100.000, 600g
        $cartService->addItem($parentProduct->id, $variant->id, 1, $customer); // 1 * 175.000 = 175.000, 350g
        // Total actual weight = 950g -> billable = 1000g
        // Subtotal = 275.000, tax 10% = 27.500, shipping J&T EZ = 17.000, grand_total = 319.500

        $idempotencyKey = '22222222-2222-4222-8222-222222222222';

        // Kirim juga field manipulatif untuk memastikan server mengabaikannya
        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jnt',
                'service_code' => 'EZ',
                'idempotency_key' => $idempotencyKey,
                'customer_notes' => 'Mohon dibungkus bubble wrap',
                'subtotal' => 1,
                'grand_total' => 1,
                'shipping_cost' => 0,
                'discount_amount' => 274999,
                'tax_amount' => 0,
                'payment_fee' => 999999,
                'total_weight_grams' => 1,
                'customer_id' => 999999,
                'order_status' => 'paid',
                'payment_status' => 'paid',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_status', 'pending')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.shipment_status', 'unfulfilled')
            ->assertJsonPath('data.grand_total', 319500)
            ->assertJsonPath('data.total_weight_grams', 950)
            ->assertJsonPath('data.idempotent_replay', false);

        /** @var Order $order */
        $order = Order::with(['items', 'address', 'shipment', 'statusHistories', 'stockReservations'])->firstOrFail();

        $this->assertSame(
            OrderCreationService::scopeIdempotencyKey($customer->id, $idempotencyKey),
            $order->checkout_idempotency_key
        );
        $this->assertStringStartsWith('PLN-', $order->order_number);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Budi Santoso', $order->customer_name_snapshot);
        $this->assertSame('budi@example.com', $order->customer_email_snapshot);
        $this->assertSame('081111222333', $order->customer_phone_snapshot);
        $this->assertFalse($order->is_guest);
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('unfulfilled', $order->shipment_status);
        $this->assertEquals(275000.00, (float) $order->subtotal);
        $this->assertEquals(0.00, (float) $order->discount_amount);
        $this->assertEquals(27500.00, (float) $order->tax_amount);
        $this->assertEquals(17000.00, (float) $order->shipping_cost);
        $this->assertEquals(0.00, (float) $order->payment_fee);
        $this->assertEquals(319500.00, (float) $order->grand_total);
        $this->assertSame(950, (int) $order->total_weight_grams);
        $this->assertSame('Mohon dibungkus bubble wrap', $order->customer_notes);
        $this->assertNotNull($order->payment_due_at);

        // Snapshot order_items
        $this->assertCount(2, $order->items);
        $simpleItem = $order->items->firstWhere('product_id', $simpleProduct->id);
        $this->assertSame('Kopi Arabika Gayo 250g', $simpleItem->product_name_snapshot);
        $this->assertNull($simpleItem->variant_name_snapshot);
        $this->assertSame('products/kopi-gayo.jpg', $simpleItem->product_image_snapshot);
        $this->assertSame('KOPI-GAYO-01', $simpleItem->sku_snapshot);
        $this->assertEquals(60000.00, (float) $simpleItem->regular_price_snapshot);
        $this->assertEquals(50000.00, (float) $simpleItem->unit_price_snapshot);
        $this->assertTrue($simpleItem->is_promo_snapshot);
        $this->assertEquals(28000.00, (float) $simpleItem->cost_price_snapshot);
        $this->assertSame(300, (int) $simpleItem->weight_grams_snapshot);
        $this->assertSame(2, (int) $simpleItem->quantity);
        $this->assertEquals(100000.00, (float) $simpleItem->line_total);

        $variantItem = $order->items->firstWhere('product_variant_id', $variant->id);
        $this->assertSame('Kemeja Batik Tulis', $variantItem->product_name_snapshot);
        $this->assertSame('Ukuran L - Merah Marun', $variantItem->variant_name_snapshot);
        $this->assertSame('products/batik-merah.jpg', $variantItem->product_image_snapshot);
        $this->assertSame('BATIK-L-MERAH', $variantItem->sku_snapshot);
        $this->assertEquals(175000.00, (float) $variantItem->regular_price_snapshot);
        $this->assertEquals(175000.00, (float) $variantItem->unit_price_snapshot);
        $this->assertFalse($variantItem->is_promo_snapshot);
        $this->assertEquals(95000.00, (float) $variantItem->cost_price_snapshot);
        $this->assertSame(350, (int) $variantItem->weight_grams_snapshot);
        $this->assertSame(1, (int) $variantItem->quantity);
        $this->assertEquals(175000.00, (float) $variantItem->line_total);

        // Snapshot alamat
        $this->assertSame('Dewi Lestari', $order->address->recipient_name);
        $this->assertSame('089999888777', $order->address->recipient_phone);
        $this->assertSame('73539', $order->address->subdistrict_id);
        $this->assertSame('40115', $order->address->postal_code);

        // Snapshot shipment: weight_grams = billable weight (1000g)
        $this->assertSame('jnt', $order->shipment->courier_code);
        $this->assertSame('EZ', $order->shipment->service_code);
        $this->assertEquals(17000.00, (float) $order->shipment->shipping_cost);
        $this->assertSame(1000, (int) $order->shipment->weight_grams);
        $this->assertSame('unfulfilled', $order->shipment->status);
        $this->assertSame(1, ShipmentHistory::where('shipment_id', $order->shipment->id)->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $order->id)->count());

        // Verifikasi reservasi stok (stok fisik tidak berubah, hanya reserved_stock)
        $simpleProduct->refresh();
        $parentProduct->refresh();
        $variant->refresh();

        $this->assertSame(10, (int) $simpleProduct->stock);
        $this->assertSame(2, (int) $simpleProduct->reserved_stock);
        $this->assertSame(8, $simpleProduct->availableStock());

        $this->assertSame(0, (int) $parentProduct->stock);
        $this->assertSame(0, (int) $parentProduct->reserved_stock);

        $this->assertSame(8, (int) $variant->stock);
        $this->assertSame(1, (int) $variant->reserved_stock);
        $this->assertSame(7, $variant->availableStock());

        $this->assertCount(2, $order->stockReservations);
        foreach ($order->stockReservations as $reservation) {
            $this->assertSame('active', $reservation->status);
            $this->assertTrue($reservation->expires_at->equalTo($order->payment_due_at));
        }

        // Verifikasi stock movements (type reservation, available stock turun, created_by null)
        $movements = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $movements);

        $simpleMove = $movements->firstWhere('product_id', $simpleProduct->id);
        $this->assertSame('reservation', $simpleMove->type);
        $this->assertSame(-2, (int) $simpleMove->quantity);
        $this->assertSame(10, (int) $simpleMove->stock_before);
        $this->assertSame(8, (int) $simpleMove->stock_after);
        $this->assertNull($simpleMove->created_by);

        $variantMove = $movements->firstWhere('product_variant_id', $variant->id);
        $this->assertSame('reservation', $variantMove->type);
        $this->assertSame(-1, (int) $variantMove->quantity);
        $this->assertSame(8, (int) $variantMove->stock_before);
        $this->assertSame(7, (int) $variantMove->stock_after);
        $this->assertNull($variantMove->created_by);

        // Cart dikosongkan & audit log tercatat
        $this->assertSame(0, CartItem::count());
        $this->assertSame(0, $cartService->getHeaderItemCount($customer));
        $this->assertTrue(ActivityLog::where('action', 'order.created')->where('subject_id', $order->id)->exists());

        // Customer dapat membuka halaman detail order; customer lain ditolak 403
        $this->actingAs($customer, 'customer')
            ->get(route('customer.orders.show', $order))
            ->assertStatus(200)
            ->assertSee($order->order_number)
            ->assertSee('Kopi Arabika Gayo 250g')
            ->assertSee('Rp 319.500');

        $otherCustomer = $this->createCustomer();
        $this->actingAs($otherCustomer, 'customer')
            ->get(route('customer.orders.show', $order))
            ->assertStatus(403);
    }

    public function test_idempotent_replay_returns_existing_order_without_duplicate_order_or_reservation(): void
    {
        $this->configureRajaOngkir();
        $this->fakeRajaOngkirRates();

        $customer = $this->createCustomer();
        $addressPrimary = $this->createAddress($customer, ['label' => 'Rumah', 'is_primary' => true]);
        $addressSecond = $this->createAddress($customer, ['label' => 'Kantor', 'subdistrict_id' => '73540', 'is_primary' => false]);
        $product = $this->createProduct(['price' => 80000, 'stock' => 10, 'reserved_stock' => 0]);

        app(CartService::class)->addItem($product->id, null, 2, $customer);

        $key = '33333333-3333-4333-8333-333333333333';
        $payload = [
            'address_id' => $addressPrimary->id,
            'courier_code' => 'jne',
            'service_code' => 'REG',
            'idempotency_key' => $key,
            'customer_notes' => 'Antar sore hari',
        ];

        $first = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), $payload);
        $first->assertStatus(201)
            ->assertJsonPath('data.idempotent_replay', false);

        $orderId = $first->json('data.order_id');
        $orderNumber = $first->json('data.order_number');

        // Replay dengan payload identik (keranjang sudah kosong) -> 200 tanpa memanggil RajaOngkir ulang
        $replay = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), $payload);
        $replay->assertStatus(200)
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.order_id', $orderId)
            ->assertJsonPath('data.order_number', $orderNumber);

        Http::assertSentCount(1);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, StockReservation::count());
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);

        // Reuse key yang sama dengan alamat berbeda -> 409
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), array_merge($payload, [
                'address_id' => $addressSecond->id,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_conflict');

        // Reuse key yang sama dengan kurir/service berbeda -> 409
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), array_merge($payload, [
                'courier_code' => 'jnt',
                'service_code' => 'EZ',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_conflict');

        // Reuse key yang sama dengan catatan berbeda -> 409
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), array_merge($payload, [
                'customer_notes' => 'Berubah catatan',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_conflict');

        // Reuse key setelah keranjang diisi kembali dengan komposisi item berbeda -> 409
        $otherProduct = $this->createProduct(['price' => 40000, 'stock' => 5]);
        app(CartService::class)->addItem($otherProduct->id, null, 1, $customer);

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), $payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_conflict');

        $this->assertSame(1, Order::count());
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
    }

    public function test_same_raw_idempotency_key_from_two_different_customers_stays_isolated(): void
    {
        $this->configureRajaOngkir();
        $this->fakeRajaOngkirRates();

        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $addressA = $this->createAddress($customerA);
        $addressB = $this->createAddress($customerB);
        $product = $this->createProduct(['stock' => 10, 'reserved_stock' => 0]);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($product->id, null, 2, $customerA);
        $cartService->addItem($product->id, null, 3, $customerB);

        $sharedRawKey = '44444444-4444-4444-8444-444444444444';

        $resA = $this->actingAs($customerA, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $addressA->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => $sharedRawKey,
            ]);
        $resB = $this->actingAs($customerB, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $addressB->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => $sharedRawKey,
            ]);

        $resA->assertStatus(201);
        $resB->assertStatus(201);
        $this->assertNotSame($resA->json('data.order_id'), $resB->json('data.order_id'));
        $this->assertSame(2, Order::count());
        $this->assertSame(5, (int) $product->fresh()->reserved_stock);
    }

    public function test_stock_reservation_prevents_overselling_and_release_is_idempotent(): void
    {
        $this->configureRajaOngkir();
        $this->fakeRajaOngkirRates();

        $customer1 = $this->createCustomer();
        $customer2 = $this->createCustomer();
        $address1 = $this->createAddress($customer1);
        $address2 = $this->createAddress($customer2);

        $parent = $this->createProduct(['has_variants' => true, 'stock' => 0]);
        $variant = $this->createVariant($parent, ['stock' => 2, 'reserved_stock' => 0]);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($parent->id, $variant->id, 2, $customer1);
        $cartService->addItem($parent->id, $variant->id, 1, $customer2);

        // Order 1 sukses mereservasi seluruh 2 unit tersedia
        $res1 = $this->actingAs($customer1, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address1->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => '55555555-5555-4555-8555-555555555551',
            ]);
        $res1->assertStatus(201);

        $this->assertSame(2, (int) $variant->fresh()->stock);
        $this->assertSame(2, (int) $variant->fresh()->reserved_stock);
        $this->assertSame(0, $variant->fresh()->availableStock());

        // Order 2 gagal aman karena sisa available stock = 0 (tidak boleh oversell atau jadi negatif)
        $res2 = $this->actingAs($customer2, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address2->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => '55555555-5555-4555-8555-555555555552',
            ]);
        $res2->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, Order::count());
        $this->assertSame(2, (int) $variant->fresh()->reserved_stock);
        $this->assertSame(0, (int) $parent->fresh()->reserved_stock);

        // Release reservasi Order 1 -> reserved_stock kembali 0 & stock_movement release positif tercatat
        /** @var Order $order1 */
        $order1 = Order::firstOrFail();
        /** @var InventoryReservationService $inventoryService */
        $inventoryService = app(InventoryReservationService::class);

        $releasedCount = $inventoryService->releaseForOrder($order1, 'released', 'Dibatalkan dalam pengujian');
        $this->assertSame(1, $releasedCount);

        $variant->refresh();
        $this->assertSame(2, (int) $variant->stock);
        $this->assertSame(0, (int) $variant->reserved_stock);
        $this->assertSame(2, $variant->availableStock());

        $reservation = StockReservation::where('order_id', $order1->id)->firstOrFail();
        $this->assertSame('released', $reservation->status);
        $this->assertNotNull($reservation->released_at);

        $releaseMovement = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $order1->id)
            ->where('type', 'release')
            ->firstOrFail();
        $this->assertSame(2, (int) $releaseMovement->quantity);
        $this->assertSame(0, (int) $releaseMovement->stock_before);
        $this->assertSame(2, (int) $releaseMovement->stock_after);
        $this->assertNull($releaseMovement->created_by);

        // Pemanggilan release kedua kalinya bersifat idempoten
        $secondRelease = $inventoryService->releaseForOrder($order1, 'released');
        $this->assertSame(0, $secondRelease);
        $this->assertSame(0, (int) $variant->fresh()->reserved_stock);
        $this->assertSame(
            1,
            StockMovement::where('reference_type', Order::class)
                ->where('reference_id', $order1->id)
                ->where('type', 'release')
                ->count()
        );
    }

    public function test_release_expired_reservations_only_releases_past_due_active_reservations(): void
    {
        $this->configureRajaOngkir();
        $this->fakeRajaOngkirRates();

        $customer1 = $this->createCustomer();
        $customer2 = $this->createCustomer();
        $address1 = $this->createAddress($customer1);
        $address2 = $this->createAddress($customer2);
        $product = $this->createProduct(['stock' => 10, 'reserved_stock' => 0]);

        app(CartService::class)->addItem($product->id, null, 3, $customer1);
        app(CartService::class)->addItem($product->id, null, 2, $customer2);

        $res1 = $this->actingAs($customer1, 'customer')->postJson(route('customer.checkout.store'), [
            'address_id' => $address1->id,
            'courier_code' => 'jne',
            'service_code' => 'REG',
            'idempotency_key' => '66666666-6666-4666-8666-666666666661',
        ]);
        $res2 = $this->actingAs($customer2, 'customer')->postJson(route('customer.checkout.store'), [
            'address_id' => $address2->id,
            'courier_code' => 'jne',
            'service_code' => 'REG',
            'idempotency_key' => '66666666-6666-4666-8666-666666666662',
        ]);
        $res1->assertStatus(201);
        $res2->assertStatus(201);

        // Paksa reservasi order 1 menjadi kedaluwarsa, sementara order 2 masih aktif di masa depan
        StockReservation::where('order_id', $res1->json('data.order_id'))
            ->update(['expires_at' => now()->subMinute()]);

        $expiredCount = app(InventoryReservationService::class)->releaseExpiredReservations();
        $this->assertSame(1, $expiredCount);

        $this->assertSame('expired', StockReservation::where('order_id', $res1->json('data.order_id'))->value('status'));
        $this->assertSame('active', StockReservation::where('order_id', $res2->json('data.order_id'))->value('status'));
        $this->assertSame(2, (int) $product->fresh()->reserved_stock);
    }

    public function test_quote_mismatch_during_locked_validation_rolls_back_with_http_409(): void
    {
        $this->configureRajaOngkir();

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, ['subdistrict_id' => '73539']);
        $product = $this->createProduct(['price' => 50000, 'weight_grams' => 500, 'stock' => 10]);
        app(CartService::class)->addItem($product->id, null, 2, $customer); // actual = 1000g, billable = 1000g

        // Simulasikan quote menghasilkan billable_weight_grams yang berbeda (2000g) dengan isi keranjang terkunci (1000g)
        $mockShipping = Mockery::mock(ShippingRateService::class);
        $mockShipping->shouldReceive('resolveSelectedRate')
            ->once()
            ->andReturn([
                'courier_code' => 'jne',
                'courier_name' => 'JNE Express',
                'service_code' => 'REG',
                'service_name' => 'Layanan Reguler',
                'description' => 'Reguler',
                'cost' => 25000,
                'etd' => '2-3 day',
                'origin_id' => '4879',
                'destination_id' => '73539',
                'actual_weight_grams' => 1200,
                'billable_weight_grams' => 2000,
                'from_cache' => false,
            ]);
        $mockShipping->shouldReceive('calculateBillableWeightGrams')
            ->once()
            ->with(1000)
            ->andReturn(1000);

        $this->app->instance(ShippingRateService::class, $mockShipping);
        $this->app->forgetInstance(OrderCreationService::class);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => '77777777-7777-4777-8777-777777777777',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'checkout_quote_mismatch');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockReservation::count());
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame(1, CartItem::count());
    }

    public function test_business_validations_and_upstream_errors_fail_cleanly_without_side_effects(): void
    {
        $this->configureRajaOngkir();

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $product = $this->createProduct(['price' => 30000, 'stock' => 5]);

        // 1. Cart kosong -> 422
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => '88888888-8888-4888-8888-888888888881',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'empty_cart');

        app(CartService::class)->addItem($product->id, null, 1, $customer); // Subtotal 30.000

        // 2. Di bawah min_order_amount -> 422
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('store', 'min_order_amount', '100000', 'decimal', true);
        $this->fakeRajaOngkirRates();

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => '88888888-8888-4888-8888-888888888882',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'below_minimum_order');

        // 3. Toko tutup -> 422 tanpa memanggil RajaOngkir
        $settings->set('store', 'min_order_amount', '0', 'decimal', true);
        $settings->set('store', 'store_status', 'closed', 'string', true);

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jne',
                'service_code' => 'REG',
                'idempotency_key' => '88888888-8888-4888-8888-888888888883',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'store_closed');

        // 4. Layanan kurir tidak ditemukan di hasil quote -> 422
        $settings->set('store', 'store_status', 'open', 'string', true);

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.checkout.store'), [
                'address_id' => $address->id,
                'courier_code' => 'jne',
                'service_code' => 'LAYANAN_PALSU',
                'idempotency_key' => '88888888-8888-4888-8888-888888888884',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'shipping_rate_not_found');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, (int) $product->fresh()->reserved_stock);
        $this->assertSame(1, CartItem::count());
    }

    public function test_checkout_page_exposes_store_url_and_submit_contract_safely(): void
    {
        $customer = $this->createCustomer();
        $this->createAddress($customer);
        $product = $this->createProduct(['price' => 45000, 'stock' => 4]);
        app(CartService::class)->addItem($product->id, null, 1, $customer);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index'));

        $response->assertStatus(200)
            ->assertSee('Buat Pesanan')
            ->assertSee('submitOrder()')
            ->assertSee('idempotencyKey')
            ->assertSee('Catatan Pesanan (Opsional)');

        $checkoutConfig = $response->viewData('checkoutConfig');
        $this->assertSame(route('customer.checkout.store'), $checkoutConfig['storeUrl']);

        $html = $response->getContent();
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);
        $this->assertStringNotContainsString('snap.js', $html);
    }
}
