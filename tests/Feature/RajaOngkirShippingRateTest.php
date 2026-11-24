<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCourier;
use App\Models\ShippingRateLog;
use App\Models\ShippingService;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\RajaOngkirShippingClient;
use App\Services\Shipping\ShippingRateException;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\CartService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RajaOngkirShippingRateTest extends TestCase
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

    protected function configureRajaOngkir(
        string $apiKey = 'rajaongkir-secret-test-key-12345',
        bool $active = true,
        string $originId = '4879',
        string $baseUrl = 'https://rajaongkir.komerce.id/api/v1'
    ): void {
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('rajaongkir', 'is_active', $active ? '1' : '0', 'boolean', false);
        $settings->set('rajaongkir', 'base_url', $baseUrl, 'string', false);
        $settings->set('rajaongkir', 'api_key', $apiKey, 'encrypted', false);
        $settings->set('rajaongkir', 'origin_id', $originId, 'string', false);
        $settings->set('rajaongkir', 'timeout', '10', 'integer', false);

        config([
            'services.rajaongkir.api_key' => null,
            'services.rajaongkir.origin_id' => null,
            'services.rajaongkir.base_url' => $baseUrl,
        ]);
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

    protected function createAddress(Customer $customer, array $overrides = []): CustomerAddress
    {
        return $customer->addresses()->create(array_merge([
            'label' => 'Rumah',
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
            'sku' => 'SKU-'.$suffix,
            'name' => 'Produk UMKM '.$suffix,
            'slug' => 'produk-umkm-'.$suffix,
            'description' => 'Produk unggulan UMKM.',
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
            'sku' => 'VAR-'.$suffix,
            'name' => 'Varian '.$suffix,
            'price' => 75000,
            'weight_grams' => 600,
            'stock' => 20,
            'reserved_stock' => 0,
            'is_active' => true,
        ], $overrides));
    }

    protected function addProductToCustomerCart(
        Customer $customer,
        int $weightGrams = 350,
        int $quantity = 1,
        float $price = 50000
    ): Product {
        $product = $this->createProduct([
            'weight_grams' => $weightGrams,
            'price' => $price,
        ]);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($product->id, null, $quantity, $customer);

        return $product;
    }

    public function test_successful_multi_courier_rate_lookup_normalizes_response_and_logs_safely(): void
    {
        $secretKey = 'rajaongkir-live-secret-key-999';
        $this->configureRajaOngkir($secretKey, true, '4879');

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, ['subdistrict_id' => '73539']);
        $this->addProductToCustomerCart($customer, 450, 2, 75000); // 900g -> dibulatkan 1000g

        // Tambahkan layanan baru 'CTC' di JNE agar teruji layanan tanpa seeder lokal tetap muncul dan normal
        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => [
                    'message' => 'Success Calculate Domestic Shipping cost',
                    'code' => 200,
                    'status' => 'success',
                ],
                'data' => [
                    [
                        'name' => 'J&T Express',
                        'code' => 'jnt',
                        'service' => 'EZ',
                        'description' => 'Reguler',
                        'cost' => 17000,
                        'etd' => '',
                    ],
                    [
                        'name' => 'JNE Express',
                        'code' => 'JNE',
                        'service' => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost' => 19000,
                        'etd' => '2-3 day',
                    ],
                    [
                        'name' => 'SiCepat Ekspres',
                        'code' => 'sicepat',
                        'service' => 'BEST',
                        'description' => 'Besok Sampai Tujuan',
                        'cost' => 28000,
                        'etd' => null,
                    ],
                ],
            ], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);
        $result = $service->calculateForCustomer($customer, $address);

        $this->assertSame('4879', $result['origin_id']);
        $this->assertSame('73539', $result['destination_id']);
        $this->assertSame(900, $result['actual_weight_grams']);
        $this->assertSame(1000, $result['billable_weight_grams']);
        $this->assertFalse($result['from_cache']);
        $this->assertCount(3, $result['rates']);

        // Pastikan ETD kosong atau null dinormalisasi ke null dan field lengkap
        $this->assertSame([
            'courier_code' => 'jnt',
            'courier_name' => 'J&T Express',
            'service_code' => 'EZ',
            'service_name' => 'J&T Regular',
            'description' => 'Reguler',
            'cost' => 17000,
            'etd' => null,
        ], $result['rates'][0]);

        $this->assertSame('jne', $result['rates'][1]['courier_code']);
        $this->assertSame('2-3 day', $result['rates'][1]['etd']);
        $this->assertSame(19000, $result['rates'][1]['cost']);
        $this->assertSame('sicepat', $result['rates'][2]['courier_code']);
        $this->assertNull($result['rates'][2]['etd']);

        Http::assertSent(function (Request $request) use ($secretKey): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost'
                && $request->hasHeader('key', $secretKey)
                && $request->isForm()
                && $request['origin'] === '4879'
                && $request['destination'] === '73539'
                && (int) $request['weight'] === 1000
                && str_contains((string) $request['courier'], 'jne')
                && str_contains((string) $request['courier'], 'jnt');
        });

        // Audit log harus tersimpan lengkap dan bebas API key
        $log = ShippingRateLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(RajaOngkirShippingClient::PROVIDER, $log->provider);
        $this->assertSame('4879', $log->origin_id);
        $this->assertSame('73539', $log->destination_id);
        $this->assertSame(1000, $log->weight_grams);
        $this->assertSame(200, $log->http_status);
        $this->assertTrue($log->is_success);
        $this->assertNull($log->error_message);
        $this->assertArrayNotHasKey('key', $log->request_payload);
        $this->assertArrayNotHasKey('headers', $log->request_payload);
        $this->assertStringNotContainsString($secretKey, json_encode($log->toArray()));
    }

    public function test_empty_or_null_success_data_returns_empty_rates_and_caches_safely(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::sequence()
                ->push(['meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'], 'data' => []], 200)
                ->push(['meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'], 'data' => null], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);
        $first = $service->calculateForCustomer($customer, $address);
        $this->assertSame([], $first['rates']);
        $this->assertFalse($first['from_cache']);

        // Panggilan berikutnya dengan parameter sama dari cache
        $second = $service->calculateForCustomer($customer, $address);
        $this->assertSame([], $second['rates']);
        $this->assertTrue($second['from_cache']);

        // Uji langsung data: null di client
        /** @var RajaOngkirShippingClient $client */
        $client = app(RajaOngkirShippingClient::class);
        $direct = $client->calculateDomesticCost('73539', 1000, ['jne']);
        $this->assertSame([], $direct);
        $this->assertSame(2, ShippingRateLog::where('is_success', true)->count());
    }

    public function test_http_401_fails_safely_without_retry_logs_without_key_and_never_caches(): void
    {
        $this->assertUpstreamHttpFailureHandledSafely(401, 1);
    }

    public function test_http_404_fails_safely_without_retry_logs_without_key_and_never_caches(): void
    {
        $this->assertUpstreamHttpFailureHandledSafely(404, 1);
    }

    public function test_http_422_fails_safely_without_retry_logs_without_key_and_never_caches(): void
    {
        $this->assertUpstreamHttpFailureHandledSafely(422, 1);
    }

    public function test_http_500_retries_safely_logs_without_key_and_never_caches(): void
    {
        $this->assertUpstreamHttpFailureHandledSafely(500, 2);
    }

    protected function assertUpstreamHttpFailureHandledSafely(int $status, int $expectedAttempts): void
    {
        $secretKey = 'super-secret-key-must-not-leak';
        $this->configureRajaOngkir($secretKey);
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => [
                    'code' => $status,
                    'status' => 'error',
                    'message' => "Error {$status} with key={$secretKey}",
                ],
                'echo_token' => $secretKey,
            ], $status),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail("Seharusnya melempar ShippingRateException untuk HTTP {$status}.");
        } catch (ShippingRateException $e) {
            $this->assertSame(503, $e->statusCode());
            $this->assertSame('shipping_upstream_unavailable', $e->errorCode());
            $this->assertSame($status, $e->diagnostics()['http_status']);
            $this->assertStringNotContainsString($secretKey, $e->getMessage());
            $this->assertStringNotContainsString($secretKey, (string) json_encode($e->diagnostics()));
        }

        Http::assertSentCount($expectedAttempts);

        $latestLog = ShippingRateLog::query()->latest('id')->firstOrFail();
        $this->assertFalse($latestLog->is_success);
        $this->assertSame($status, $latestLog->http_status);
        $this->assertStringNotContainsString($secretKey, (string) $latestLog->error_message);
        $this->assertStringNotContainsString($secretKey, (string) json_encode($latestLog->response_payload));
        $this->assertStringNotContainsString($secretKey, (string) json_encode($latestLog->request_payload));
    }

    public function test_connection_timeout_retries_safely_and_logs_without_exposing_key(): void
    {
        $secretKey = 'timeout-secret-key-abc';
        $this->configureRajaOngkir($secretKey);
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        $attempts = 0;
        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => static function () use (&$attempts, $secretKey): never {
                $attempts++;

                throw new ConnectionException("cURL error 28 timeout while sending key={$secretKey}");
            },
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('Seharusnya melempar ShippingRateException timeout.');
        } catch (ShippingRateException $e) {
            $this->assertSame(503, $e->statusCode());
            $this->assertSame('shipping_timeout', $e->errorCode());
            $this->assertStringNotContainsString($secretKey, $e->getMessage());
            $this->assertStringNotContainsString($secretKey, (string) json_encode($e->diagnostics()));
        }

        $this->assertSame(2, $attempts);
        $log = ShippingRateLog::query()->latest('id')->firstOrFail();
        $this->assertFalse($log->is_success);
        $this->assertNull($log->http_status);
        $this->assertStringNotContainsString($secretKey, (string) $log->error_message);
    }

    public function test_invalid_non_json_response_throws_malformed_response_without_caching(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response('<html>Bad Gateway</html>', 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('Seharusnya menolak respons non-JSON.');
        } catch (ShippingRateException $e) {
            $this->assertSame(502, $e->statusCode());
            $this->assertSame('shipping_malformed_response', $e->errorCode());
        }

        $this->assertSame(1, ShippingRateLog::where('is_success', false)->count());
    }

    public function test_negative_cost_payload_throws_malformed_response_without_caching(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'name' => 'JNE Express',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Reguler',
                        'cost' => -5000,
                        'etd' => '2-3',
                    ],
                ],
            ], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('Seharusnya menolak cost bernilai negatif.');
        } catch (ShippingRateException $e) {
            $this->assertSame(502, $e->statusCode());
            $this->assertSame('shipping_malformed_response', $e->errorCode());
        }

        $this->assertSame(1, ShippingRateLog::where('is_success', false)->count());
    }

    public function test_inactive_empty_key_missing_origin_and_collaborator_host_fail_before_http_calls(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        // 1. Integrasi nonaktif
        $this->configureRajaOngkir('valid-key', false, '4879');
        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('Integrasi nonaktif seharusnya ditolak.');
        } catch (ShippingRateException $e) {
            $this->assertSame('shipping_integration_inactive', $e->errorCode());
        }

        // 2. API Key kosong
        $this->configureRajaOngkir('', true, '4879');
        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('API Key kosong seharusnya ditolak.');
        } catch (ShippingRateException $e) {
            $this->assertSame('shipping_service_unconfigured', $e->errorCode());
        }

        // 3. Origin ID kosong
        $this->configureRajaOngkir('valid-key', true, '');
        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('Origin ID kosong seharusnya ditolak.');
        } catch (ShippingRateException $e) {
            $this->assertSame('shipping_origin_missing', $e->errorCode());
        }

        // 4. Collaborator host ditolak pada endpoint tarif V2
        $this->configureRajaOngkir('valid-key', true, '4879', 'https://api-sandbox.collaborator.komerce.id');
        try {
            $service->calculateForCustomer($customer, $address);
            $this->fail('Host Collaborator Sandbox seharusnya ditolak untuk endpoint tarif V2.');
        } catch (ShippingRateException $e) {
            $this->assertSame('shipping_provider_unsupported', $e->errorCode());
        }

        Http::assertNothingSent();
    }

    public function test_manual_or_unowned_destination_address_is_rejected_without_http_calls(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $this->addProductToCustomerCart($customer, 500, 1);

        $manualAddress = $this->createAddress($customer, [
            'province_id' => 'manual',
            'city_id' => 'manual',
            'district_id' => null,
            'subdistrict_id' => null,
        ]);

        $missingSubdistrictAddress = $this->createAddress($customer, [
            'is_primary' => false,
            'subdistrict_id' => null,
        ]);

        $otherAddress = $this->createAddress($otherCustomer);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        foreach ([$manualAddress, $missingSubdistrictAddress] as $badAddress) {
            try {
                $service->calculateForCustomer($customer, $badAddress);
                $this->fail('Alamat manual/tanpa destination ID harus ditolak.');
            } catch (ShippingRateException $e) {
                $this->assertSame(422, $e->statusCode());
                $this->assertSame('shipping_invalid_destination', $e->errorCode());
            }
        }

        $this->expectException(AuthorizationException::class);
        try {
            $service->calculateForCustomer($customer, $otherAddress);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_weight_uses_cart_variant_and_applies_min_weight_and_rounding_consistently(): void
    {
        $this->configureRajaOngkir();
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('store', 'min_weight_grams', '500', 'integer', false);
        $settings->set('store', 'weight_rounding_grams', '1000', 'integer', false);

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);

        // Produk varian 600g x 2 = 1200g, plus produk tunggal 150g x 1 = 150g => total aktual 1350g => dibulatkan 2000g
        $variantProduct = $this->createProduct(['has_variants' => true, 'weight_grams' => 100]);
        $variant = $this->createVariant($variantProduct, ['weight_grams' => 600]);
        $simpleProduct = $this->createProduct(['has_variants' => false, 'weight_grams' => 150]);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($variantProduct->id, $variant->id, 2, $customer);
        $cartService->addItem($simpleProduct->id, null, 1, $customer);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['name' => 'JNE Express', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 36000, 'etd' => '2-3'],
                ],
            ], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);
        $result = $service->calculateForCustomer($customer, $address);

        $this->assertSame(1350, $result['actual_weight_grams']);
        $this->assertSame(2000, $result['billable_weight_grams']);
        $this->assertGreaterThanOrEqual($result['actual_weight_grams'], $result['billable_weight_grams']);

        // Uji berat di bawah min_weight_grams saat rounding dinonaktifkan (0)
        $settings->set('store', 'weight_rounding_grams', '0', 'integer', false);
        $this->assertSame(500, $service->calculateBillableWeightGrams(120));
        $this->assertSame(850, $service->calculateBillableWeightGrams(850));

        Http::assertSent(static fn (Request $request): bool => (int) $request['weight'] === 2000);
    }

    public function test_inactive_couriers_and_inactive_services_are_excluded_from_request_and_results(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        // Nonaktifkan 'tiki' dan nonaktifkan layanan JNE 'OKE'
        ShippingCourier::where('code', 'tiki')->update(['is_active' => false]);
        $jne = ShippingCourier::where('code', 'jne')->firstOrFail();
        ShippingService::where('courier_id', $jne->id)->where('service_code', 'OKE')->update(['is_active' => false]);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['name' => 'TIKI', 'code' => 'tiki', 'service' => 'REG', 'description' => 'Regular', 'cost' => 15000, 'etd' => '2'],
                    ['name' => 'JNE Express', 'code' => 'jne', 'service' => 'OKE', 'description' => 'Ekonomis', 'cost' => 12000, 'etd' => '4-5'],
                    ['name' => 'JNE Express', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 18000, 'etd' => '2-3'],
                ],
            ], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);
        $result = $service->calculateForCustomer($customer, $address);

        $this->assertNotContains('tiki', $result['courier_codes']);
        $this->assertCount(1, $result['rates']);
        $this->assertSame('jne', $result['rates'][0]['courier_code']);
        $this->assertSame('REG', $result['rates'][0]['service_code']);

        Http::assertSent(static fn (Request $request): bool => ! str_contains((string) $request['courier'], 'tiki'));
    }

    public function test_server_uses_normal_cost_ignoring_cashback_and_does_not_trust_client_rate(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 1000, 1, 120000);

        // Bahkan jika payload upstream membawa field diskon/cashback aggregator, tarif pelanggan tetap pakai `cost`
        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'name' => 'JNE Express',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Reguler',
                        'cost' => 22000,
                        'shipping_cost_net' => 15000,
                        'shipping_cashback' => 7000,
                        'etd' => '2-3',
                    ],
                ],
            ], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);
        $selected = $service->resolveSelectedRate($customer, $address->id, 'jne', 'REG');

        $this->assertSame(22000, $selected['cost']);
        $this->assertSame(1000, $selected['billable_weight_grams']);

        // Layanan yang tidak ada pada kurir aktif harus ditolak
        $this->expectException(ShippingRateException::class);
        $service->resolveSelectedRate($customer, $address->id, 'jne', 'FREE_FAKE');
    }

    public function test_cache_hit_and_cache_invalidation_when_couriers_or_settings_change(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $this->addProductToCustomerCart($customer, 500, 1);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::sequence()
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success'],
                    'data' => [
                        ['name' => 'JNE Express', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 18000, 'etd' => '2-3'],
                    ],
                ], 200)
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success'],
                    'data' => [
                        ['name' => 'JNE Express', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 21000, 'etd' => '2-3'],
                    ],
                ], 200)
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success'],
                    'data' => [
                        ['name' => 'JNE Express', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 25000, 'etd' => '2-3'],
                    ],
                ], 200),
        ]);

        /** @var ShippingRateService $service */
        $service = app(ShippingRateService::class);

        $first = $service->calculateForCustomer($customer, $address);
        $this->assertFalse($first['from_cache']);
        $this->assertSame(18000, $first['rates'][0]['cost']);

        // Panggilan kedua -> cache hit (0 HTTP tambahan)
        $second = $service->calculateForCustomer($customer, $address);
        $this->assertTrue($second['from_cache']);
        $this->assertSame(18000, $second['rates'][0]['cost']);
        Http::assertSentCount(1);

        // Perubahan ShippingCourier memicu invalidasi cache otomatis
        $courier = ShippingCourier::where('code', 'jne')->firstOrFail();
        $courier->update(['name' => 'JNE Express Indonesia']);

        $third = $service->calculateForCustomer($customer, $address);
        $this->assertFalse($third['from_cache']);
        $this->assertSame(21000, $third['rates'][0]['cost']);
        $this->assertSame('JNE Express Indonesia', $third['rates'][0]['courier_name']);
        Http::assertSentCount(2);

        // Perubahan setting store memicu invalidasi cache otomatis
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('store', 'min_weight_grams', '200', 'integer', false);

        $fourth = $service->calculateForCustomer($customer, $address);
        $this->assertFalse($fourth['from_cache']);
        $this->assertSame(25000, $fourth['rates'][0]['cost']);
        Http::assertSentCount(3);
    }
}
