<?php

namespace App\Services\Setting;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class StoreSettingService
{
    public const CACHE_KEY = 'store_settings_all';

    public function allDecoded(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $grouped = [];
            foreach (Setting::all() as $setting) {
                $grouped[$setting->group][$setting->key] = $setting->decodedValue();
            }

            return $grouped;
        });
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->allDecoded();

        return $all[$group][$key] ?? $default;
    }

    public function set(string $group, string $key, mixed $value, string $type = 'string', bool $isPublic = false): Setting
    {
        $setting = Setting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => Setting::encodeValue($value, $type),
                'type' => $type,
                'is_public' => $isPublic,
            ]
        );

        Cache::forget(self::CACHE_KEY);

        return $setting;
    }

    /**
     * Hanya mengembalikan pengaturan yang aman ditampilkan di browser.
     * Kredensial rahasia (Server Key Midtrans, API Key RajaOngkir) tidak pernah masuk sini.
     */
    public function publicSettings(): array
    {
        $all = $this->allDecoded();

        return [
            'store_name' => $all['general']['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara'),
            'store_tagline' => $all['general']['store_tagline'] ?? 'Produk UMKM Pilihan Asli Nusantara',
            'store_description' => $all['general']['store_description'] ?? 'Belanja langsung produk UMKM lokal berkualitas.',
            'store_address' => $all['general']['store_address'] ?? '',
            'store_phone' => $all['general']['store_phone'] ?? '',
            'store_email' => $all['general']['store_email'] ?? '',
            'store_whatsapp' => $all['general']['store_whatsapp'] ?? '',
            'store_hours' => $all['general']['store_hours'] ?? 'Senin - Sabtu, 08:00 - 17:00 WIB',
            'social_instagram' => $all['general']['social_instagram'] ?? '',
            'social_facebook' => $all['general']['social_facebook'] ?? '',
            'social_tiktok' => $all['general']['social_tiktok'] ?? '',
            'social_youtube' => $all['general']['social_youtube'] ?? '',
            'contact_additional_info' => $all['general']['contact_additional_info'] ?? '',
            'logo_path' => $all['general']['logo_path'] ?? null,
            'favicon_path' => $all['general']['favicon_path'] ?? null,
            'currency' => $all['general']['currency'] ?? 'IDR',
            'primary_color' => $all['appearance']['primary_color'] ?? '#245C45',
            'secondary_color' => $all['appearance']['secondary_color'] ?? '#E9F1E9',
            'accent_color' => $all['appearance']['accent_color'] ?? '#E9A23B',
            'promo_bar_text' => $all['appearance']['promo_bar_text'] ?? null,
            'guest_checkout_enabled' => (bool) ($all['checkout']['guest_checkout_enabled'] ?? true),
        ];
    }

    public function aboutSettings(): array
    {
        $all = $this->allDecoded();

        return [
            'subtitle' => (string) ($all['general']['about_subtitle'] ?? ''),
            'banner_path' => ($all['appearance']['about_banner_path'] ?? null) ?: null,
            'vision' => (string) ($all['general']['about_vision'] ?? ''),
            'mission' => (string) ($all['general']['about_mission'] ?? ''),
            'business_profile' => (string) ($all['general']['about_business_profile'] ?? ''),
        ];
    }

    public function contactSettings(): array
    {
        $all = $this->allDecoded();

        return [
            'store_name' => (string) ($all['general']['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara')),
            'store_address' => (string) ($all['general']['store_address'] ?? ''),
            'store_phone' => (string) ($all['general']['store_phone'] ?? ''),
            'store_whatsapp' => (string) ($all['general']['store_whatsapp'] ?? ''),
            'store_email' => (string) ($all['general']['store_email'] ?? ''),
            'store_hours' => (string) ($all['general']['store_hours'] ?? 'Senin - Sabtu, 08:00 - 17:00 WIB'),
            'social_instagram' => (string) ($all['general']['social_instagram'] ?? ''),
            'social_facebook' => (string) ($all['general']['social_facebook'] ?? ''),
            'social_tiktok' => (string) ($all['general']['social_tiktok'] ?? ''),
            'social_youtube' => (string) ($all['general']['social_youtube'] ?? ''),
            'contact_additional_info' => (string) ($all['general']['contact_additional_info'] ?? ''),
        ];
    }

    public function midtransConfig(): array
    {
        $db = $this->allDecoded()['midtrans'] ?? [];

        $dbClientKey = trim((string) ($db['client_key'] ?? ''));
        $dbServerKey = trim((string) ($db['server_key'] ?? ''));
        $dbMerchantId = trim((string) ($db['merchant_id'] ?? ''));

        $envClientKey = trim((string) config('services.midtrans.client_key', ''));
        $envServerKey = trim((string) config('services.midtrans.server_key', ''));
        $envMerchantId = trim((string) config('services.midtrans.merchant_id', ''));

        $hasDbCredentials = $dbClientKey !== '' || $dbServerKey !== '';
        $hasEnvCredentials = $envClientKey !== '' && $envServerKey !== '';

        $rawEnvIsActive = config('services.midtrans.is_active');
        $hasExplicitEnvActive = $rawEnvIsActive !== null && $rawEnvIsActive !== '';

        if ($hasDbCredentials) {
            // Jika kredensial tersimpan di database settings, hormati toggle is_active dan mode dari database.
            $isActive = (bool) ($db['is_active'] ?? false);
            $isProduction = (bool) ($db['is_production'] ?? config('services.midtrans.is_production', false));
        } elseif ((bool) ($db['is_active'] ?? false)) {
            $isActive = true;
            $isProduction = (bool) config('services.midtrans.is_production', false)
                || (bool) ($db['is_production'] ?? false);
        } elseif ($hasExplicitEnvActive) {
            $isActive = filter_var($rawEnvIsActive, FILTER_VALIDATE_BOOLEAN);
            $isProduction = (bool) config('services.midtrans.is_production', false);
        } else {
            // Jika DB masih kosong (default SettingSeeder), aktifkan otomatis bila .env sudah mengisi Client Key & Server Key.
            $isActive = $hasEnvCredentials;
            $isProduction = (bool) config('services.midtrans.is_production', false);
        }

        return [
            'is_active' => $isActive,
            'is_production' => $isProduction,
            'merchant_id' => $dbMerchantId !== '' ? $dbMerchantId : ($envMerchantId !== '' ? $envMerchantId : null),
            'client_key' => $dbClientKey !== '' ? $dbClientKey : ($envClientKey !== '' ? $envClientKey : null),
            'server_key' => $dbServerKey !== '' ? $dbServerKey : ($envServerKey !== '' ? $envServerKey : null),
            'is_sanitized' => (bool) ($db['is_sanitized'] ?? config('services.midtrans.is_sanitized', true)),
            'is_3ds' => (bool) ($db['is_3ds'] ?? config('services.midtrans.is_3ds', true)),
        ];
    }

    public function rajaOngkirConfig(): array
    {
        $db = $this->allDecoded()['rajaongkir'] ?? [];
        $dbBaseUrl = trim((string) ($db['base_url'] ?? ''));

        return [
            'is_active' => (bool) ($db['is_active'] ?? false),
            'base_url' => rtrim((string) ($dbBaseUrl !== '' ? $dbBaseUrl : config('services.rajaongkir.base_url')), '/'),
            'api_key' => ($db['api_key'] ?? null) ?: config('services.rajaongkir.api_key'),
            'origin_id' => ($db['origin_id'] ?? null) ?: config('services.rajaongkir.origin_id'),
            'timeout' => (int) (($db['timeout'] ?? null) ?: config('services.rajaongkir.timeout', 10)),
        ];
    }
}
