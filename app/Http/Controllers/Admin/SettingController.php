<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateIntegrationSettingsRequest;
use App\Http\Requests\Admin\UpdateMidtransSettingsRequest;
use App\Http\Requests\Admin\UpdateRajaOngkirSettingsRequest;
use App\Http\Requests\Admin\UpdateStoreSettingsRequest;
use App\Models\User;
use App\Services\Security\AuditLogService;
use App\Services\Setting\IntegrationHealthCheckService;
use App\Services\Setting\StoreSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SettingController extends Controller
{
    public function __construct(
        protected StoreSettingService $settingService,
        protected IntegrationHealthCheckService $healthCheckService,
    ) {}

    public function edit(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.view'), 403);

        $all = $this->settingService->allDecoded();

        $storeForm = [
            'store_name' => (string) ($all['general']['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara')),
            'store_tagline' => (string) ($all['general']['store_tagline'] ?? ''),
            'store_description' => (string) ($all['general']['store_description'] ?? ''),
            'store_address' => (string) ($all['general']['store_address'] ?? ''),
            'store_phone' => (string) ($all['general']['store_phone'] ?? ''),
            'store_email' => (string) ($all['general']['store_email'] ?? ''),
            'store_whatsapp' => (string) ($all['general']['store_whatsapp'] ?? ''),
            'store_hours' => (string) ($all['general']['store_hours'] ?? ''),
            'warehouse_address' => (string) ($all['store']['warehouse_address'] ?? ''),
            'origin_city_name' => (string) ($all['store']['origin_city_name'] ?? ''),
            'min_weight_grams' => (int) ($all['store']['min_weight_grams'] ?? 100),
            'weight_rounding_grams' => (int) ($all['store']['weight_rounding_grams'] ?? 1000),
            'min_order_amount' => (float) ($all['store']['min_order_amount'] ?? 0),
            'tax_percentage' => (float) ($all['store']['tax_percentage'] ?? 0),
            'store_status' => (string) ($all['store']['store_status'] ?? 'open'),
            'maintenance_mode' => (bool) ($all['store']['maintenance_mode'] ?? false),
            'guest_checkout_enabled' => (bool) ($all['checkout']['guest_checkout_enabled'] ?? true),
            'customer_registration_enabled' => (bool) ($all['checkout']['customer_registration_enabled'] ?? true),
            'payment_expiry_minutes' => (int) ($all['checkout']['payment_expiry_minutes'] ?? 1440),
            'auto_cancel_expired' => (bool) ($all['checkout']['auto_cancel_expired'] ?? true),
            'stock_reservation_enabled' => (bool) ($all['checkout']['stock_reservation_enabled'] ?? true),
        ];

        $effectiveMidtrans = $this->settingService->midtransConfig();
        $effectiveRajaOngkir = $this->settingService->rajaOngkirConfig();

        $integrationStatus = [
            'midtrans' => [
                'db_is_active' => (bool) ($all['midtrans']['is_active'] ?? false),
                'db_is_production' => (bool) ($all['midtrans']['is_production'] ?? false),
                'db_merchant_id' => (string) ($all['midtrans']['merchant_id'] ?? ''),
                'db_client_key' => (string) ($all['midtrans']['client_key'] ?? ''),
                'has_db_server_key' => trim((string) ($all['midtrans']['server_key'] ?? '')) !== '',
                'has_db_client_key' => trim((string) ($all['midtrans']['client_key'] ?? '')) !== '',
                'has_env_server_key' => trim((string) config('services.midtrans.server_key', '')) !== '',
                'has_env_client_key' => trim((string) config('services.midtrans.client_key', '')) !== '',
                'effective_is_active' => (bool) ($effectiveMidtrans['is_active'] ?? false),
                'effective_is_production' => (bool) ($effectiveMidtrans['is_production'] ?? false),
                'effective_has_client_key' => trim((string) ($effectiveMidtrans['client_key'] ?? '')) !== '',
                'effective_has_server_key' => trim((string) ($effectiveMidtrans['server_key'] ?? '')) !== '',
                'effective_merchant_id' => (string) ($effectiveMidtrans['merchant_id'] ?? ''),
            ],
            'rajaongkir' => [
                'db_is_active' => (bool) ($all['rajaongkir']['is_active'] ?? false),
                'db_base_url' => (string) ($all['rajaongkir']['base_url'] ?? ''),
                'db_origin_id' => (string) ($all['rajaongkir']['origin_id'] ?? ''),
                'db_timeout' => (int) ($all['rajaongkir']['timeout'] ?? config('services.rajaongkir.timeout', 10)),
                'has_db_api_key' => trim((string) ($all['rajaongkir']['api_key'] ?? '')) !== '',
                'has_env_api_key' => trim((string) config('services.rajaongkir.api_key', '')) !== '',
                'effective_is_active' => (bool) ($effectiveRajaOngkir['is_active'] ?? false),
                'effective_base_url' => (string) ($effectiveRajaOngkir['base_url'] ?? ''),
                'effective_origin_id' => (string) ($effectiveRajaOngkir['origin_id'] ?? ''),
                'effective_timeout' => (int) ($effectiveRajaOngkir['timeout'] ?? 10),
                'effective_has_api_key' => trim((string) ($effectiveRajaOngkir['api_key'] ?? '')) !== '',
            ],
        ];

        $allowedTabs = ['toko', 'midtrans', 'rajaongkir'];
        $requestedTab = (string) ($request->old('_tab') ?: $request->session()->get('active_tab') ?: $request->query('tab', 'toko'));
        $activeTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'toko';

        return view('admin.settings.edit', compact('admin', 'storeForm', 'integrationStatus', 'activeTab'));
    }

    public function updateStore(UpdateStoreSettingsRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.manage'), 403);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($admin, $data, $request): void {
                $this->settingService->set('general', 'store_name', $data['store_name'], 'string', true);
                $this->settingService->set('general', 'store_tagline', $data['store_tagline'] ?? '', 'string', true);
                $this->settingService->set('general', 'store_description', $data['store_description'] ?? '', 'string', true);
                $this->settingService->set('general', 'store_address', $data['store_address'] ?? '', 'string', true);
                $this->settingService->set('general', 'store_phone', $data['store_phone'] ?? '', 'string', true);
                $this->settingService->set('general', 'store_email', $data['store_email'] ?? '', 'string', true);
                $this->settingService->set('general', 'store_whatsapp', $data['store_whatsapp'] ?? '', 'string', true);
                $this->settingService->set('general', 'store_hours', $data['store_hours'] ?? '', 'string', true);

                $this->settingService->set('store', 'warehouse_address', $data['warehouse_address'] ?? '', 'string', false);
                $this->settingService->set('store', 'origin_city_name', $data['origin_city_name'] ?? '', 'string', true);
                $this->settingService->set('store', 'min_weight_grams', (int) $data['min_weight_grams'], 'integer', false);
                $this->settingService->set('store', 'weight_rounding_grams', (int) $data['weight_rounding_grams'], 'integer', false);
                $this->settingService->set('store', 'min_order_amount', (float) $data['min_order_amount'], 'decimal', true);
                $this->settingService->set('store', 'tax_percentage', (float) $data['tax_percentage'], 'decimal', true);
                $this->settingService->set('store', 'store_status', $data['store_status'], 'string', true);
                $this->settingService->set('store', 'maintenance_mode', (bool) $data['maintenance_mode'], 'boolean', false);

                $this->settingService->set('checkout', 'guest_checkout_enabled', (bool) $data['guest_checkout_enabled'], 'boolean', true);
                $this->settingService->set('checkout', 'customer_registration_enabled', (bool) $data['customer_registration_enabled'], 'boolean', true);
                $this->settingService->set('checkout', 'payment_expiry_minutes', (int) $data['payment_expiry_minutes'], 'integer', false);
                $this->settingService->set('checkout', 'auto_cancel_expired', (bool) $data['auto_cancel_expired'], 'boolean', false);
                $this->settingService->set('checkout', 'stock_reservation_enabled', (bool) $data['stock_reservation_enabled'], 'boolean', false);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'settings.store_updated',
                    'Memperbarui profil toko dan pengaturan operasional.',
                    null,
                    [
                        'store_name' => $data['store_name'],
                        'store_status' => $data['store_status'],
                        'maintenance_mode' => (bool) $data['maintenance_mode'],
                        'guest_checkout_enabled' => (bool) $data['guest_checkout_enabled'],
                    ],
                    $request
                );
            });

            return redirect()->route('admin.settings.edit')
                ->with('active_tab', 'toko')
                ->with('success', 'Pengaturan profil toko dan operasional berhasil disimpan.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui pengaturan toko oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->with('active_tab', 'toko')->withErrors(['error' => 'Terjadi kesalahan saat menyimpan pengaturan toko.']);
        }
    }

    public function updateMidtrans(UpdateMidtransSettingsRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.integrations'), 403);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($admin, $data, $request): void {
                $this->persistMidtransSettings($admin, $data, $request);
            });

            return redirect()->route('admin.settings.edit')
                ->with('active_tab', 'midtrans')
                ->with('success', 'Pengaturan integrasi Midtrans berhasil disimpan.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui pengaturan integrasi Midtrans oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->with('active_tab', 'midtrans')->withErrors(['error' => 'Terjadi kesalahan saat menyimpan pengaturan integrasi Midtrans.']);
        }
    }

    public function updateRajaOngkir(UpdateRajaOngkirSettingsRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.integrations'), 403);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($admin, $data, $request): void {
                $this->persistRajaOngkirSettings($admin, $data, $request);
            });

            return redirect()->route('admin.settings.edit')
                ->with('active_tab', 'rajaongkir')
                ->with('success', 'Pengaturan integrasi RajaOngkir berhasil disimpan.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui pengaturan integrasi RajaOngkir oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->with('active_tab', 'rajaongkir')->withErrors(['error' => 'Terjadi kesalahan saat menyimpan pengaturan integrasi RajaOngkir.']);
        }
    }

    public function checkMidtrans(): JsonResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.integrations'), 403);

        $result = $this->healthCheckService->checkMidtrans();

        return response()->json($result, 200);
    }

    public function checkRajaOngkir(): JsonResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.integrations'), 403);

        $result = $this->healthCheckService->checkRajaOngkir();

        return response()->json($result, 200);
    }

    public function updateIntegrations(UpdateIntegrationSettingsRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('settings.integrations'), 403);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($admin, $data, $request): void {
                $midtransMeta = $this->persistMidtransSettings($admin, $data, $request, recordAudit: false);
                $rajaOngkirMeta = $this->persistRajaOngkirSettings($admin, $data, $request, recordAudit: false);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'settings.integrations_updated',
                    'Memperbarui pengaturan integrasi Midtrans & RajaOngkir.',
                    null,
                    array_merge($midtransMeta, $rajaOngkirMeta),
                    $request
                );
            });

            return redirect()->route('admin.settings.edit')
                ->with('success', 'Pengaturan integrasi Midtrans & RajaOngkir berhasil diperbarui.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui pengaturan integrasi oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan pengaturan integrasi.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    protected function persistMidtransSettings(User $admin, array $data, Request $request, bool $recordAudit = true): array
    {
        $this->settingService->set('midtrans', 'is_active', (bool) $data['midtrans_is_active'], 'boolean', false);
        $this->settingService->set('midtrans', 'is_production', (bool) $data['midtrans_is_production'], 'boolean', false);

        $midtransServerKeyUpdated = false;
        if ((bool) $data['clear_midtrans_db_credentials']) {
            $this->settingService->set('midtrans', 'merchant_id', '', 'string', false);
            $this->settingService->set('midtrans', 'client_key', '', 'string', false);
            $this->settingService->set('midtrans', 'server_key', '', 'encrypted', false);
        } else {
            $this->settingService->set('midtrans', 'merchant_id', $data['midtrans_merchant_id'] ?? '', 'string', false);
            $this->settingService->set('midtrans', 'client_key', $data['midtrans_client_key'] ?? '', 'string', false);

            if (($data['midtrans_server_key'] ?? '') !== '') {
                $this->settingService->set('midtrans', 'server_key', $data['midtrans_server_key'], 'encrypted', false);
                $midtransServerKeyUpdated = true;
            }
        }

        $meta = [
            'midtrans_is_active' => (bool) $data['midtrans_is_active'],
            'midtrans_is_production' => (bool) $data['midtrans_is_production'],
            'midtrans_server_key_updated' => $midtransServerKeyUpdated,
            'midtrans_credentials_cleared' => (bool) $data['clear_midtrans_db_credentials'],
        ];

        if ($recordAudit) {
            AuditLogService::record(
                'user',
                $admin->id,
                'settings.midtrans_updated',
                'Memperbarui pengaturan integrasi Midtrans.',
                null,
                $meta,
                $request
            );
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    protected function persistRajaOngkirSettings(User $admin, array $data, Request $request, bool $recordAudit = true): array
    {
        $this->settingService->set('rajaongkir', 'is_active', (bool) $data['rajaongkir_is_active'], 'boolean', false);
        $this->settingService->set('rajaongkir', 'timeout', (int) $data['rajaongkir_timeout'], 'integer', false);

        $rajaOngkirApiKeyUpdated = false;
        if ((bool) $data['clear_rajaongkir_db_credentials']) {
            $this->settingService->set('rajaongkir', 'base_url', '', 'string', false);
            $this->settingService->set('rajaongkir', 'origin_id', '', 'string', false);
            $this->settingService->set('rajaongkir', 'api_key', '', 'encrypted', false);
        } else {
            $this->settingService->set('rajaongkir', 'base_url', $data['rajaongkir_base_url'] ?? '', 'string', false);
            $this->settingService->set('rajaongkir', 'origin_id', $data['rajaongkir_origin_id'] ?? '', 'string', false);

            if (($data['rajaongkir_api_key'] ?? '') !== '') {
                $this->settingService->set('rajaongkir', 'api_key', $data['rajaongkir_api_key'], 'encrypted', false);
                $rajaOngkirApiKeyUpdated = true;
            }
        }

        $meta = [
            'rajaongkir_is_active' => (bool) $data['rajaongkir_is_active'],
            'rajaongkir_api_key_updated' => $rajaOngkirApiKeyUpdated,
            'rajaongkir_credentials_cleared' => (bool) $data['clear_rajaongkir_db_credentials'],
        ];

        if ($recordAudit) {
            AuditLogService::record(
                'user',
                $admin->id,
                'settings.rajaongkir_updated',
                'Memperbarui pengaturan integrasi RajaOngkir.',
                null,
                $meta,
                $request
            );
        }

        return $meta;
    }
}
