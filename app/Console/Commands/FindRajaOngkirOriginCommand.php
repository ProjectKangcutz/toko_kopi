<?php

namespace App\Console\Commands;

use App\Services\Setting\StoreSettingService;
use App\Services\Shipping\KomerceRegionClient;
use App\Services\Shipping\RegionLookupException;
use Illuminate\Console\Command;

class FindRajaOngkirOriginCommand extends Command
{
    protected $signature = 'rajaongkir:find-origin
                            {keyword : Nama kota, kecamatan, atau kode pos}
                            {--limit=15 : Maksimal hasil yang ditampilkan}';

    protected $description = 'Mencari ID lokasi asal pengiriman via RajaOngkir Shipping Cost API';

    public function handle(KomerceRegionClient $client, StoreSettingService $settingService): int
    {
        $keyword = (string) $this->argument('keyword');
        $limit = max(1, min(50, (int) $this->option('limit')));

        $config = $settingService->rajaOngkirConfig();
        $dbSettings = $settingService->allDecoded()['rajaongkir'] ?? [];
        $hasDbBaseUrl = trim((string) ($dbSettings['base_url'] ?? '')) !== '';
        $hasDbApiKey = trim((string) ($dbSettings['api_key'] ?? '')) !== '';
        $hasAnyApiKey = trim((string) ($config['api_key'] ?? '')) !== '';
        $isCollaborator = $config['base_url'] !== '' && KomerceRegionClient::isCollaboratorHost($config['base_url']);

        $this->line('Base URL aktif : '.($config['base_url'] !== '' ? $config['base_url'] : '(kosong)')
            .' (sumber: '.($hasDbBaseUrl ? 'database settings' : '.env / config').')');
        $this->line('Mode API       : '.($isCollaborator ? 'Komerce Collaborator (header: x-api-key)' : 'RajaOngkir V2 (header: key)'));
        $this->line('API Key        : '.($hasAnyApiKey
            ? 'terkonfigurasi (sumber: '.($hasDbApiKey ? 'database settings' : '.env / config').')'
            : 'kosong'));
        $this->line('Timeout        : '.max(2, min(30, (int) $config['timeout'])).' detik');

        try {
            $results = $client->searchDestinations($keyword, $limit);
        } catch (RegionLookupException $e) {
            $diagnostics = $e->diagnostics();

            $this->error($e->getMessage());
            $this->newLine();
            $this->warn('Diagnostik Aman:');
            $this->line('  Error Code      : '.$e->errorCode());
            $this->line('  Kategori        : '.$diagnostics['category']);

            if ($diagnostics['http_status'] !== null) {
                $this->line('  HTTP Status     : '.$diagnostics['http_status']);
            }

            if ($diagnostics['meta_code'] !== null) {
                $this->line('  Meta Code       : '.$diagnostics['meta_code']);
            }

            if ($diagnostics['endpoint'] !== null) {
                $this->line('  Endpoint        : '.$diagnostics['endpoint']);
            }

            if ($diagnostics['auth_header'] !== null) {
                $this->line('  Header Auth     : '.$diagnostics['auth_header']);
            }

            if ($diagnostics['exception_class'] !== null) {
                $this->line('  Exception Class : '.$diagnostics['exception_class']);
            }

            if ($diagnostics['upstream_message'] !== null) {
                $this->line('  Detail          : '.$diagnostics['upstream_message']);
            }

            $hint = $this->diagnosticHint($diagnostics['category'], $hasDbBaseUrl, $isCollaborator);
            if ($hint !== null) {
                $this->newLine();
                $this->comment('Saran: '.$hint);
            }

            return self::FAILURE;
        }

        if ($results === []) {
            $this->warn('Tidak ditemukan wilayah untuk kata kunci: '.$keyword);

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $item): array => [
            $item['id'],
            $item['province_name'] ?? '-',
            $item['city_name'] ?? '-',
            $item['district_name'] ?? '-',
            $item['subdistrict_name'] ?? '-',
            $item['zip_code'] ?? '-',
            $item['label'],
        ], $results);

        $this->table(
            ['Origin ID', 'Provinsi', 'Kota/Kab', 'Kecamatan', 'Kelurahan', 'Kode Pos', 'Label'],
            $rows
        );

        $firstId = $results[0]['id'];
        $this->info('Salin ID kelurahan/kecamatan asal toko ke .env Anda, misalnya:');
        $this->line('RAJAONGKIR_ORIGIN_ID='.$firstId);

        return self::SUCCESS;
    }

    protected function diagnosticHint(string $category, bool $hasDbBaseUrl, bool $isCollaborator): ?string
    {
        return match ($category) {
            'upstream_auth_error' => $isCollaborator
                ? 'API Key ditolak oleh Komerce Collaborator Sandbox (401/403). Pastikan API Key berasal dari menu Shipping Delivery / Collaborator Sandbox (bukan key Shipping Cost) dan masih aktif.'
                : 'API Key ditolak oleh RajaOngkir V2 (401/403). Pastikan API Key adalah key Shipping Cost yang valid.',
            'upstream_not_found' => $hasDbBaseUrl
                ? 'Endpoint tidak ditemukan (404) dan base_url saat ini masih berasal dari tabel database settings. Jika Anda mengubah .env, jalankan php artisan db:seed --class=SettingSeeder && php artisan optimize:clear.'
                : 'Endpoint atau data tidak ditemukan (404). Periksa kembali RAJAONGKIR_BASE_URL (https://komerce.id) dan coba kata kunci lain (misal nama kota/kecamatan).',
            'connection_or_timeout' => 'Koneksi gagal atau timeout. Periksa akses internet lokal, DNS, atau konfigurasi SSL/CA bundle PHP (cURL error 60 di Laragon/Windows).',
            'unconfigured' => 'Periksa RAJAONGKIR_API_KEY, RAJAONGKIR_BASE_URL di .env (lalu jalankan php artisan optimize:clear), atau status is_active pada tabel settings.',
            default => null,
        };
    }
}