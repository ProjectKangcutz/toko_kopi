<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Role;
use App\Models\User;
use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\KomerceRegionClient;
use App\Services\Shipping\RegionLookupException;
use App\Services\Shipping\RegionLookupService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KomerceRegionLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function configureRajaOngkir(string $apiKey = 'rajaongkir-secret-key-98765', bool $active = true): void
    {
        /** @var StoreSettingService $settings */
        $settings = app(StoreSettingService::class);
        $settings->set('rajaongkir', 'is_active', $active ? '1' : '0', 'boolean', false);
        $settings->set('rajaongkir', 'base_url', 'https://rajaongkir.komerce.id/api/v1', 'string', false);
        $settings->set('rajaongkir', 'api_key', $apiKey, 'encrypted', false);
        $settings->set('rajaongkir', 'timeout', '10', 'integer', false);

        config(['services.rajaongkir.api_key' => null]);
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

    protected function createAdmin(): User
    {
        $suffix = str_replace('.', '', uniqid('', true));

        $admin = User::create([
            'name' => 'Admin '.$suffix,
            'email' => 'admin-'.$suffix.'@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', 'super-admin')->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    public function test_unauthenticated_guest_cannot_access_region_lookup_endpoints(): void
    {
        $this->configureRajaOngkir();

        $this->getJson(route('customer.regions.provinces'))->assertUnauthorized();
        $this->getJson(route('customer.regions.cities', ['provinceId' => '9']))->assertUnauthorized();
        $this->getJson(route('customer.regions.districts', ['cityId' => '23']))->assertUnauthorized();
        $this->getJson(route('customer.regions.subdistricts', ['districtId' => '345']))->assertUnauthorized();

        $this->get(route('customer.regions.provinces'))->assertRedirect(route('customer.login'));

        Http::assertNothingSent();
    }

    public function test_admin_logged_in_only_on_admin_guard_cannot_access_customer_region_lookup_endpoints(): void
    {
        $this->configureRajaOngkir();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->getJson(route('customer.regions.provinces'))
            ->assertUnauthorized();

        $this->actingAs($admin, 'admin')
            ->get(route('customer.regions.provinces'))
            ->assertRedirect(route('customer.login'));

        $this->assertAuthenticated('admin');
        Http::assertNothingSent();
    }

    public function test_suspended_customer_is_rejected_and_logged_out_from_region_lookup_endpoints(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer(['status' => 'suspended']);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'))
            ->assertForbidden()
            ->assertJson([
                'message' => 'Akun pelanggan Anda sedang dinonaktifkan. Silakan hubungi admin toko.',
            ]);

        $this->assertGuest('customer');
        Http::assertNothingSent();
    }

    public function test_active_customer_can_fetch_all_four_region_levels_and_key_header_is_sent_without_leaking(): void
    {
        $secretKey = 'komerce-super-secret-api-key-12345';
        $this->configureRajaOngkir($secretKey, true);
        $customer = $this->createCustomer();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/province' => Http::response([
                'meta' => ['message' => 'Success Get Province', 'code' => 200, 'status' => 'success'],
                'data' => [
                    ['id' => 9, 'name' => 'JAWA BARAT'],
                    ['id' => 6, 'name' => 'DKI JAKARTA'],
                ],
            ], 200),
            'https://rajaongkir.komerce.id/api/v1/destination/city/9' => Http::response([
                'meta' => ['message' => 'Success Get City', 'code' => 200, 'status' => 'success'],
                'data' => [
                    ['id' => 23, 'name' => 'KOTA BANDUNG'],
                ],
            ], 200),
            'https://rajaongkir.komerce.id/api/v1/destination/district/23' => Http::response([
                'meta' => ['message' => 'Success Get District', 'code' => 200, 'status' => 'success'],
                'data' => [
                    ['id' => 345, 'name' => 'SUMUR BANDUNG'],
                ],
            ], 200),
            'https://rajaongkir.komerce.id/api/v1/destination/sub-district/345' => Http::response([
                'meta' => ['message' => 'Success Get Sub-district', 'code' => 200, 'status' => 'success'],
                'data' => [
                    ['id' => 34501, 'name' => 'BRAGA', 'zip_code' => '40111'],
                ],
            ], 200),
        ]);

        $provincesResponse = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'));

        $provincesResponse->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => '9', 'name' => 'JAWA BARAT', 'zip_code' => null],
                    ['id' => '6', 'name' => 'DKI JAKARTA', 'zip_code' => null],
                ],
            ]);

        $citiesResponse = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.cities', ['provinceId' => '9']));

        $citiesResponse->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => '23', 'name' => 'KOTA BANDUNG', 'zip_code' => null],
                ],
            ]);

        $districtsResponse = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.districts', ['cityId' => '23']));

        $districtsResponse->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => '345', 'name' => 'SUMUR BANDUNG', 'zip_code' => null],
                ],
            ]);

        $subdistrictsResponse = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.subdistricts', ['districtId' => '345']));

        $subdistrictsResponse->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => '34501', 'name' => 'BRAGA', 'zip_code' => '40111'],
                ],
            ]);

        foreach ([$provincesResponse, $citiesResponse, $districtsResponse, $subdistrictsResponse] as $response) {
            $this->assertStringNotContainsString($secretKey, (string) $response->getContent());
        }

        Http::assertSentCount(4);
        Http::assertSent(function (Request $request) use ($secretKey): bool {
            return $request->url() === 'https://rajaongkir.komerce.id/api/v1/destination/province'
                && $request->hasHeader('key', $secretKey);
        });
        Http::assertSent(function (Request $request) use ($secretKey): bool {
            return $request->url() === 'https://rajaongkir.komerce.id/api/v1/destination/sub-district/345'
                && $request->hasHeader('key', $secretKey);
        });
    }

    public function test_env_config_fallback_works_when_db_api_key_is_empty(): void
    {
        config([
            'services.rajaongkir.base_url' => 'https://rajaongkir.komerce.id/api/v1',
            'services.rajaongkir.api_key' => 'env-fallback-key-555',
            'services.rajaongkir.timeout' => 8,
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        $customer = $this->createCustomer();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/province' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['id' => 9, 'name' => 'JAWA BARAT']],
            ], 200),
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'))
            ->assertOk()
            ->assertJsonPath('data.0.id', '9');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('key', 'env-fallback-key-555'));
    }

    public function test_successful_lookup_is_cached_and_subsequent_calls_do_not_hit_upstream(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/city/9' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['id' => 23, 'name' => 'KOTA BANDUNG']],
            ], 200),
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.cities', ['provinceId' => '9']))
            ->assertOk();

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.cities', ['provinceId' => '9']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'KOTA BANDUNG');

        Http::assertSentCount(1);
        $this->assertTrue(Cache::has(RegionLookupService::citiesCacheKey('9')));
    }

    public function test_invalid_parent_id_is_rejected_with_422_and_never_calls_upstream(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();

        foreach (['abc', '0', '000', '-12', '9-abc'] as $invalidId) {
            $this->actingAs($customer, 'customer')
                ->getJson(route('customer.regions.cities', ['provinceId' => $invalidId]))
                ->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'error_code' => 'invalid_region_parent',
                    'fallback_manual_allowed' => true,
                    'data' => [],
                ]);
        }

        Http::assertNothingSent();
    }

    public function test_timeout_connection_exception_retries_safely_and_returns_503_without_caching(): void
    {
        $secretKey = 'secret-timeout-key';
        $this->configureRajaOngkir($secretKey, true);
        $customer = $this->createCustomer();

        $attempts = 0;

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/province' => static function () use (&$attempts): never {
                $attempts++;

                throw new ConnectionException('Connection timed out');
            },
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'));

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_timeout',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        $this->assertStringNotContainsString($secretKey, (string) $response->getContent());
        $this->assertSame(2, $attempts);
        $this->assertFalse(Cache::has(RegionLookupService::provincesCacheKey()));
    }

    public function test_http_500_retries_safely_returns_503_without_caching_and_does_not_leak_key(): void
    {
        $secretKey = 'secret-server-error-key';
        $this->configureRajaOngkir($secretKey, true);
        $customer = $this->createCustomer();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/district/23' => Http::response([
                'meta' => ['message' => 'Internal Server Error', 'code' => 500, 'status' => 'error'],
            ], 500),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.districts', ['cityId' => '23']));

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_upstream_unavailable',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        $this->assertStringNotContainsString($secretKey, (string) $response->getContent());
        Http::assertSentCount(2);
        $this->assertFalse(Cache::has(RegionLookupService::districtsCacheKey('23')));
    }

    public function test_http_401_does_not_retry_and_returns_safe_503_without_leaking_key(): void
    {
        $secretKey = 'secret-invalid-auth-key';
        $this->configureRajaOngkir($secretKey, true);
        $customer = $this->createCustomer();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/province' => Http::response([
                'meta' => ['message' => 'Invalid API key: '.$secretKey, 'code' => 401, 'status' => 'error'],
            ], 401),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'));

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_upstream_unavailable',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        $this->assertStringNotContainsString($secretKey, (string) $response->getContent());
        Http::assertSentCount(1);
        $this->assertFalse(Cache::has(RegionLookupService::provincesCacheKey()));
    }

    public function test_malformed_upstream_payload_returns_502_and_never_caches(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/sub-district/345' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['unexpected_field' => 'invalid'],
                ],
            ], 200),
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.subdistricts', ['districtId' => '345']))
            ->assertStatus(502)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_malformed_response',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        $this->assertFalse(Cache::has(RegionLookupService::subdistrictsCacheKey('345')));
    }

    public function test_unconfigured_or_disabled_rajaongkir_returns_503_without_http_calls(): void
    {
        config(['services.rajaongkir.api_key' => null]);
        Cache::forget(StoreSettingService::CACHE_KEY);
        $customer = $this->createCustomer();

        // Kasus 1: API key kosong di DB dan .env
        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'))
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_service_unconfigured',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        // Kasus 2: API key ada di DB tetapi toggle is_active dimatikan eksplisit
        $this->configureRajaOngkir('stored-key-but-disabled', false);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'))
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_service_unconfigured',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        Http::assertNothingSent();
    }

    public function test_existing_customer_addresses_remain_intact_when_region_lookup_fails(): void
    {
        $this->configureRajaOngkir();
        $customer = $this->createCustomer();

        $savedAddress = $customer->addresses()->create([
            'label' => 'Rumah',
            'recipient_name' => 'Budi Santoso',
            'recipient_phone' => '081234567890',
            'address_line' => 'Jl. Asia Afrika No. 10',
            'province_id' => '9',
            'province_name' => 'Jawa Barat',
            'city_id' => '23',
            'city_name' => 'Kota Bandung',
            'district_id' => '345',
            'district_name' => 'Sumur Bandung',
            'subdistrict_id' => '34501',
            'subdistrict_name' => 'Braga',
            'postal_code' => '40111',
            'is_primary' => true,
        ]);

        Http::fake([
            'https://rajaongkir.komerce.id/api/v1/destination/province' => Http::response(['message' => 'Down'], 503),
        ]);

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'))
            ->assertStatus(503)
            ->assertJsonPath('fallback_manual_allowed', true);

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $savedAddress->id,
            'customer_id' => $customer->id,
            'province_name' => 'Jawa Barat',
            'city_name' => 'Kota Bandung',
            'district_name' => 'Sumur Bandung',
            'subdistrict_name' => 'Braga',
            'postal_code' => '40111',
        ]);
        $this->assertSame(1, CustomerAddress::count());
    }

    public function test_collaborator_sandbox_search_uses_x_api_key_header_and_caches_results(): void
    {
        $sandboxKey = 'sandbox-collaborator-secret-key';

        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id',
            'services.rajaongkir.api_key' => $sandboxKey,
            'services.rajaongkir.timeout' => 10,
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        $customer = $this->createCustomer();

        Http::fake([
            'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['message' => 'Success Get Destination', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id' => 31597,
                        'label' => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG, JAWA BARAT, 40111',
                        'province_name' => 'JAWA BARAT',
                        'city_name' => 'KOTA BANDUNG',
                        'district_name' => 'SUMUR BANDUNG',
                        'subdistrict_name' => 'BRAGA',
                        'zip_code' => '40111',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.search', ['keyword' => '40111']));

        $response->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    [
                        'id' => '31597',
                        'name' => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG, JAWA BARAT, 40111',
                        'label' => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG, JAWA BARAT, 40111',
                        'province_name' => 'JAWA BARAT',
                        'city_name' => 'KOTA BANDUNG',
                        'district_name' => 'SUMUR BANDUNG',
                        'subdistrict_name' => 'BRAGA',
                        'zip_code' => '40111',
                    ],
                ],
            ]);

        $this->assertStringNotContainsString($sandboxKey, (string) $response->getContent());

        // Panggilan kedua menggunakan cache
        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.search', ['keyword' => '40111']))
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($sandboxKey): bool {
            return str_starts_with($request->url(), 'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search')
                && $request->hasHeader('x-api-key', $sandboxKey)
                && ! $request->hasHeader('key');
        });
    }

    public function test_hierarchical_endpoints_fail_safely_when_configured_to_collaborator_sandbox(): void
    {
        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id',
            'services.rajaongkir.api_key' => 'sandbox-key-only',
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        $customer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.provinces'))
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'error_code' => 'region_hierarchical_unsupported',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        Http::assertNothingSent();
    }

    public function test_search_rejects_short_or_empty_keyword_with_422(): void
    {
        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id',
            'services.rajaongkir.api_key' => 'sandbox-key-only',
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        $customer = $this->createCustomer();

        $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.search', ['keyword' => 'a']))
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'invalid_region_keyword',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);

        Http::assertNothingSent();
    }

    public function test_find_origin_artisan_command_displays_origin_id_from_sandbox(): void
    {
        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id',
            'services.rajaongkir.api_key' => 'sandbox-cli-key',
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        Http::fake([
            'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['message' => 'Success Get Destination', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id' => 31597,
                        'label' => 'BRAGA, SUMUR BANDUNG, KOTA BANDUNG, JAWA BARAT, 40111',
                        'province_name' => 'JAWA BARAT',
                        'city_name' => 'KOTA BANDUNG',
                        'district_name' => 'SUMUR BANDUNG',
                        'subdistrict_name' => 'BRAGA',
                        'zip_code' => '40111',
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('rajaongkir:find-origin', ['keyword' => 'Braga'])
            ->expectsOutputToContain('RAJAONGKIR_ORIGIN_ID=31597')
            ->assertSuccessful();
    }

    public function test_exception_preserves_sanitized_diagnostics_without_leaking_api_key_or_exposing_internal_fields_to_http_json(): void
    {
        $secretKey = 'sandbox-secret-token-xyz987';

        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id',
            'services.rajaongkir.api_key' => $secretKey,
            'services.rajaongkir.timeout' => 10,
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        Http::fake([
            'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search*' => Http::response([
                'meta' => [
                    'code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized x-api-key='.$secretKey,
                ],
                'data' => null,
            ], 401),
        ]);

        /** @var KomerceRegionClient $client */
        $client = app(KomerceRegionClient::class);

        try {
            $client->searchDestinations('40115');
            $this->fail('RegionLookupException seharusnya dilempar pada respons 401.');
        } catch (RegionLookupException $e) {
            $diagnostics = $e->diagnostics();

            $this->assertSame(503, $e->statusCode());
            $this->assertSame('region_upstream_unavailable', $e->errorCode());
            $this->assertSame('upstream_auth_error', $diagnostics['category']);
            $this->assertSame(401, $diagnostics['http_status']);
            $this->assertSame(401, $diagnostics['meta_code']);
            $this->assertSame('tariff/api/v1/destination/search', $diagnostics['endpoint']);
            $this->assertSame('x-api-key', $diagnostics['auth_header']);
            $this->assertNotNull($diagnostics['upstream_message']);
            $this->assertStringNotContainsString($secretKey, $diagnostics['upstream_message']);
            $this->assertStringContainsString('[REDACTED]', $diagnostics['upstream_message']);
        }

        // Endpoint HTTP customer tidak boleh membocorkan detail internal/diagnostik ke browser
        $customer = $this->createCustomer();
        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.regions.search', ['keyword' => '40115']));

        $response->assertStatus(503)
            ->assertExactJson([
                'success' => false,
                'message' => 'Layanan pencarian wilayah sedang tidak tersedia. Silakan coba lagi nanti.',
                'error_code' => 'region_upstream_unavailable',
                'fallback_manual_allowed' => true,
                'data' => [],
            ]);
        $this->assertStringNotContainsString($secretKey, (string) $response->getContent());
    }

    public function test_find_origin_command_prints_safe_diagnostics_on_upstream_error_without_leaking_key(): void
    {
        $secretKey = 'cli-diag-secret-key-456';

        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id',
            'services.rajaongkir.api_key' => $secretKey,
            'services.rajaongkir.timeout' => 10,
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        Http::fake([
            'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search*' => Http::response([
                'meta' => [
                    'code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid API key '.$secretKey,
                ],
                'data' => null,
            ], 401),
        ]);

        $this->artisan('rajaongkir:find-origin', ['keyword' => '40115'])
            ->expectsOutputToContain('upstream_auth_error')
            ->expectsOutputToContain('HTTP Status     : 401')
            ->expectsOutputToContain('Header Auth     : x-api-key')
            ->expectsOutputToContain('Endpoint        : tariff/api/v1/destination/search')
            ->expectsOutputToContain('[REDACTED]')
            ->doesntExpectOutputToContain($secretKey)
            ->assertFailed();
    }

    public function test_collaborator_base_url_with_tariff_path_and_null_success_data_are_handled_safely(): void
    {
        config([
            'services.rajaongkir.base_url' => 'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/',
            'services.rajaongkir.api_key' => 'sandbox-path-key',
            'services.rajaongkir.timeout' => 10,
        ]);
        Cache::forget(StoreSettingService::CACHE_KEY);

        Http::fake([
            'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success', 'message' => 'Data empty'],
                'data' => null,
            ], 200),
        ]);

        $this->artisan('rajaongkir:find-origin', ['keyword' => '40115'])
            ->expectsOutputToContain('Tidak ditemukan wilayah untuk kata kunci: 40115')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search')
                && ! str_contains($request->url(), 'tariff/api/v1/tariff/api/v1')
                && $request->hasHeader('x-api-key', 'sandbox-path-key');
        });
    }
}
