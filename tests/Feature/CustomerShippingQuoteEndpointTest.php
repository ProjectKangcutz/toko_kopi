<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\ShippingRateException;
use App\Services\Shipping\ShippingRateService;
use App\Services\Storefront\CartService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\ShippingCourierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class CustomerShippingQuoteEndpointTest extends TestCase
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

    protected function sampleQuotePayload(): array
    {
        return [
            'origin_id' => '4879',
            'destination_id' => '73539',
            'actual_weight_grams' => 500,
            'billable_weight_grams' => 1000,
            'item_value' => 75000.0,
            'courier_codes' => ['jne', 'jnt', 'sicepat'],
            'from_cache' => false,
            'rates' => [
                [
                    'courier_code' => 'jnt',
                    'courier_name' => 'J&T Express',
                    'service_code' => 'EZ',
                    'service_name' => 'J&T Regular',
                    'description' => 'Reguler',
                    'cost' => 17000,
                    'etd' => null,
                ],
                [
                    'courier_code' => 'jne',
                    'courier_name' => 'JNE Express',
                    'service_code' => 'REG',
                    'service_name' => 'Layanan Reguler',
                    'description' => 'Layanan Reguler',
                    'cost' => 19000,
                    'etd' => '2-3 day',
                ],
            ],
        ];
    }

    public function test_route_is_registered_with_web_csrf_customer_auth_and_shipping_quote_throttle(): void
    {
        $route = Route::getRoutes()->getByName('customer.shipping.quote');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertSame('akun/pengiriman/quote', $route->uri());

        $middleware = $route->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('auth.customer', $middleware);
        $this->assertContains('throttle:shipping-quote', $middleware);
    }

    public function test_guest_is_rejected_with_401_for_json_and_redirect_for_web(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);

        $this->mock(ShippingRateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('calculateForCustomer');
        });

        $this->postJson(route('customer.shipping.quote'), [
            'address_id' => $address->id,
        ])->assertStatus(401)
            ->assertJson([
                'message' => 'Silakan masuk sebagai pelanggan terlebih dahulu.',
            ]);

        $this->post(route('customer.shipping.quote'), [
            'address_id' => $address->id,
        ])->assertRedirect(route('customer.login'));

        Http::assertNothingSent();
    }

    public function test_admin_authenticated_only_on_admin_guard_is_rejected_with_401(): void
    {
        $admin = $this->createAdmin();
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);

        $this->mock(ShippingRateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('calculateForCustomer');
        });

        $this->actingAs($admin, 'admin')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ])
            ->assertStatus(401);

        $this->assertAuthenticated('admin');
        Http::assertNothingSent();
    }

    public function test_suspended_customer_is_rejected_with_403_and_logged_out(): void
    {
        $suspended = $this->createCustomer(['status' => 'suspended']);
        $address = $this->createAddress($suspended);

        $this->mock(ShippingRateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('calculateForCustomer');
        });

        $this->actingAs($suspended, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ])
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Akun pelanggan Anda sedang dinonaktifkan. Silakan hubungi admin toko.',
            ]);

        $this->assertGuest('customer');
        Http::assertNothingSent();
    }

    public function test_active_customer_receives_valid_json_shipping_quote_for_owned_address(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $sample = $this->sampleQuotePayload();

        $this->mock(ShippingRateService::class, function (MockInterface $mock) use ($customer, $address, $sample): void {
            $mock->shouldReceive('calculateForCustomer')
                ->once()
                ->withArgs(function (Customer $argCustomer, CustomerAddress $argAddress, ?array $requestedCouriers = null) use ($customer, $address): bool {
                    return (int) $argCustomer->id === (int) $customer->id
                        && (int) $argAddress->id === (int) $address->id
                        && $requestedCouriers === null;
                })
                ->andReturn($sample);
        });

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'address_id' => $address->id,
                    'origin_id' => '4879',
                    'destination_id' => '73539',
                    'actual_weight_grams' => 500,
                    'billable_weight_grams' => 1000,
                    'item_value' => 75000,
                    'courier_codes' => ['jne', 'jnt', 'sicepat'],
                    'from_cache' => false,
                    'rates' => $sample['rates'],
                ],
            ]);

        Http::assertNothingSent();
    }

    public function test_end_to_end_quote_with_fake_rajaongkir_http_and_cart_weight_returns_normalized_json(): void
    {
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('rajaongkir', 'is_active', '1', 'boolean', false);
        $settings->set('rajaongkir', 'base_url', 'https://rajaongkir.komerce.id/api/v1', 'string', false);
        $settings->set('rajaongkir', 'api_key', 'test-rajaongkir-endpoint-key', 'encrypted', false);
        $settings->set('rajaongkir', 'origin_id', '4879', 'string', false);
        $settings->set('rajaongkir', 'timeout', '10', 'integer', false);

        $customer = $this->createCustomer();
        $address = $this->createAddress($customer, ['subdistrict_id' => '73539']);

        $product = Product::create([
            'sku' => 'SKU-END-'.uniqid(),
            'name' => 'Kopi Nusantara',
            'slug' => 'kopi-nusantara-'.uniqid(),
            'description' => 'Kopi pilihan UMKM.',
            'price' => 65000,
            'stock' => 15,
            'reserved_stock' => 0,
            'weight_grams' => 450,
            'has_variants' => false,
            'is_active' => true,
        ]);

        /** @var CartService $cartService */
        $cartService = app(CartService::class);
        $cartService->addItem($product->id, null, 2, $customer); // 900g -> dibulatkan 1000g

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
                ],
            ], 200),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.address_id', $address->id)
            ->assertJsonPath('data.origin_id', '4879')
            ->assertJsonPath('data.destination_id', '73539')
            ->assertJsonPath('data.actual_weight_grams', 900)
            ->assertJsonPath('data.billable_weight_grams', 1000)
            ->assertJsonPath('data.item_value', 130000)
            ->assertJsonPath('data.rates.0.courier_code', 'jnt')
            ->assertJsonPath('data.rates.0.service_code', 'EZ')
            ->assertJsonPath('data.rates.0.cost', 17000)
            ->assertJsonPath('data.rates.0.etd', null);
    }

    public function test_customer_cannot_quote_shipping_for_another_customers_address(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $addressOfB = $this->createAddress($customerB);

        $this->mock(ShippingRateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('calculateForCustomer');
        });

        $this->actingAs($customerA, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $addressOfB->id,
            ])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_soft_deleted_address_is_rejected_with_422(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $address->delete();

        $this->mock(ShippingRateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('calculateForCustomer');
        });

        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address_id']);

        Http::assertNothingSent();
    }

    public function test_invalid_address_id_payloads_return_422_validation_error(): void
    {
        $customer = $this->createCustomer();

        $this->mock(ShippingRateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('calculateForCustomer');
        });

        $invalidPayloads = [
            [],
            ['address_id' => null],
            ['address_id' => 'abc'],
            ['address_id' => 0],
            ['address_id' => -10],
            ['address_id' => 99999999],
        ];

        foreach ($invalidPayloads as $payload) {
            $this->actingAs($customer, 'customer')
                ->postJson(route('customer.shipping.quote'), $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['address_id']);
        }

        Http::assertNothingSent();
    }

    public function test_client_tampering_fields_are_completely_ignored_by_endpoint(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $sample = $this->sampleQuotePayload();

        $this->mock(ShippingRateService::class, function (MockInterface $mock) use ($customer, $address, $sample): void {
            $mock->shouldReceive('calculateForCustomer')
                ->once()
                ->withArgs(function (Customer $argCustomer, CustomerAddress $argAddress, ?array $requestedCouriers = null) use ($customer, $address): bool {
                    // Pastikan controller tidak pernah meneruskan parameter kurir atau data dari request client
                    return (int) $argCustomer->id === (int) $customer->id
                        && (int) $argAddress->id === (int) $address->id
                        && $requestedCouriers === null;
                })
                ->andReturn($sample);
        });

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
                'customer_id' => $otherCustomer->id,
                'origin' => '999999',
                'origin_id' => '999999',
                'destination' => '111111',
                'destination_id' => '111111',
                'weight' => 1,
                'actual_weight_grams' => 1,
                'billable_weight_grams' => 1,
                'courier' => 'free_courier',
                'courier_codes' => ['free_courier'],
                'shipping_cost' => 0,
                'cost' => 1,
                'item_value' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.origin_id', '4879')
            ->assertJsonPath('data.destination_id', '73539')
            ->assertJsonPath('data.actual_weight_grams', 500)
            ->assertJsonPath('data.billable_weight_grams', 1000)
            ->assertJsonPath('data.item_value', 75000)
            ->assertJsonPath('data.rates.0.cost', 17000);

        Http::assertNothingSent();
    }

    public function test_shipping_rate_exception_maps_to_safe_json_without_diagnostics_secrets_or_stack_trace(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $secretKey = 'rajaongkir-secret-never-expose-987';

        $exceptions = [
            ShippingRateException::emptyCart(),
            ShippingRateException::malformedResponse([
                'endpoint' => 'calculate/domestic-cost',
                'upstream_message' => "secret={$secretKey}",
            ]),
            ShippingRateException::upstreamUnavailable([
                'http_status' => 503,
                'endpoint' => 'calculate/domestic-cost',
                'auth_header' => 'key',
                'upstream_message' => "internal key={$secretKey}",
            ]),
        ];
        $callIndex = 0;

        $this->mock(ShippingRateService::class, function (MockInterface $mock) use (&$callIndex, $exceptions): void {
            $mock->shouldReceive('calculateForCustomer')
                ->times(3)
                ->andReturnUsing(static function () use (&$callIndex, $exceptions): never {
                    throw $exceptions[$callIndex++];
                });
        });

        // 1. Empty cart -> 422
        $res422 = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), ['address_id' => $address->id]);
        $res422->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => 'Keranjang belanja kosong atau tidak memiliki item yang tersedia untuk dihitung ongkos kirimnya.',
                'error_code' => 'shipping_empty_cart',
                'data' => null,
            ]);

        // 2. Malformed response -> 502
        $res502 = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), ['address_id' => $address->id]);
        $res502->assertStatus(502)
            ->assertJson([
                'success' => false,
                'error_code' => 'shipping_malformed_response',
                'data' => null,
            ]);
        $this->assertStringNotContainsString($secretKey, $res502->getContent());
        $this->assertArrayNotHasKey('diagnostics', $res502->json());
        $this->assertArrayNotHasKey('trace', $res502->json());

        // 3. Upstream unavailable -> 503
        $res503 = $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), ['address_id' => $address->id]);
        $res503->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'shipping_upstream_unavailable',
                'data' => null,
            ]);
        $this->assertStringNotContainsString($secretKey, $res503->getContent());
        $this->assertArrayNotHasKey('diagnostics', $res503->json());
        $this->assertArrayNotHasKey('trace', $res503->json());
        $this->assertArrayNotHasKey('exception', $res503->json());
    }

    public function test_rate_limiter_allows_ten_requests_per_minute_and_returns_429_on_eleventh(): void
    {
        $customer = $this->createCustomer();
        $address = $this->createAddress($customer);
        $sample = $this->sampleQuotePayload();

        $this->mock(ShippingRateService::class, function (MockInterface $mock) use ($sample): void {
            $mock->shouldReceive('calculateForCustomer')
                ->times(10)
                ->andReturn($sample);
        });

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($customer, 'customer')
                ->postJson(route('customer.shipping.quote'), [
                    'address_id' => $address->id,
                ])
                ->assertStatus(200);
        }

        // Request ke-11 harus terkena rate limit 429 dan tidak memanggil service
        $this->actingAs($customer, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $address->id,
            ])
            ->assertStatus(429)
            ->assertExactJson([
                'success' => false,
                'message' => 'Terlalu banyak permintaan cek ongkos kirim. Silakan tunggu sebentar sebelum mencoba lagi.',
                'error_code' => 'shipping_rate_limited',
                'data' => null,
            ]);

        Http::assertNothingSent();
    }

    public function test_rate_limiter_bucket_is_isolated_per_customer(): void
    {
        $customerA = $this->createCustomer();
        $addressA = $this->createAddress($customerA);

        $customerB = $this->createCustomer();
        $addressB = $this->createAddress($customerB);

        $sample = $this->sampleQuotePayload();

        $this->mock(ShippingRateService::class, function (MockInterface $mock) use ($sample): void {
            // 10 request berhasil untuk customerA + 1 request berhasil untuk customerB
            $mock->shouldReceive('calculateForCustomer')
                ->times(11)
                ->andReturn($sample);
        });

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($customerA, 'customer')
                ->postJson(route('customer.shipping.quote'), [
                    'address_id' => $addressA->id,
                ])
                ->assertStatus(200);
        }

        // Customer A mencapai limit 429
        $this->actingAs($customerA, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $addressA->id,
            ])
            ->assertStatus(429);

        // Customer B pada IP yang sama tetap diizinkan karena bucket dipisah per customer ID
        $this->actingAs($customerB, 'customer')
            ->postJson(route('customer.shipping.quote'), [
                'address_id' => $addressB->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.address_id', $addressB->id);

        Http::assertNothingSent();
    }
}
