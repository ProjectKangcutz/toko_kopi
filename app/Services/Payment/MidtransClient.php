<?php

namespace App\Services\Payment;

use App\Services\Setting\StoreSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MidtransClient
{
    public const SNAP_SANDBOX_BASE_URL = 'https://app.sandbox.midtrans.com';

    public const SNAP_PRODUCTION_BASE_URL = 'https://app.midtrans.com';

    public const CORE_SANDBOX_BASE_URL = 'https://api.sandbox.midtrans.com';

    public const CORE_PRODUCTION_BASE_URL = 'https://api.midtrans.com';

    public const SNAP_SANDBOX_JS_URL = 'https://app.sandbox.midtrans.com/snap/snap.js';

    public const SNAP_PRODUCTION_JS_URL = 'https://app.midtrans.com/snap/snap.js';

    public function __construct(
        protected StoreSettingService $settingService,
    ) {}

    /**
     * @return array{
     *     is_active: bool,
     *     is_production: bool,
     *     merchant_id: ?string,
     *     client_key: string,
     *     server_key: string,
     *     is_sanitized: bool,
     *     is_3ds: bool
     * }
     */
    public function resolveConfig(): array
    {
        $raw = $this->settingService->midtransConfig();

        if (! ($raw['is_active'] ?? false)) {
            throw MidtransPaymentException::inactive([
                'category' => 'integration_inactive',
            ]);
        }

        $clientKey = trim((string) ($raw['client_key'] ?? ''));
        $serverKey = trim((string) ($raw['server_key'] ?? ''));

        if ($clientKey === '' || $serverKey === '') {
            throw MidtransPaymentException::unconfigured([
                'category' => 'missing_credentials',
            ]);
        }

        $merchantId = trim((string) ($raw['merchant_id'] ?? ''));

        return [
            'is_active' => true,
            'is_production' => (bool) ($raw['is_production'] ?? false),
            'merchant_id' => $merchantId !== '' ? $merchantId : null,
            'client_key' => $clientKey,
            'server_key' => $serverKey,
            'is_sanitized' => (bool) ($raw['is_sanitized'] ?? true),
            'is_3ds' => (bool) ($raw['is_3ds'] ?? true),
        ];
    }

    /**
     * Mengembalikan konfigurasi non-rahasia untuk UI Snap.js, atau null jika belum aktif/siap.
     * server_key tidak pernah keluar dari method ini.
     *
     * @return array{is_production: bool, client_key: string, snap_js_url: string}|null
     */
    public function resolvePublicUiConfig(): ?array
    {
        try {
            $config = $this->resolveConfig();
        } catch (MidtransPaymentException) {
            return null;
        }

        return [
            'is_production' => $config['is_production'],
            'client_key' => $config['client_key'],
            'snap_js_url' => $this->snapJsUrl($config['is_production']),
        ];
    }

    public function snapApiBaseUrl(bool $isProduction): string
    {
        return $isProduction ? self::SNAP_PRODUCTION_BASE_URL : self::SNAP_SANDBOX_BASE_URL;
    }

    public function coreApiBaseUrl(bool $isProduction): string
    {
        return $isProduction ? self::CORE_PRODUCTION_BASE_URL : self::CORE_SANDBOX_BASE_URL;
    }

    public function snapJsUrl(bool $isProduction): string
    {
        return $isProduction ? self::SNAP_PRODUCTION_JS_URL : self::SNAP_SANDBOX_JS_URL;
    }

    /**
     * Membuat transaksi Midtrans Snap di server-side.
     *
     * @param  array<string, mixed>  $payload
     * @return array{token: string, redirect_url: ?string}
     */
    public function createSnapTransaction(array $payload): array
    {
        $config = $this->resolveConfig();
        $baseUrl = $this->snapApiBaseUrl($config['is_production']);
        $endpoint = '/snap/v1/transactions';
        $midtransOrderId = (string) ($payload['transaction_details']['order_id'] ?? '');

        try {
            $response = $this->buildPendingRequest($baseUrl, $config['server_key'])
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['server_key'])
                ?? 'Koneksi ke Midtrans Snap API timeout.';

            Log::channel('payment')->warning('Midtrans Snap API connection/timeout failure.', [
                'endpoint' => $endpoint,
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $midtransOrderId,
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            throw MidtransPaymentException::timeout([
                'category' => 'connection_or_timeout',
                'endpoint' => $endpoint,
                'upstream_message' => $sanitizedError,
            ]);
        } catch (Throwable $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['server_key'])
                ?? 'Kesalahan client saat menghubungi Midtrans Snap API.';

            Log::channel('payment')->warning('Midtrans Snap API unexpected client failure.', [
                'endpoint' => $endpoint,
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $midtransOrderId,
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            throw MidtransPaymentException::upstreamUnavailable([
                'category' => 'client_exception',
                'endpoint' => $endpoint,
                'upstream_message' => $sanitizedError,
            ]);
        }

        if ($response->failed()) {
            $status = $response->status();
            $sanitizedError = $this->extractSanitizedErrorMessage($response, $config['server_key'])
                ?? ('Midtrans Snap mengembalikan HTTP '.$status.'.');

            Log::channel('payment')->warning('Midtrans Snap API HTTP error.', [
                'endpoint' => $endpoint,
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $midtransOrderId,
                'http_status' => $status,
                'detail' => $sanitizedError,
            ]);

            throw MidtransPaymentException::upstreamUnavailable([
                'category' => $this->classifyHttpFailure($status),
                'http_status' => $status,
                'endpoint' => $endpoint,
                'upstream_message' => $sanitizedError,
            ]);
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            Log::channel('payment')->warning('Midtrans Snap API returned non-JSON response.', [
                'endpoint' => $endpoint,
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $midtransOrderId,
                'http_status' => $response->status(),
            ]);

            throw MidtransPaymentException::malformedResponse([
                'category' => 'malformed_non_json',
                'http_status' => $response->status(),
                'endpoint' => $endpoint,
            ]);
        }

        $token = is_string($decoded['token'] ?? null) ? trim($decoded['token']) : '';
        if ($token === '') {
            Log::channel('payment')->warning('Midtrans Snap API response missing token.', [
                'endpoint' => $endpoint,
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $midtransOrderId,
                'http_status' => $response->status(),
            ]);

            throw MidtransPaymentException::malformedResponse([
                'category' => 'missing_snap_token',
                'http_status' => $response->status(),
                'endpoint' => $endpoint,
            ]);
        }

        $redirectUrl = is_string($decoded['redirect_url'] ?? null)
            ? trim($decoded['redirect_url'])
            : '';

        return [
            'token' => $token,
            'redirect_url' => $redirectUrl !== '' ? $redirectUrl : null,
        ];
    }

    /**
     * Mengambil status transaksi terbaru langsung dari Midtrans Core API server-side.
     *
     * @return array<string, mixed>
     */
    public function getTransactionStatus(string $midtransOrderId): array
    {
        $normalizedOrderId = trim($midtransOrderId);
        if ($normalizedOrderId === '') {
            throw MidtransPaymentException::invalidOrderState('Referensi midtrans_order_id tidak boleh kosong.');
        }

        $config = $this->resolveConfig();
        $baseUrl = $this->coreApiBaseUrl($config['is_production']);
        $endpoint = '/v2/'.rawurlencode($normalizedOrderId).'/status';

        try {
            $response = $this->buildPendingRequest($baseUrl, $config['server_key'])
                ->get($endpoint);
        } catch (ConnectionException $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['server_key'])
                ?? 'Koneksi ke Midtrans Core API timeout.';

            Log::channel('payment')->warning('Midtrans status check connection/timeout failure.', [
                'endpoint' => '/v2/{order_id}/status',
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $normalizedOrderId,
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            throw MidtransPaymentException::timeout([
                'category' => 'connection_or_timeout',
                'endpoint' => '/v2/{order_id}/status',
                'upstream_message' => $sanitizedError,
            ]);
        } catch (Throwable $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['server_key'])
                ?? 'Kesalahan client saat memeriksa status transaksi Midtrans.';

            Log::channel('payment')->warning('Midtrans status check unexpected client failure.', [
                'endpoint' => '/v2/{order_id}/status',
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $normalizedOrderId,
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            throw MidtransPaymentException::upstreamUnavailable([
                'category' => 'client_exception',
                'endpoint' => '/v2/{order_id}/status',
                'upstream_message' => $sanitizedError,
            ]);
        }

        if ($response->failed()) {
            $status = $response->status();
            $sanitizedError = $this->extractSanitizedErrorMessage($response, $config['server_key'])
                ?? ('Midtrans status API mengembalikan HTTP '.$status.'.');

            Log::channel('payment')->warning('Midtrans status API HTTP error.', [
                'endpoint' => '/v2/{order_id}/status',
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $normalizedOrderId,
                'http_status' => $status,
                'detail' => $sanitizedError,
            ]);

            throw MidtransPaymentException::upstreamUnavailable([
                'category' => $this->classifyHttpFailure($status),
                'http_status' => $status,
                'endpoint' => '/v2/{order_id}/status',
                'upstream_message' => $sanitizedError,
            ]);
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            Log::channel('payment')->warning('Midtrans status API returned non-JSON response.', [
                'endpoint' => '/v2/{order_id}/status',
                'environment' => $config['is_production'] ? 'production' : 'sandbox',
                'midtrans_order_id' => $normalizedOrderId,
                'http_status' => $response->status(),
            ]);

            throw MidtransPaymentException::malformedResponse([
                'category' => 'malformed_non_json',
                'http_status' => $response->status(),
                'endpoint' => '/v2/{order_id}/status',
            ]);
        }

        return $decoded;
    }

    protected function buildPendingRequest(string $baseUrl, string $serverKey): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($serverKey, '')
            ->connectTimeout(5)
            ->timeout(15);
    }

    protected function classifyHttpFailure(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'upstream_auth_error',
            $status === 404 => 'upstream_not_found',
            in_array($status, [400, 422], true) => 'upstream_validation_error',
            $status === 429 => 'upstream_rate_limited',
            $status >= 500 => 'upstream_server_error',
            default => 'upstream_http_error',
        };
    }

    protected function extractSanitizedErrorMessage(Response $response, string $serverKey): ?string
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return 'Midtrans mengembalikan respons non-JSON (HTTP '.$response->status().').';
        }

        $candidates = [];

        if (is_array($payload['error_messages'] ?? null)) {
            foreach ($payload['error_messages'] as $msg) {
                if (is_string($msg) && trim($msg) !== '') {
                    $candidates[] = $msg;
                }
            }
        }

        foreach (['status_message', 'message', 'error'] as $key) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                $candidates[] = $payload[$key];
            }
        }

        if ($candidates === []) {
            return null;
        }

        return $this->sanitizeDiagnosticMessage(implode(' | ', $candidates), $serverKey);
    }

    public function sanitizeDiagnosticMessage(?string $message, string $serverKey = ''): ?string
    {
        if ($message === null) {
            return null;
        }

        $cleaned = trim($message);
        if ($cleaned === '') {
            return null;
        }

        if ($serverKey !== '') {
            $encodedBasic = base64_encode($serverKey.':');
            $cleaned = str_replace(
                [$serverKey, urlencode($serverKey), rawurlencode($serverKey), $encodedBasic],
                '[REDACTED]',
                $cleaned
            );
        }

        $cleaned = preg_replace(
            '/\b(Basic|Bearer)\s+[A-Za-z0-9+\/=._-]+/i',
            '$1 [REDACTED]',
            $cleaned
        ) ?? $cleaned;

        $cleaned = preg_replace(
            '/\b(server[_-]?key|api[_-]?key|authorization|secret|token)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $cleaned
        ) ?? $cleaned;

        $cleaned = preg_replace('/[\r\n\t]+/', ' ', $cleaned) ?? $cleaned;

        return mb_substr(trim($cleaned), 0, 200);
    }
}
