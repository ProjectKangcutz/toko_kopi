<?php

namespace App\Services\Setting;

use App\Services\Payment\MidtransClient;
use App\Services\Shipping\KomerceRegionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class IntegrationHealthCheckService
{
    public function __construct(
        protected StoreSettingService $settingService,
        protected MidtransClient $midtransClient,
    ) {}

    /**
     * Memeriksa konektivitas dan validitas kredensial Midtrans tanpa membuat transaksi nyata.
     * server_key tidak pernah dikembalikan dalam hasil ini.
     *
     * @return array<string, mixed>
     */
    public function checkMidtrans(): array
    {
        $config = $this->settingService->midtransConfig();
        $isActive = (bool) ($config['is_active'] ?? false);
        $isProduction = (bool) ($config['is_production'] ?? false);
        $environment = $isProduction ? 'Production' : 'Sandbox';
        $clientKey = trim((string) ($config['client_key'] ?? ''));
        $serverKey = trim((string) ($config['server_key'] ?? ''));
        $merchantId = trim((string) ($config['merchant_id'] ?? ''));

        $baseDetails = [
            'is_active' => $isActive,
            'environment' => $environment,
            'has_merchant_id' => $merchantId !== '',
            'has_client_key' => $clientKey !== '',
            'has_server_key' => $serverKey !== '',
            'checked_at' => now('Asia/Jakarta')->format('d M Y, H:i:s').' WIB',
        ];

        if ($serverKey === '' || $clientKey === '') {
            $missing = [];
            if ($clientKey === '') {
                $missing[] = 'Client Key';
            }
            if ($serverKey === '') {
                $missing[] = 'Server Key';
            }

            return [
                'success' => false,
                'status' => 'unconfigured',
                'message' => 'Konfigurasi Midtrans belum lengkap ('.implode(' dan ', $missing).' belum diisi).',
                'details' => $baseDetails,
            ];
        }

        $warnings = [];
        if (! $isActive) {
            $warnings[] = 'Toggle Aktifkan Midtrans masih nonaktif, sehingga pembayaran belum tersedia di checkout.';
        }
        if ($isProduction && str_starts_with($serverKey, 'SB-')) {
            $warnings[] = 'Mode Production aktif, tetapi Server Key diawali prefix Sandbox (SB-).';
        } elseif (! $isProduction && ! str_starts_with($serverKey, 'SB-') && str_starts_with($serverKey, 'Mid-')) {
            $warnings[] = 'Mode Sandbox aktif, tetapi Server Key tidak diawali prefix Sandbox (SB-).';
        }

        $coreBaseUrl = $this->midtransClient->coreApiBaseUrl($isProduction);
        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl($coreBaseUrl)
                ->acceptJson()
                ->withBasicAuth($serverKey, '')
                ->connectTimeout(5)
                ->timeout(10)
                ->get('/v2/PLN-PING-HEALTHCHECK/status');
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'status' => 'timeout',
                'message' => $this->midtransClient->sanitizeDiagnosticMessage($e->getMessage(), $serverKey)
                    ?? "Koneksi ke server Midtrans ({$environment}) gagal atau timeout.",
                'details' => array_merge($baseDetails, [
                    'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                    'warnings' => $warnings,
                ]),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $this->midtransClient->sanitizeDiagnosticMessage($e->getMessage(), $serverKey)
                    ?? 'Terjadi kesalahan saat menghubungi API Midtrans.',
                'details' => array_merge($baseDetails, [
                    'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                    'warnings' => $warnings,
                ]),
            ];
        }

        $latencyMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $httpStatus = $response->status();
        $decoded = $response->json();

        if (in_array($httpStatus, [401, 403], true)) {
            return [
                'success' => false,
                'status' => 'auth_error',
                'message' => "Autentikasi Midtrans ({$environment}) ditolak (HTTP {$httpStatus}). Pastikan Server Key valid dan sesuai dengan mode {$environment}.",
                'details' => array_merge($baseDetails, [
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'warnings' => $warnings,
                ]),
            ];
        }

        // Midtrans Core API mengembalikan HTTP 404 JSON "Transaction doesn't exist." ketika Server Key valid.
        if ($response->successful() || ($httpStatus === 404 && is_array($decoded))) {
            return [
                'success' => true,
                'status' => 'connected',
                'message' => "Koneksi & autentikasi Server Key Midtrans ({$environment}) berhasil diverifikasi.",
                'details' => array_merge($baseDetails, [
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'warnings' => $warnings,
                ]),
            ];
        }

        $upstreamMessage = is_array($decoded)
            ? ($decoded['status_message'] ?? $decoded['message'] ?? null)
            : null;

        return [
            'success' => false,
            'status' => 'upstream_error',
            'message' => $this->midtransClient->sanitizeDiagnosticMessage(
                is_string($upstreamMessage) ? $upstreamMessage : "Midtrans ({$environment}) mengembalikan HTTP {$httpStatus}.",
                $serverKey
            ) ?? "Midtrans ({$environment}) mengembalikan HTTP {$httpStatus}.",
            'details' => array_merge($baseDetails, [
                'http_status' => $httpStatus,
                'latency_ms' => $latencyMs,
                'warnings' => $warnings,
            ]),
        ];
    }

    /**
     * Memeriksa konektivitas dan validitas API Key RajaOngkir / Komerce tanpa mengekspos API Key.
     *
     * @return array<string, mixed>
     */
    public function checkRajaOngkir(): array
    {
        $config = $this->settingService->rajaOngkirConfig();
        $isActive = (bool) ($config['is_active'] ?? false);
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $originId = trim((string) ($config['origin_id'] ?? ''));
        $timeout = max(2, min(30, (int) ($config['timeout'] ?? 10)));

        $isCollaborator = $baseUrl !== '' && KomerceRegionClient::isCollaboratorHost($baseUrl);
        $providerLabel = $isCollaborator ? 'Komerce Collaborator Sandbox' : 'RajaOngkir V2';

        $baseDetails = [
            'is_active' => $isActive,
            'provider' => $providerLabel,
            'base_url' => $baseUrl !== '' ? $baseUrl : '-',
            'origin_id' => $originId !== '' ? $originId : null,
            'has_api_key' => $apiKey !== '',
            'checked_at' => now('Asia/Jakarta')->format('d M Y, H:i:s').' WIB',
        ];

        if ($apiKey === '') {
            return [
                'success' => false,
                'status' => 'unconfigured',
                'message' => 'API Key RajaOngkir belum diisi di database maupun .env.',
                'details' => $baseDetails,
            ];
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return [
                'success' => false,
                'status' => 'unconfigured',
                'message' => 'Base URL RajaOngkir kosong atau bukan URL HTTP/HTTPS yang valid.',
                'details' => $baseDetails,
            ];
        }

        $warnings = [];
        if (! $isActive) {
            $warnings[] = 'Toggle Aktifkan Cek Ongkir Live masih nonaktif; checkout masih memakai tarif cadangan lokal.';
        }
        if ($originId === '' && ! $isCollaborator) {
            $warnings[] = 'Origin ID gudang belum diisi. Perhitungan ongkir live memerlukan Origin ID yang valid.';
        }
        if ($isCollaborator) {
            $warnings[] = 'Host Collaborator Sandbox mendukung pencarian wilayah, tetapi endpoint hitung tarif V2 memerlukan host RajaOngkir V2.';
        }

        $headerName = $isCollaborator ? 'x-api-key' : 'key';
        $path = trim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        $endpoint = $isCollaborator
            ? (str_ends_with($path, 'tariff/api/v1') ? 'destination/search' : 'tariff/api/v1/destination/search')
            : 'destination/domestic-destination';
        $query = $isCollaborator
            ? ['keyword' => 'jakarta']
            : ['search' => 'jakarta', 'limit' => 3, 'offset' => 0];

        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withHeaders([$headerName => $apiKey])
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->get($endpoint, $query);
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'status' => 'timeout',
                'message' => $this->sanitizeKeyMessage($e->getMessage(), $apiKey)
                    ?? "Koneksi ke {$providerLabel} gagal atau timeout.",
                'details' => array_merge($baseDetails, [
                    'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                    'warnings' => $warnings,
                ]),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $this->sanitizeKeyMessage($e->getMessage(), $apiKey)
                    ?? "Terjadi kesalahan saat menghubungi {$providerLabel}.",
                'details' => array_merge($baseDetails, [
                    'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                    'warnings' => $warnings,
                ]),
            ];
        }

        $latencyMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $httpStatus = $response->status();
        $decoded = $response->json();

        $metaCode = is_array($decoded) && is_numeric($decoded['meta']['code'] ?? null)
            ? (int) $decoded['meta']['code']
            : null;
        $metaStatus = is_array($decoded) && is_string($decoded['meta']['status'] ?? null)
            ? strtolower(trim($decoded['meta']['status']))
            : null;
        $upstreamMsg = is_array($decoded)
            ? ($decoded['meta']['message'] ?? $decoded['message'] ?? $decoded['error'] ?? null)
            : null;

        if (in_array($httpStatus, [401, 403], true)) {
            return [
                'success' => false,
                'status' => 'auth_error',
                'message' => "Autentikasi API Key {$providerLabel} ditolak (HTTP {$httpStatus}). Periksa kembali API Key dan Base URL.",
                'details' => array_merge($baseDetails, [
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'warnings' => $warnings,
                ]),
            ];
        }

        if ($response->failed() || ($metaCode !== null && $metaCode >= 400) || $metaStatus === 'error') {
            $cleanedMsg = $this->sanitizeKeyMessage(is_string($upstreamMsg) ? $upstreamMsg : null, $apiKey);

            return [
                'success' => false,
                'status' => 'upstream_error',
                'message' => $cleanedMsg ?? "{$providerLabel} mengembalikan error (HTTP {$httpStatus}).",
                'details' => array_merge($baseDetails, [
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'warnings' => $warnings,
                ]),
            ];
        }

        $dataList = is_array($decoded['data'] ?? null) ? $decoded['data'] : null;
        if (! is_array($decoded) || $dataList === null) {
            return [
                'success' => false,
                'status' => 'malformed_response',
                'message' => "Respons {$providerLabel} tidak memiliki struktur data wilayah yang diharapkan.",
                'details' => array_merge($baseDetails, [
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'warnings' => $warnings,
                ]),
            ];
        }

        return [
            'success' => true,
            'status' => 'connected',
            'message' => "Koneksi ke {$providerLabel} berhasil diverifikasi (".count($dataList).' sampel wilayah diterima).',
            'details' => array_merge($baseDetails, [
                'http_status' => $httpStatus,
                'latency_ms' => $latencyMs,
                'sample_count' => count($dataList),
                'warnings' => $warnings,
            ]),
        ];
    }

    protected function sanitizeKeyMessage(?string $message, string $apiKey): ?string
    {
        if ($message === null) {
            return null;
        }

        $cleaned = trim($message);
        if ($cleaned === '') {
            return null;
        }

        if ($apiKey !== '') {
            $cleaned = str_replace([$apiKey, urlencode($apiKey), rawurlencode($apiKey)], '[REDACTED]', $cleaned);
        }

        $cleaned = preg_replace(
            '/\b(x-api-key|api[_-]?key|key|authorization|bearer|token|secret)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $cleaned
        ) ?? $cleaned;

        $cleaned = preg_replace('/[\r\n\t]+/', ' ', $cleaned) ?? $cleaned;

        return mb_substr(trim($cleaned), 0, 200);
    }
}
