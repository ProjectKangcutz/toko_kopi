<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // A. General Settings
            ['general', 'store_name', 'Pasar Lokal Nusantara', 'string', true],
            ['general', 'store_tagline', 'Produk UMKM Pilihan Langsung dari Pengrajin & Produsen Lokal', 'string', true],
            ['general', 'store_description', 'Online shop UMKM terpercaya dengan produk kuliner, kriya, dan kebutuhan harian berkualitas.', 'string', true],
            ['general', 'store_address', 'Jl. Merdeka No. 45, Bandung, Jawa Barat 40115', 'string', true],
            ['general', 'store_phone', '(022) 555-0192', 'string', true],
            ['general', 'store_email', 'halo@pasarlokal.test', 'string', true],
            ['general', 'store_whatsapp', '6281200000001', 'string', true],
            ['general', 'store_hours', 'Senin - Sabtu, 08:00 - 17:00 WIB', 'string', true],
            ['general', 'currency', 'IDR', 'string', true],
            ['general', 'timezone', 'Asia/Jakarta', 'string', false],
            ['general', 'order_number_prefix', 'PLN-', 'string', false],

            // B. Store Settings
            ['store', 'warehouse_address', 'Gudang Utama Jl. Merdeka No. 45, Bandung', 'string', false],
            ['store', 'origin_city_name', 'Kota Bandung, Jawa Barat', 'string', true],
            ['store', 'origin_region_id', '', 'string', false],
            ['store', 'min_weight_grams', '100', 'integer', false],
            ['store', 'weight_rounding_grams', '1000', 'integer', false],
            ['store', 'min_order_amount', '0', 'decimal', true],
            ['store', 'tax_percentage', '0', 'decimal', true],
            ['store', 'store_status', 'open', 'string', true],
            ['store', 'maintenance_mode', '0', 'boolean', false],

            // C. Appearance Settings (Modern Local Market)
            ['appearance', 'primary_color', '#245C45', 'string', true],
            ['appearance', 'secondary_color', '#E9F1E9', 'string', true],
            ['appearance', 'accent_color', '#E9A23B', 'string', true],
            ['appearance', 'promo_bar_text', 'Gratis konsultasi pesanan UMKM & pengiriman aman ke seluruh Indonesia', 'string', true],

            // D. Checkout Settings
            ['checkout', 'guest_checkout_enabled', '1', 'boolean', true],
            ['checkout', 'customer_registration_enabled', '1', 'boolean', true],
            ['checkout', 'payment_expiry_minutes', '1440', 'integer', false],
            ['checkout', 'auto_cancel_expired', '1', 'boolean', false],
            ['checkout', 'stock_reservation_enabled', '1', 'boolean', false],

            // E. Notification Settings
            ['notification', 'email_enabled', '1', 'boolean', false],
            ['notification', 'notify_new_order', '1', 'boolean', false],
            ['notification', 'notify_payment_settled', '1', 'boolean', false],
            ['notification', 'notify_shipment_updated', '1', 'boolean', false],
            ['notification', 'notify_customer_message', '1', 'boolean', false],

            // F. Security Settings
            ['security', 'min_password_length', '8', 'integer', false],
            ['security', 'login_max_attempts', '5', 'integer', false],
            ['security', 'audit_log_enabled', '1', 'boolean', false],

            // G. SEO Settings
            ['seo', 'meta_title_template', '{title} | {store_name}', 'string', false],
            ['seo', 'default_meta_description', 'Belanja produk UMKM unggulan asli Indonesia dengan pembayaran aman dan pengiriman terpercaya.', 'string', false],
            ['seo', 'schema_organization_enabled', '1', 'boolean', false],

            // H. Integrations (diisi melalui Admin atau .env; secret tidak pernah hardcoded)
            ['rajaongkir', 'is_active', '0', 'boolean', false],
            ['rajaongkir', 'base_url', '', 'string', false],
            ['rajaongkir', 'api_key', '', 'encrypted', false],
            ['rajaongkir', 'origin_id', '', 'string', false],
            ['rajaongkir', 'timeout', '10', 'integer', false],

            ['midtrans', 'is_active', '0', 'boolean', false],
            ['midtrans', 'is_production', '0', 'boolean', false],
            ['midtrans', 'merchant_id', '', 'string', false],
            ['midtrans', 'client_key', '', 'string', false],
            ['midtrans', 'server_key', '', 'encrypted', false],
        ];

        foreach ($defaults as [$group, $key, $value, $type, $isPublic]) {
            Setting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                [
                    'value' => Setting::encodeValue($value, $type),
                    'type' => $type,
                    'is_public' => $isPublic,
                ]
            );
        }
    }
}
