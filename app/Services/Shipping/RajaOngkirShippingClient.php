<?php

namespace App\Services\Shipping;

use App\Models\ShippingRateLog;
use App\Services\Setting\StoreSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RajaOngkirShippingClient
{
    public const PROVIDER = 'rajaongkir_v2';

    public const ENDPOINT_DOMESTIC_COST = 'calculate/domestic-cost';

    public function __construct(
        protected StoreSettingService $settingService
    ) {}

    /**
     * Menghitung tarif pengiriman domestik melalui RajaOngkir V2 (POST calculate/domestic-cost).
     *
     * @param  array<int, string>  $courierCodes
     * @return array<int, array{
     *     courier_code: string,
     *     courier_name: string,
     *     service_code: string,
     *     service_name: string,
     *     description: ?string,
     *     cost: int|float,
     *     etd: ?string
     * }>
     */
    public function calculateDomesticCost(string $destinationId, int $weightGrams, array $courierCodes): array
    {
        $config = $this->resolveConfig();
        $validatedDestinationId = self::validateLocationId($destinationId, 'tujuan');

        if ($weightGrams < 1) {
            throw ShippingRateException::invalidWeight('Berat paket minimal 1 gram.');
        }

        $normalizedCouriers = self::normalizeCourierCodes($courierCodes);
        if ($normalizedCouriers === []) {
            throw ShippingRateException::noActiveCouriers();
        }

        $courierParam = implode(':', $normalizedCouriers);
        $requestPayload = [
            'origin' => $config['origin_id'],
            'destination' => $validatedDestinationId,
            'weight' => $weightGrams,
            'courier' => $courierParam,
        ];

        $startedAt = microtime(true);
        $response = $this->executePost(
            $config,
            $requestPayload,
            $startedAt
        );

        return $this->parseAndNormalizeRates(
            $response,
            $requestPayload,
            $config['api_key'],
            $startedAt
        );
    }

    public static function validateLocationId(string $id, string $label = 'lokasi'): string
    {
        $trimmed = trim($id);

        if (
            $trimmed === ''
            || strtolower($trimmed) === 'manual'
            || ! preg_match('/^(?!0+$)[0-9]{1,30}$/', $trimmed)
        ) {
            throw ShippingRateException::invalidDestination(
                "ID {$label} pengiriman tidak valid atau berasal dari input manual."
            );
        }

        return $trimmed;
    }

    /**
     * @param  array<int, string>  $courierCodes
     * @return array<int, string>
     */
    public static function normalizeCourierCodes(array $courierCodes): array
    {
        $normalized = [];

        foreach ($courierCodes as $code) {
            $cleaned = strtolower(trim((string) $code));
            if ($cleaned !== '' && preg_match('/^[a-z0-9_-]{1,30}$/', $cleaned)) {
                $normalized[$cleaned] = $cleaned;
            }
        }

        return array_values($normalized);
    }

    /**
     * @return array{base_url: string, api_key: string, origin_id: string, timeout: int}
     */
    public function resolveConfig(): array
    {
        $config = $this->settingService->rajaOngkirConfig();

        if (! ($config['is_active'] ?? false)) {
            throw ShippingRateException::inactive([
                'upstream_message' => 'Integrasi rajaongkir pada pengaturan toko sedang nonaktif.',
            ]);
        }

        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw ShippingRateException::unconfigured([
                'upstream_message' => 'API Key RajaOngkir belum diisi pada database settings maupun .env.',
            ]);
        }

        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
        if ($baseUrl === '' || ! $this->isValidHttpUrl($baseUrl)) {
            throw ShippingRateException::unconfigured([
                'upstream_message' => 'Base URL RajaOngkir kosong atau bukan URL HTTP/HTTPS yang valid.',
            ]);
        }

        if (KomerceRegionClient::isCollaboratorHost($baseUrl)) {
            throw ShippingRateException::unsupportedProvider([
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'upstream_message' => 'Endpoint tarif RajaOngkir V2 tidak dapat dipanggil menggunakan host Collaborator Sandbox.',
            ]);
        }

        $originId = trim((string) ($config['origin_id'] ?? ''));
        if ($originId === '' || strtolower($originId) === 'manual' || ! preg_match('/^(?!0+$)[0-9]{1,30}$/', $originId)) {
            throw ShippingRateException::missingOrigin([
                'upstream_message' => 'rajaongkir.origin_id belum diisi atau tidak valid.',
            ]);
        }

        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'origin_id' => $originId,
            'timeout' => max(2, min(30, (int) ($config['timeout'] ?? 10))),
        ];
    }

    /**
     * @param  array{base_url: string, api_key: string, origin_id: string, timeout: int}  $config
     * @param  array{origin: string, destination: string, weight: int, courier: string}  $requestPayload
     */
    protected function executePost(array $config, array $requestPayload, float $startedAt): Response
    {
        $timeout = $config['timeout'];
        $connectTimeout = min(5, $timeout);

        try {
            $response = $this->buildPendingRequest($config['base_url'], $config['api_key'], $connectTimeout, $timeout)
                ->post(self::ENDPOINT_DOMESTIC_COST, $requestPayload);
        } catch (ConnectionException $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['api_key'])
                ?? 'Koneksi ke RajaOngkir V2 timeout.';

            Log::warning('RajaOngkir V2 shipping rate connection/timeout failure.', [
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'origin_id' => $requestPayload['origin'],
                'destination_id' => $requestPayload['destination'],
                'weight_grams' => $requestPayload['weight'],
                'courier_codes' => $requestPayload['courier'],
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            $this->recordRateLog(
                $requestPayload,
                null,
                false,
                $sanitizedError,
                null,
                $startedAt
            );

            throw ShippingRateException::timeout([
                'category' => 'connection_or_timeout',
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'auth_header' => 'key',
                'exception_class' => $e::class,
                'upstream_message' => $sanitizedError,
            ]);
        } catch (Throwable $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['api_key'])
                ?? 'Kesalahan client saat menghubungi RajaOngkir V2.';

            Log::warning('RajaOngkir V2 shipping rate unexpected client failure.', [
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'origin_id' => $requestPayload['origin'],
                'destination_id' => $requestPayload['destination'],
                'weight_grams' => $requestPayload['weight'],
                'courier_codes' => $requestPayload['courier'],
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            $this->recordRateLog(
                $requestPayload,
                null,
                false,
                $sanitizedError,
                null,
                $startedAt
            );

            throw ShippingRateException::upstreamUnavailable([
                'category' => 'client_exception',
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'auth_header' => 'key',
                'exception_class' => $e::class,
                'upstream_message' => $sanitizedError,
            ]);
        }

        if ($response->failed()) {
            $status = $response->status();
            $category = $this->classifyHttpFailure($status);
            $metaCode = $this->extractMetaCode($response);
            $sanitizedError = $this->extractSanitizedErrorMessage($response, $config['api_key'])
                ?? ('RajaOngkir V2 mengembalikan HTTP '.$status.'.');

            Log::warning('RajaOngkir V2 shipping rate HTTP error.', [
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'origin_id' => $requestPayload['origin'],
                'destination_id' => $requestPayload['destination'],
                'weight_grams' => $requestPayload['weight'],
                'courier_codes' => $requestPayload['courier'],
                'status' => $status,
                'meta_code' => $metaCode,
                'category' => $category,
                'detail' => $sanitizedError,
            ]);

            $this->recordRateLog(
                $requestPayload,
                $status,
                false,
                $sanitizedError,
                $this->sanitizeResponsePayloadForLog($response, $config['api_key']),
                $startedAt
            );

            throw ShippingRateException::upstreamUnavailable([
                'category' => $category,
                'http_status' => $status,
                'meta_code' => $metaCode,
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'auth_header' => 'key',
                'upstream_message' => $sanitizedError,
            ]);
        }

        return $response;
    }

    protected function buildPendingRequest(
        string $baseUrl,
        string $apiKey,
        int $connectTimeout,
        int $timeout
    ): PendingRequest {
        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asForm()
            ->withHeaders([
                'key' => $apiKey,
            ])
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->retry(
                2,
                50,
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException && $exception->response !== null) {
                        return $exception->response->serverError()
                            || $exception->response->status() === 429;
                    }

                    return false;
                },
                throw: false
            );
    }

    /**
     * @param  array{origin: string, destination: string, weight: int, courier: string}  $requestPayload
     * @return array<int, array{
     *     courier_code: string,
     *     courier_name: string,
     *     service_code: string,
     *     service_name: string,
     *     description: ?string,
     *     cost: int|float,
     *     etd: ?string
     * }>
     */
    protected function parseAndNormalizeRates(
        Response $response,
        array $requestPayload,
        string $apiKey,
        float $startedAt
    ): array {
        $status = $response->status();
        $payload = $response->json();

        if (! is_array($payload)) {
            $errorMsg = 'Respons RajaOngkir V2 bukan JSON array/objek yang valid.';
            $this->recordRateLog(
                $requestPayload,
                $status,
                false,
                $errorMsg,
                $this->sanitizeResponsePayloadForLog($response, $apiKey),
                $startedAt
            );

            throw ShippingRateException::malformedResponse([
                'category' => 'malformed_non_json',
                'http_status' => $status,
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'auth_header' => 'key',
                'upstream_message' => $errorMsg,
            ]);
        }

        $meta = $payload['meta'] ?? null;
        $metaStatus = is_array($meta) && is_string($meta['status'] ?? null)
            ? strtolower(trim($meta['status']))
            : null;
        $metaCode = is_array($meta) && is_numeric($meta['code'] ?? null)
            ? (int) $meta['code']
            : null;

        if (($metaCode !== null && $metaCode >= 400) || $metaStatus === 'error') {
            $sanitizedMetaMsg = $this->sanitizeDiagnosticMessage(
                is_array($meta) && is_string($meta['message'] ?? null) ? $meta['message'] : null,
                $apiKey
            ) ?? 'Upstream mengembalikan meta error.';

            $this->recordRateLog(
                $requestPayload,
                $status,
                false,
                $sanitizedMetaMsg,
                $this->sanitizePayloadArray($payload, $apiKey),
                $startedAt
            );

            throw ShippingRateException::upstreamUnavailable([
                'category' => 'upstream_meta_error',
                'http_status' => $status,
                'meta_code' => $metaCode,
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'auth_header' => 'key',
                'upstream_message' => $sanitizedMetaMsg,
            ]);
        }

        if (
            array_key_exists('data', $payload)
            && $payload['data'] === null
            && ($metaStatus === 'success' || ($metaCode !== null && $metaCode >= 200 && $metaCode < 300))
        ) {
            $this->recordRateLog(
                $requestPayload,
                $status,
                true,
                null,
                $this->sanitizePayloadArray($payload, $apiKey),
                $startedAt
            );

            return [];
        }

        if (! array_key_exists('data', $payload) || ! is_array($payload['data']) || ! array_is_list($payload['data'])) {
            $errorMsg = 'Field data tidak ada atau bukan array numerik yang valid.';
            $this->recordRateLog(
                $requestPayload,
                $status,
                false,
                $errorMsg,
                $this->sanitizePayloadArray($payload, $apiKey),
                $startedAt
            );

            throw ShippingRateException::malformedResponse([
                'category' => 'malformed_data_structure',
                'http_status' => $status,
                'meta_code' => $metaCode,
                'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                'auth_header' => 'key',
                'upstream_message' => $errorMsg,
            ]);
        }

        $normalized = [];

        foreach ($payload['data'] as $item) {
            if (! is_array($item)) {
                $errorMsg = 'Elemen layanan pada field data bukan array/objek yang valid.';
                $this->recordRateLog(
                    $requestPayload,
                    $status,
                    false,
                    $errorMsg,
                    $this->sanitizePayloadArray($payload, $apiKey),
                    $startedAt
                );

                throw ShippingRateException::malformedResponse([
                    'category' => 'malformed_item_type',
                    'http_status' => $status,
                    'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                    'auth_header' => 'key',
                    'upstream_message' => $errorMsg,
                ]);
            }

            $courierCode = $this->normalizeRequiredIdentifier($item['code'] ?? null, 30);
            $courierName = $this->normalizeOptionalText($item['name'] ?? null, 100);
            $serviceCode = $this->normalizeRequiredText($item['service'] ?? null, 40);
            $description = $this->normalizeOptionalText($item['description'] ?? null, 255);
            $cost = $this->parseNonNegativeCost($item['cost'] ?? null);
            $etd = $this->normalizeOptionalText($item['etd'] ?? null, 40);

            if (
                $courierCode === null
                || $courierName === null
                || $serviceCode === null
                || $cost === null
            ) {
                $errorMsg = 'Field layanan ongkir (code, name, service, atau cost) tidak valid.';
                $this->recordRateLog(
                    $requestPayload,
                    $status,
                    false,
                    $errorMsg,
                    $this->sanitizePayloadArray($payload, $apiKey),
                    $startedAt
                );

                throw ShippingRateException::malformedResponse([
                    'category' => 'malformed_item_fields',
                    'http_status' => $status,
                    'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                    'auth_header' => 'key',
                    'upstream_message' => $errorMsg,
                ]);
            }

            $normalized[] = [
                'courier_code' => strtolower($courierCode),
                'courier_name' => $courierName,
                'service_code' => $serviceCode,
                'service_name' => $description ?? $serviceCode,
                'description' => $description,
                'cost' => $cost,
                'etd' => $etd,
            ];
        }

        $this->recordRateLog(
            $requestPayload,
            $status,
            true,
            null,
            $this->sanitizePayloadArray($payload, $apiKey),
            $startedAt
        );

        return $normalized;
    }

    /**
     * @param  array{origin: string, destination: string, weight: int, courier: string}  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    protected function recordRateLog(
        array $requestPayload,
        ?int $httpStatus,
        bool $isSuccess,
        ?string $errorMessage,
        ?array $responsePayload,
        float $startedAt
    ): void {
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));

        try {
            ShippingRateLog::create([
                'provider' => self::PROVIDER,
                'origin_id' => mb_substr($requestPayload['origin'], 0, 30),
                'destination_id' => mb_substr($requestPayload['destination'], 0, 30),
                'weight_grams' => $requestPayload['weight'],
                'courier_codes' => mb_substr($requestPayload['courier'], 0, 255),
                'http_status' => $httpStatus,
                'is_success' => $isSuccess,
                'error_message' => $errorMessage,
                'request_payload' => [
                    'endpoint' => self::ENDPOINT_DOMESTIC_COST,
                    'method' => 'POST',
                    'origin' => $requestPayload['origin'],
                    'destination' => $requestPayload['destination'],
                    'weight' => $requestPayload['weight'],
                    'courier' => $requestPayload['courier'],
                ],
                'response_payload' => $responsePayload,
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Gagal menyimpan shipping_rate_logs.', [
                'exception' => $e::class,
                'message' => $this->sanitizeDiagnosticMessage($e->getMessage()),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function sanitizeResponsePayloadForLog(Response $response, string $apiKey): ?array
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            return $this->sanitizePayloadArray($decoded, $apiKey);
        }

        $body = trim($response->body());
        if ($body === '') {
            return null;
        }

        return [
            'raw_excerpt' => $this->sanitizeDiagnosticMessage(mb_substr($body, 0, 500), $apiKey),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    protected function sanitizePayloadArray(array $payload, string $apiKey): array
    {
        $sanitized = [];
        $sensitiveKeys = ['key', 'api_key', 'x-api-key', 'authorization', 'token', 'secret', 'password'];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower(trim($key)) : null;

            if ($normalizedKey !== null && in_array($normalizedKey, $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayloadArray($value, $apiKey);
                continue;
            }

            if (is_string($value)) {
                $cleaned = $value;
                if ($apiKey !== '') {
                    $cleaned = str_replace([$apiKey, urlencode($apiKey), rawurlencode($apiKey)], '[REDACTED]', $cleaned);
                }
                $sanitized[$key] = $cleaned;
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    protected function isValidHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
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

    protected function extractMetaCode(Response $response): ?int
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $metaCode = $payload['meta']['code'] ?? null;

        return is_numeric($metaCode) ? (int) $metaCode : null;
    }

    protected function extractSanitizedErrorMessage(Response $response, string $apiKey): ?string
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return 'Upstream mengembalikan respons non-JSON (HTTP '.$response->status().').';
        }

        $candidates = [
            $payload['meta']['message'] ?? null,
            $payload['message'] ?? null,
            $payload['error'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->sanitizeDiagnosticMessage($candidate, $apiKey);
            }
        }

        return null;
    }

    protected function sanitizeDiagnosticMessage(?string $message, string $apiKey = ''): ?string
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

    protected function normalizeRequiredIdentifier(mixed $value, int $maxLength): ?string
    {
        $text = $this->normalizeRequiredText($value, $maxLength);
        if ($text === null) {
            return null;
        }

        $lower = strtolower($text);

        return preg_match('/^[a-z0-9_-]{1,30}$/', $lower) ? $lower : null;
    }

    protected function normalizeRequiredText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    protected function normalizeOptionalText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    protected function parseNonNegativeCost(mixed $value): int|float|null
    {
        if (is_bool($value) || $value === null || is_array($value) || is_object($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0) {
                return null;
            }

            return fmod($value, 1.0) === 0.0 ? (int) $value : round($value, 2);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }

            $numeric = (float) $trimmed;
            if (! is_finite($numeric) || $numeric < 0) {
                return null;
            }

            return fmod($numeric, 1.0) === 0.0 ? (int) $numeric : round($numeric, 2);
        }

        return null;
    }
}
