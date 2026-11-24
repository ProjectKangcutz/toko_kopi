<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\Setting\StoreSettingService;
use App\Services\Storefront\CartService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerCheckoutPageTest extends TestCase
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

    protected function configureRajaOngkir(string $secretKey = 'checkout-test-secret-key-999'): void
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
            'email' => 'customer-'.$suffix.'@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ], $overrides));
    }

    protected function createAdmin(array $overrides = []): User
    {
        $suffix = str_replace('.', '', uniqid('', true));

        $admin = User::create(array_merge([
            'name' => 'Admin '.$suffix,
            'email' => 'admin-'.$suffix.'@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ], $overrides));

        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    protected function createAddress(Customer $customer, array $overrides = []): CustomerAddress
    {
        return $customer->addresses()->create(array_merge([
            'label' => 'Rumah Utama',
            'recipient_name' => 'Rina Kartika',
            'recipient_phone' => '081234567890',
            'address_line' => 'Jl. Braga No. 45',
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
            'sku' => 'CHK-'.$suffix,
            'name' => 'Produk Checkout '.$suffix,
            'slug' => 'produk-checkout-'.$suffix,
            'description' => 'Produk UMKM unggulan.',
            'price' => 50000,
            'stock' => 20,
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
            'sku' => 'CHKV-'.$suffix,
            'name' => 'Varian '.$suffix,
            'price' => 75000,
            'weight_grams' => 450,
            'stock' => 20,
            'reserved_stock' => 0,
            'is_active' => true,
        ], $overrides));
    }

    public function test_test_database_never_uses_tokoonline(): void
    {
        $this->assertNotSame('tokoonline', config('database.connections.mysql.database'));
        $this->assertSame('umkm_shop_test', config('database.connections.mysql.database'));
    }

    public function test_guest_and_admin_only_and_suspended_customer_cannot_access_checkout_page(): void
    {
        $this->get(route('customer.checkout.index'))
            ->assertRedirect(route('customer.login'));

        $admin = $this->createAdmin();
        $this->actingAs($admin, 'admin')
            ->get(route('customer.checkout.index'))
            ->assertRedirect(route('customer.login'));
        $this->assertAuthenticated('admin');

        $suspended = $this->createCustomer(['status' => 'suspended']);
        $this->actingAs($suspended, 'customer')
            ->get(route('customer.checkout.index'))
            ->assertRedirect(route('customer.login'));
        $this->assertGuest('customer');

        Http::assertNothingSent();
    }

    public function test_active_customer_with_cart_items_can_view_checkout_page_with_variants_and_addresses(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, [
            'label' => 'Rumah Bandung',
            'recipient_name' => 'Dewi Anggraini',
        ]);

        $product = $this->createProduct([
            'name' => 'Kopi Arabika Gayo',
            'has_variants' => true,
            'stock' => 0,
        ]);
        $attr = ProductAttribute::create(['name' => 'Kemasan', 'slug' => 'kemasan']);
        $attrVal = ProductAttributeValue::create(['attribute_id' => $attr->id, 'value' => '250 gram', 'slug' => '250-gram']);
        $variant = $this->createVariant($product, [
            'name' => 'Bubuk Medium 250g',
            'price' => 75000,
            'weight_grams' => 300,
            'stock' => 10,
        ]);
        $variant->attributeValues()->attach($attrVal->id);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($product->id, $variant->id, 2, $customer); // 150.000, 600g

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index'));

        $response->assertStatus(200)
            ->assertSee('Checkout')
            ->assertSee('Kopi Arabika Gayo')
            ->assertSee('Bubuk Medium 250g')
            ->assertSee('Kemasan: 250 gram')
            ->assertSee('Rp 150.000')
            ->assertSee('600 gram')
            ->assertSee('Rumah Bandung')
            ->assertSee('Dewi Anggraini')
            ->assertSee('Alamat Utama')
            ->assertSee('checkoutPage(', false)
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false);

        $checkoutConfig = $response->viewData('checkoutConfig');
        $this->assertTrue($checkoutConfig['canCheckout']);
        $this->assertSame(route('customer.shipping.quote'), $checkoutConfig['quoteUrl']);
        $this->assertSame($address->id, $checkoutConfig['initialAddressId']);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1 '));
        $this->assertStringNotContainsString('rajaongkir.komerce.id', $html);
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);
        $this->assertStringNotContainsString('snap.js', $html);
        $this->assertSame(0, Order::count());

        Http::assertNothingSent();
    }

    public function test_empty_cart_blocks_checkout_and_shows_catalog_link_without_quote_endpoint(): void
    {
        $customer = $this->createCustomer();
        $this->createAddress($customer);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index'));

        $response->assertStatus(200)
            ->assertSee('Keranjang Belanja Kosong atau Belum Siap')
            ->assertSee('Jelajahi Katalog Produk')
            ->assertSee(route('storefront.catalog'), false)
            ->assertDontSee('Pilih layanan pengiriman')
            ->assertDontSee('checkoutPage(', false);

        $checkoutConfig = $response->viewData('checkoutConfig');
        $this->assertFalse($checkoutConfig['canCheckout']);
        $this->assertNull($checkoutConfig['quoteUrl']);

        Http::assertNothingSent();
    }

    public function test_addresses_belonging_to_other_customers_and_soft_deleted_addresses_are_never_rendered(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();

        $ownAddress = $this->createAddress($customerA, [
            'label' => 'Alamat Milik Sendiri',
            'recipient_name' => 'Pemilik Asli',
        ]);
        $softDeletedAddress = $this->createAddress($customerA, [
            'label' => 'Alamat Dihapus',
            'recipient_name' => 'Nama Terhapus',
            'is_primary' => false,
        ]);
        $softDeletedAddress->delete();

        $otherAddress = $this->createAddress($customerB, [
            'label' => 'Alamat Rahasia Tetangga',
            'recipient_name' => 'Budi Tetangga',
            'address_line' => 'Jl. Rahasia No. 99',
        ]);

        $product = $this->createProduct(['price' => 40000, 'weight_grams' => 500]);
        app(CartService::class)->addItem($product->id, null, 1, $customerA);

        $response = $this->actingAs($customerA, 'customer')
            ->get(route('customer.checkout.index'));

        $response->assertStatus(200)
            ->assertSee($ownAddress->label)
            ->assertSee('Pemilik Asli')
            ->assertDontSee($softDeletedAddress->label)
            ->assertDontSee('Nama Terhapus')
            ->assertDontSee($otherAddress->label)
            ->assertDontSee('Budi Tetangga')
            ->assertDontSee('Jl. Rahasia No. 99');

        Http::assertNothingSent();
    }

    public function test_customer_without_addresses_receives_empty_state_with_add_address_link_and_no_quote_url(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['price' => 50000, 'weight_grams' => 400]);
        app(CartService::class)->addItem($product->id, null, 1, $customer);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index'));

        $response->assertStatus(200)
            ->assertSee('Belum Ada Alamat Pengiriman')
            ->assertSee('Tambah Alamat Pengiriman')
            ->assertSee(route('customer.addresses.create'), false);

        $checkoutConfig = $response->viewData('checkoutConfig');
        $this->assertNull($checkoutConfig['quoteUrl']);
        $this->assertSame([], $checkoutConfig['addresses']);

        Http::assertNothingSent();
    }

    public function test_quote_called_with_selected_address_returns_rates_and_changing_address_fetches_new_quote(): void
    {
        $this->configureRajaOngkir();

        $customer = $this->createCustomer();
        $addressPrimary = $this->createAddress($customer, [
            'label' => 'Rumah Bandung',
            'subdistrict_id' => '73539',
            'is_primary' => true,
        ]);
        $addressOffice = $this->createAddress($customer, [
            'label' => 'Kantor Jakarta',
            'subdistrict_id' => '17500',
            'is_primary' => false,
        ]);

        $product = $this->createProduct(['price' => 80000, 'weight_grams' => 500]);
        app(CartService::class)->addItem($product->id, null, 2, $customer); // 160.000, 1000g

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::sequence()
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'],
                    'data' => [
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
                    ],
                ], 200)
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'],
                    'data' => [
                        [
                            'name' => 'SiCepat Ekspres',
                            'code' => 'sicepat',
                            'service' => 'BEST',
                            'description' => 'Besok Sampai Tujuan',
                            'cost' => 32000,
                            'etd' => '1 day',
                        ],
                    ],
                ], 200),
        ]);

        // Quote alamat pertama
        $quote1 = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $addressPrimary->id,
            ]);

        $quote1->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.address_id', $addressPrimary->id)
            ->assertJsonPath('data.destination_id', '73539')
            ->assertJsonPath('data.rates.0.courier_code', 'jne')
            ->assertJsonPath('data.rates.0.cost', 19000)
            ->assertJsonPath('data.rates.1.courier_code', 'jnt')
            ->assertJsonPath('data.rates.1.cost', 17000);

        // Ganti ke alamat kedua -> quote baru dipanggil untuk destination 17500 dengan daftar layanan baru
        $quote2 = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $addressOffice->id,
            ]);

        $quote2->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.address_id', $addressOffice->id)
            ->assertJsonPath('data.destination_id', '17500')
            ->assertJsonCount(1, 'data.rates')
            ->assertJsonPath('data.rates.0.courier_code', 'sicepat')
            ->assertJsonPath('data.rates.0.service_code', 'BEST')
            ->assertJsonPath('data.rates.0.cost', 32000)
            ->assertJsonPath('data.rates.0.etd', '1 day');

        Http::assertSentCount(2);
        Http::assertSent(static fn (HttpClientRequest $req): bool => $req['destination'] === '73539');
        Http::assertSent(static fn (HttpClientRequest $req): bool => $req['destination'] === '17500');
    }

    public function test_quote_failure_returns_safe_error_without_exposing_secret_key(): void
    {
        $secretKey = 'checkout-secret-do-not-leak-555';
        $this->configureRajaOngkir($secretKey);

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $product = $this->createProduct(['price' => 50000, 'weight_grams' => 500]);
        app(CartService::class)->addItem($product->id, null, 1, $customer);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => [
                    'code' => 500,
                    'status' => 'error',
                    'message' => "Internal failure key={$secretKey}",
                ],
            ], 500),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ]);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'shipping_upstream_unavailable',
                'data' => null,
            ]);

        $this->assertStringNotContainsString($secretKey, $response->getContent());
    }

    public function test_empty_rates_response_is_handled_cleanly_for_checkout_ui(): void
    {
        $this->configureRajaOngkir();

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $product = $this->createProduct(['price' => 50000, 'weight_grams' => 500]);
        app(CartService::class)->addItem($product->id, null, 1, $customer);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'],
                'data' => [],
            ], 200),
        ]);

        $quoteResponse = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ]);

        $quoteResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rates', []);

        $pageResponse = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index'));

        $pageResponse->assertStatus(200)
            ->assertSee('Belum Ada Layanan Pengiriman Tersedia', false);
    }

    public function test_checkout_page_enforces_alpine_state_reset_and_dynamic_total_preview_contracts(): void
    {
        $customer = $this->createCustomer();
        $addressPrimary = $this->createAddress($customer, ['label' => 'Rumah', 'is_primary' => true]);
        $addressSecond = $this->createAddress($customer, ['label' => 'Kantor', 'is_primary' => false]);

        $product = $this->createProduct(['price' => 120000, 'weight_grams' => 500]);
        app(CartService::class)->addItem($product->id, null, 1, $customer);

        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('store', 'tax_percentage', '10', 'decimal', true);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index'));

        $response->assertStatus(200)
            ->assertSee('Rp 120.000') // Subtotal
            ->assertSee('Pajak (10%)')
            ->assertSee('Rp 12.000') // Pajak 10%
            ->assertSee('Rp 132.000') // Total sementara sebelum pilih kurir
            ->assertSee($addressPrimary->label)
            ->assertSee($addressSecond->label);

        $html = $response->getContent();

        // Verifikasi kontrak reset pilihan tarif saat ganti alamat atau perbarui quote
        $this->assertStringContainsString('resetShippingSelectionAndQuote()', $html);
        $this->assertStringContainsString('this.selectedRateKey = null;', $html);
        $this->assertStringContainsString('this.selectedRate = null;', $html);
        $this->assertStringContainsString('this.rates = [];', $html);
        $this->assertStringContainsString('activeRequestToken', $html);
        $this->assertStringContainsString('new AbortController()', $html);

        // Verifikasi perhitungan total sementara mengikuti layanan terpilih & hanya mengirim address_id
        $this->assertStringContainsString('estimatedGrandTotal', $html);
        $this->assertStringContainsString('this.shippingCostPreview', $html);
        $this->assertStringContainsString('address_id: targetAddressId', $html);
        $this->assertStringContainsString("'X-CSRF-TOKEN': this.csrfToken()", $html);
    }

    public function test_browser_tampering_params_on_checkout_page_and_quote_endpoint_are_ignored(): void
    {
        $this->configureRajaOngkir();

        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $address = $this->createAddress($customer, ['subdistrict_id' => '73539']);

        $product = $this->createProduct([
            'price' => 95000,
            'weight_grams' => 600,
        ]);
        app(CartService::class)->addItem($product->id, null, 2, $customer); // Subtotal 190.000, berat 1200g -> billable 2000g

        // 1. Query tampering pada GET /akun/checkout diabaikan sepenuhnya
        $pageResponse = $this->actingAs($customer, 'customer')
            ->get(route('customer.checkout.index', [
                'subtotal' => 1,
                'shipping_cost' => 0,
                'weight' => 1,
                'total_weight_grams' => 1,
                'discount_amount' => 189999,
                'customer_id' => $otherCustomer->id,
            ]));

        $pageResponse->assertStatus(200)
            ->assertSee('Rp 190.000')
            ->assertSee('1,20 kg')
            ->assertDontSee('Rp 189.999');

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'],
                'data' => [
                    [
                        'name' => 'JNE Express',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Reguler',
                        'cost' => 36000,
                        'etd' => '2-3 day',
                    ],
                ],
            ], 200),
        ]);

        // 2. Body tampering pada POST /akun/pengiriman/quote diabaikan sepenuhnya
        $quoteResponse = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
                'customer_id' => $otherCustomer->id,
                'weight' => 1,
                'actual_weight_grams' => 1,
                'billable_weight_grams' => 1,
                'item_value' => 100,
                'shipping_cost' => 0,
                'cost' => 1,
                'courier' => 'fake_free',
                'origin_id' => '9999',
                'destination_id' => '1111',
            ]);

        $quoteResponse->assertStatus(200)
            ->assertJsonPath('data.origin_id', '4879')
            ->assertJsonPath('data.destination_id', '73539')
            ->assertJsonPath('data.actual_weight_grams', 1200)
            ->assertJsonPath('data.billable_weight_grams', 2000)
            ->assertJsonPath('data.item_value', 190000)
            ->assertJsonPath('data.rates.0.cost', 36000);
    }

    public function test_cart_page_shows_active_checkout_link_for_valid_cart(): void
    {
        $customer = $this->createCustomer();
        $product = $this->createProduct(['price' => 50000, 'weight_grams' => 300]);
        app(CartService::class)->addItem($product->id, null, 1, $customer);

        $this->actingAs($customer, 'customer')
            ->get(route('storefront.cart'))
            ->assertStatus(200)
            ->assertSee('Lanjut ke Checkout')
            ->assertSee(route('customer.checkout.index'), false);
    }
}
