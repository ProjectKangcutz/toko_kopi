<?php

namespace App\Console\Commands;

use App\Services\Payment\MidtransClient;
use App\Services\Payment\MidtransPaymentException;
use App\Services\Setting\StoreSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckMidtransConfigCommand extends Command
{
    protected $signature = 'midtrans:check
                            {--no-ping : Hanya periksa konfigurasi lokal tanpa menghubungi API Midtrans}';

    protected $description = 'Memeriksa konfigurasi Midtrans (Sandbox/Production) dan menguji koneksi ke server Midtrans secara aman';

    public function handle(StoreSettingService $settingService, MidtransClient $midtransClient): int
    {
        $db = $settingService->allDecoded()['midtrans'] ?? [];
        $config = $settingService->midtransConfig();

        $dbClientKey = trim((string) ($db['client_key'] ?? ''));
        $dbServerKey = trim((string) ($db['server_key'] ?? ''));
        $clientKey = trim((string) ($config['client_key'] ?? ''));
        $serverKey = trim((string) ($config['server_key'] ?? ''));
        $isActive = (bool) ($config['is_active'] ?? false);
        $isProduction = (bool) ($config['is_production'] ?? false);

        $this->line('Status aktif   : '.($isActive ? 'AKTIF' : 'NONAKTIF'));
        $this->line('Mode           : '.($isProduction ? 'PRODUCTION' : 'SANDBOX'));
        $this->line('Sumber key     : '.(($dbClientKey !== '' || $dbServerKey !== '') ? 'database settings' : '.env / config'));
        $this->line('Merchant ID    : '.(($config['merchant_id'] ?? null) ?: '(kosong)'));
        $this->line('Client Key     : '.$this->maskKey($clientKey));
        $this->line('Server Key     : '.$this->maskKey($serverKey));
        $this->line('Snap API URL   : '.$midtransClient->snapApiBaseUrl($isProduction).'/snap/v1/transactions');
        $this->line('Core API URL   : '.$midtransClient->coreApiBaseUrl($isProduction).'/v2/{order_id}/status');

        $warnings = $this->detectConfigWarnings($clientKey, $serverKey, $isActive, $isProduction);
        foreach ($warnings as $warning) {
            $this->warn('Peringatan: '.$warning);
        }

        if (! $isActive || $clientKey === '' || $serverKey === '') {
            $this->error('Konfigurasi Midtrans belum aktif atau kredensial belum lengkap.');

            return self::FAILURE;
        }

        if ($this->option('no-ping')) {
            $this->info('Pemeriksaan konfigurasi lokal selesai (--no-ping).');

            return $warnings === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('Menguji autentikasi & koneksi ke Midtrans Core API...');

        try {
            $midtransClient->getTransactionStatus('PLN-HEALTHCHECK-PROBE');
            $this->info('Koneksi dan autentikasi Midtrans berhasil diverifikasi.');

            return self::SUCCESS;
        } catch (MidtransPaymentException $e) {
            $diagnostics = $e->diagnostics();
            $category = (string) ($diagnostics['category'] ?? '');
            $httpStatus = $diagnostics['http_status'] ?? null;
            $detail = (string) ($diagnostics['upstream_message'] ?? $e->getMessage());

            // HTTP 404 pada order probe berarti autentikasi Basic Auth Server Key & TLS berhasil lolos.
            if ($category === 'upstream_not_found' && (int) $httpStatus === 404) {
                $this->info('Koneksi TLS & autentikasi Server Key Midtrans VALID (API merespons HTTP 404 untuk order probe).');

                return self::SUCCESS;
            }

            $this->error('Gagal terhubung atau terautentikasi ke Midtrans.');
            $this->line('  Error Code  : '.$e->errorCode());
            $this->line('  Kategori    : '.($category !== '' ? $category : '-'));
            if ($httpStatus !== null) {
                $this->line('  HTTP Status : '.$httpStatus);
            }
            $this->line('  Detail      : '.$detail);

            $hint = $this->buildHint($category, $isProduction);
            if ($hint !== null) {
                $this->newLine();
                $this->comment('Saran: '.$hint);
            }

            return self::FAILURE;
        }
    }

    protected function maskKey(string $key): string
    {
        if ($key === '') {
            return '(kosong)';
        }

        $length = strlen($key);
        $prefix = Str::limit($key, min(14, max(4, $length - 4)), '');

        return $prefix.'*** ('.$length.' karakter)';
    }

    /**
     * @return array<int, string>
     */
    protected function detectConfigWarnings(string $clientKey, string $serverKey, bool $isActive, bool $isProduction): array
    {
        $warnings = [];

        if (! $isActive) {
            $warnings[] = 'midtrans.is_active bernilai nonaktif.';
        }

        if ($clientKey !== '' && (str_starts_with($clientKey, 'SB-Mid-server-') || str_starts_with($clientKey, 'Mid-server-'))) {
            $warnings[] = 'MIDTRANS_CLIENT_KEY berisi Server Key (tertukar). Gunakan key yang diawali SB-Mid-client- / Mid-client-.';
        }

        if ($serverKey !== '' && (str_starts_with($serverKey, 'SB-Mid-client-') || str_starts_with($serverKey, 'Mid-client-'))) {
            $warnings[] = 'MIDTRANS_SERVER_KEY berisi Client Key (tertukar). Gunakan key yang diawali SB-Mid-server- / Mid-server-.';
        }

        if ($serverKey !== '') {
            $isSandboxKey = str_starts_with($serverKey, 'SB-Mid-');
            if ($isSandboxKey && $isProduction) {
                $warnings[] = 'Server Key adalah key Sandbox (SB-Mid-...), tetapi mode saat ini PRODUCTION (MIDTRANS_IS_PRODUCTION=true).';
            } elseif (! $isSandboxKey && str_starts_with($serverKey, 'Mid-') && ! $isProduction) {
                $warnings[] = 'Server Key adalah key Production (Mid-...), tetapi mode saat ini SANDBOX (MIDTRANS_IS_PRODUCTION=false).';
            }
        }

        return $warnings;
    }

    protected function buildHint(string $category, bool $isProduction): ?string
    {
        return match ($category) {
            'upstream_auth_error' => $isProduction
                ? 'Server Key ditolak oleh Midtrans Production (401/403). Pastikan MIDTRANS_SERVER_KEY adalah Server Key Production (bukan Sandbox SB-Mid-server- atau Client Key).'
                : 'Server Key ditolak oleh Midtrans Sandbox (401/403). Pastikan MIDTRANS_SERVER_KEY berasal dari Sandbox > Settings > Access Keys (diawali SB-Mid-server-) dan tidak tertukar dengan Client Key.',
            'connection_or_timeout' => 'Koneksi TLS/HTTPS gagal atau timeout. Jika muncul cURL error 60 di Laragon/Windows, pastikan cacert.pem terpasang di php.ini (curl.cainfo & openssl.cafile).',
            default => 'Periksa log detail terbaru di storage/logs/payment-*.log untuk melihat respons lengkap dari Midtrans.',
        };
    }
}
