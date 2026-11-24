<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Setting\StoreSettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsAndOrderAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SettingSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function createAdminWithRole(string $roleSlug): User
    {
        $suffix = bin2hex(random_bytes(3));
        $admin = User::create([
            'name' => 'Admin '.$suffix,
            'email' => "adm-{$roleSlug}-{$suffix}@example.com",
            'password' => 'Password!123',
            'is_active' => true,
        ]);

        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $admin->roles()->attach($role->id);

        return $admin;
    }

    public function test_store_and_integration_settings_preserve_encrypted_secrets_and_never_expose_plaintext(): void
    {
        $superAdmin = $this->createAdminWithRole('super-admin');
        $productManager = $this->createAdminWithRole('product-manager');

        // Role without settings.view is forbidden
        $this->actingAs($productManager, 'admin')
            ->get(route('admin.settings.edit'))
            ->assertForbidden();

        // Update store operational settings
        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.settings.store.update'), [
                'store_name' => 'Pasar UMKM Nusantara Jaya',
                'store_tagline' => 'Karya Asli Pengrajin Lokal',
                'store_description' => 'Toko resmi UMKM lokal.',
                'store_address' => 'Jl. Asia Afrika No. 10, Bandung',
                'store_phone' => '0225551234',
                'store_email' => 'cs@pasarlokal.test',
                'store_whatsapp' => '6281234567890',
                'store_hours' => 'Senin - Sabtu 08:00 - 17:00',
                'warehouse_address' => 'Gudang Bandung Barat',
                'origin_city_name' => 'Kota Bandung',
                'min_weight_grams' => 200,
                'weight_rounding_grams' => 1000,
                'min_order_amount' => 25000,
                'tax_percentage' => 0,
                'store_status' => 'open',
                'maintenance_mode' => '0',
                'guest_checkout_enabled' => '1',
                'customer_registration_enabled' => '1',
                'payment_expiry_minutes' => 720,
                'auto_cancel_expired' => '1',
                'stock_reservation_enabled' => '1',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        /** @var StoreSettingService $settingService */
        $settingService = app(StoreSettingService::class);
        $this->assertSame('Pasar UMKM Nusantara Jaya', $settingService->publicSettings()['store_name']);

        // Save encrypted Midtrans & RajaOngkir secrets
        $midtransSecret = 'SB-Mid-server-SUPER-SECRET-VALUE-12345';
        $rajaOngkirSecret = 'RAJAONGKIR-SUPER-SECRET-KEY-98765';

        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.settings.integrations.update'), [
                'midtrans_is_active' => '1',
                'midtrans_is_production' => '0',
                'midtrans_merchant_id' => 'G99887766',
                'midtrans_client_key' => 'SB-Mid-client-PUBLIC-111',
                'midtrans_server_key' => $midtransSecret,
                'clear_midtrans_db_credentials' => '0',
                'rajaongkir_is_active' => '1',
                'rajaongkir_base_url' => 'https://rajaongkir.komerce.id/api/v1',
                'rajaongkir_origin_id' => '501',
                'rajaongkir_timeout' => 12,
                'rajaongkir_api_key' => $rajaOngkirSecret,
                'clear_rajaongkir_db_credentials' => '0',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        // Decrypted values available internally via StoreSettingService
        $this->assertSame($midtransSecret, $settingService->midtransConfig()['server_key']);
        $this->assertSame($rajaOngkirSecret, $settingService->rajaOngkirConfig()['api_key']);
        $this->assertTrue($settingService->rajaOngkirConfig()['is_active']);

        // Re-opening settings page MUST NEVER expose plaintext server_key or api_key in HTML
        $pageResponse = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Tersimpan di DB (Terenkripsi)')
            ->assertDontSee($midtransSecret)
            ->assertDontSee($rajaOngkirSecret);

        $this->assertStringNotContainsString($midtransSecret, $pageResponse->getContent());

        // Saving form again with BLANK server_key/api_key MUST preserve existing encrypted keys & toggle RajaOngkir off safely
        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.settings.integrations.update'), [
                'midtrans_is_active' => '1',
                'midtrans_is_production' => '0',
                'midtrans_merchant_id' => 'G99887766',
                'midtrans_client_key' => 'SB-Mid-client-PUBLIC-111',
                'midtrans_server_key' => '',
                'clear_midtrans_db_credentials' => '0',
                'rajaongkir_is_active' => '0',
                'rajaongkir_base_url' => 'https://rajaongkir.komerce.id/api/v1',
                'rajaongkir_origin_id' => '501',
                'rajaongkir_timeout' => 10,
                'rajaongkir_api_key' => '',
                'clear_rajaongkir_db_credentials' => '0',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame($midtransSecret, $settingService->midtransConfig()['server_key']);
        $this->assertSame($rajaOngkirSecret, $settingService->rajaOngkirConfig()['api_key']);
        $this->assertFalse($settingService->rajaOngkirConfig()['is_active']);
    }

    public function test_custom_role_permission_alignment_on_admin_order_detail_and_midtrans_history_display(): void
    {
        $customRole = Role::create([
            'name' => 'Status Updater Without Shipment',
            'slug' => 'status-updater-no-ship',
            'description' => 'Hanya ubah status order tanpa izin shipment.',
            'is_system' => false,
        ]);

        $permIds = Permission::whereIn('slug', [
            'dashboard.view',
            'orders.view',
            'orders.update_status',
            'payments.view',
        ])->pluck('id')->all();
        $customRole->permissions()->sync($permIds);

        $limitedAdmin = User::create([
            'name' => 'Admin Terbatas',
            'email' => 'limited-order@example.com',
            'password' => 'Password!123',
            'is_active' => true,
        ]);
        $limitedAdmin->roles()->attach($customRole->id);

        $customer = Customer::create([
            'name' => 'Pembeli Test',
            'email' => 'cust-midtrans-hist@example.com',
            'phone' => '081234567890',
            'password' => 'Password!123',
            'status' => 'active',
        ]);

        $order = Order::create([
            'order_number' => 'PLN-20261123-PAY01',
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'customer_email_snapshot' => $customer->email,
            'customer_phone_snapshot' => $customer->phone,
            'is_guest' => false,
            'order_status' => 'processing',
            'payment_status' => 'paid',
            'shipment_status' => 'unfulfilled',
            'subtotal' => 100000,
            'shipping_cost' => 15000,
            'grand_total' => 115000,
            'total_weight_grams' => 500,
            'paid_at' => now(),
        ]);

        Shipment::create([
            'order_id' => $order->id,
            'courier_code' => 'jne',
            'courier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Reguler',
            'shipping_cost' => 15000,
            'weight_grams' => 500,
            'status' => 'unfulfilled',
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => 'midtrans',
            'midtrans_order_id' => 'PLN-20261123-PAY01-P1-ABCD',
            'payment_type' => 'bank_transfer',
            'gross_amount' => 115000,
            'status' => 'settlement',
            'midtrans_transaction_id' => 'tx-midtrans-998877',
            'fraud_status' => 'accept',
            'paid_at' => now(),
        ]);

        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'transaction_id' => 'tx-midtrans-998877',
            'event_type' => 'webhook',
            'status' => 'settlement',
            'gross_amount' => 115000,
            'payment_type' => 'bank_transfer',
            'signature_verified' => true,
            'raw_payload' => ['signature_key' => 'SECRET-SIGNATURE-HASH-XYZ'],
            'occurred_at' => now(),
        ]);

        PaymentWebhookLog::create([
            'idempotency_key' => 'idem-key-123',
            'gateway' => 'midtrans',
            'midtrans_order_id' => 'PLN-20261123-PAY01-P1-ABCD',
            'transaction_id' => 'tx-midtrans-998877',
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'signature_valid' => true,
            'processed' => true,
            'headers' => ['authorization' => 'Basic SECRET'],
            'payload' => ['signature_key' => 'SECRET-SIGNATURE-HASH-XYZ'],
            'received_at' => now(),
        ]);

        // Order detail shows Midtrans payment/transaction/webhook history without secrets,
        // and MUST NOT show shipment mutation forms because limitedAdmin lacks shipments.update
        $detailResponse = $this->actingAs($limitedAdmin, 'admin')
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Riwayat Pembayaran Midtrans')
            ->assertSee('PLN-20261123-PAY01-P1-ABCD')
            ->assertSee('tx-midtrans-998877')
            ->assertSee('Signature Valid')
            ->assertDontSee('SECRET-SIGNATURE-HASH-XYZ')
            ->assertDontSee('Kirim &amp; Simpan Resi', false);

        $this->assertStringNotContainsString(route('admin.orders.shipped', $order), $detailResponse->getContent());

        // Attempting POST to shipment route returns 403 matching hidden UI
        $this->actingAs($limitedAdmin, 'admin')
            ->post(route('admin.orders.shipped', $order), [
                'tracking_number' => 'JNE123456',
            ])
            ->assertForbidden();
    }

    public function test_per_tab_midtrans_and_rajaongkir_updates_preserve_sibling_provider_and_active_tab(): void
    {
        $superAdmin = $this->createAdminWithRole('super-admin');
        $productManager = $this->createAdminWithRole('product-manager');

        // Unauthorized admin cannot update individual provider tabs
        $this->actingAs($productManager, 'admin')
            ->put(route('admin.settings.midtrans.update'), [])
            ->assertForbidden();

        $this->actingAs($productManager, 'admin')
            ->put(route('admin.settings.rajaongkir.update'), [])
            ->assertForbidden();

        $midtransSecret = 'SB-Mid-server-TAB-SECRET-999';
        $rajaOngkirSecret = 'RAJAONGKIR-TAB-SECRET-888';

        // Save Midtrans tab independently
        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.settings.midtrans.update'), [
                'midtrans_is_active' => '1',
                'midtrans_is_production' => '0',
                'midtrans_merchant_id' => 'M-TAB-01',
                'midtrans_client_key' => 'SB-Mid-client-TAB-01',
                'midtrans_server_key' => $midtransSecret,
                'clear_midtrans_db_credentials' => '0',
            ])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('active_tab', 'midtrans');

        // Save RajaOngkir tab independently -> MUST NOT affect Midtrans settings
        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.settings.rajaongkir.update'), [
                'rajaongkir_is_active' => '1',
                'rajaongkir_base_url' => 'https://rajaongkir.komerce.id/api/v1',
                'rajaongkir_origin_id' => '152',
                'rajaongkir_timeout' => 15,
                'rajaongkir_api_key' => $rajaOngkirSecret,
                'clear_rajaongkir_db_credentials' => '0',
            ])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('active_tab', 'rajaongkir');

        /** @var StoreSettingService $settingService */
        $settingService = app(StoreSettingService::class);
        $midtrans = $settingService->midtransConfig();
        $raja = $settingService->rajaOngkirConfig();

        $this->assertTrue($midtrans['is_active']);
        $this->assertSame('M-TAB-01', $midtrans['merchant_id']);
        $this->assertSame($midtransSecret, $midtrans['server_key']);

        $this->assertTrue($raja['is_active']);
        $this->assertSame('152', $raja['origin_id']);
        $this->assertSame($rajaOngkirSecret, $raja['api_key']);
    }

    public function test_provider_integration_health_checks_verify_connectivity_and_never_leak_secrets(): void
    {
        $superAdmin = $this->createAdminWithRole('super-admin');
        $productManager = $this->createAdminWithRole('product-manager');

        $this->actingAs($productManager, 'admin')
            ->postJson(route('admin.settings.midtrans.check'))
            ->assertForbidden();

        $this->actingAs($productManager, 'admin')
            ->postJson(route('admin.settings.rajaongkir.check'))
            ->assertForbidden();

        $midtransSecret = 'SB-Mid-server-HEALTHCHECK-SECRET';
        $rajaOngkirSecret = 'RAJAONGKIR-HEALTHCHECK-SECRET';

        /** @var StoreSettingService $settingService */
        $settingService = app(StoreSettingService::class);
        $settingService->set('midtrans', 'is_active', true, 'boolean', false);
        $settingService->set('midtrans', 'is_production', false, 'boolean', false);
        $settingService->set('midtrans', 'client_key', 'SB-Mid-client-CHECK', 'string', false);
        $settingService->set('midtrans', 'server_key', $midtransSecret, 'encrypted', false);

        $settingService->set('rajaongkir', 'is_active', true, 'boolean', false);
        $settingService->set('rajaongkir', 'base_url', 'https://rajaongkir.komerce.id/api/v1', 'string', false);
        $settingService->set('rajaongkir', 'origin_id', '501', 'string', false);
        $settingService->set('rajaongkir', 'timeout', 10, 'integer', false);
        $settingService->set('rajaongkir', 'api_key', $rajaOngkirSecret, 'encrypted', false);

        // 1. Call #1: Midtrans HTTP 404 JSON = key valid, dummy order absent -> success: true
        //    Call #2: Midtrans HTTP 401 = auth_error tanpa membocorkan secret
        // 2. Call #1: RajaOngkir HTTP 200 with data list -> success: true
        //    Call #2: RajaOngkir HTTP 401 = auth_error tanpa membocorkan secret
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/*' => Http::sequence()
                ->push([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404)
                ->push([
                    'status_code' => '401',
                    'status_message' => 'Access denied with key '.$midtransSecret,
                ], 401),
            'https://rajaongkir.komerce.id/api/v1/destination/domestic-destination*' => Http::sequence()
                ->push([
                    'meta' => ['code' => 200, 'status' => 'success', 'message' => 'OK'],
                    'data' => [
                        ['id' => 152, 'label' => 'JAKARTA PUSAT'],
                    ],
                ], 200)
                ->push([
                    'meta' => ['code' => 401, 'status' => 'error', 'message' => 'Invalid key '.$rajaOngkirSecret],
                ], 401),
        ]);

        $midtransOk = $this->actingAs($superAdmin, 'admin')
            ->postJson(route('admin.settings.midtrans.check'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('details.environment', 'Sandbox');

        $this->assertStringNotContainsString($midtransSecret, $midtransOk->getContent());

        $rajaOk = $this->actingAs($superAdmin, 'admin')
            ->postJson(route('admin.settings.rajaongkir.check'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('details.sample_count', 1);

        $this->assertStringNotContainsString($rajaOngkirSecret, $rajaOk->getContent());

        // Auth failure responses MUST fail cleanly without leaking keys
        $midtransFail = $this->actingAs($superAdmin, 'admin')
            ->postJson(route('admin.settings.midtrans.check'))
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'auth_error');

        $this->assertStringNotContainsString($midtransSecret, $midtransFail->getContent());

        $rajaFail = $this->actingAs($superAdmin, 'admin')
            ->postJson(route('admin.settings.rajaongkir.check'))
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'auth_error');

        $this->assertStringNotContainsString($rajaOngkirSecret, $rajaFail->getContent());
    }
}
