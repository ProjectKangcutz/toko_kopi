<?php

namespace App\Services\Shipping;

use App\Services\Setting\StoreSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class KomerceRegionClient
{
    public function __construct(
        protected StoreSettingService $settingService
    ) {}

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function fetchProvinces(): array
    {
        return $this->requestAndNormalize('province', 'destination/province');
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function fetchCities(string $provinceId): array
    {
        $validatedId = self::validateParentId($provinceId);

        return $this->requestAndNormalize('city', 'destination/city/'.$validatedId, $validatedId);
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function fetchDistricts(string $cityId): array
    {
        $validatedId = self::validateParentId($cityId);

        return $this->requestAndNormalize('district', 'destination/district/'.$validatedId, $validatedId);
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    public function fetchSubdistricts(string $districtId): array
    {
        $validatedId = self::validateParentId($districtId);

        return $this->requestAndNormalize('subdistrict', 'destination/sub-district/'.$validatedId, $validatedId);
    }

    /**
     * Pencarian langsung wilayah / destination & origin ID.
     * Mendukung Komerce Collaborator Sandbox (/tariff/api/v1/destination/search)
     * maupun RajaOngkir V2 (/destination/domestic-destination).
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     label: string,
     *     province_name: ?string,
     *     city_name: ?string,
     *     district_name: ?string,
     *     subdistrict_name: ?string,
     *     zip_code: ?string
     * }>
     */
    public function searchDestinations(string $keyword, int $limit = 10): array
    {
        $validatedKeyword = self::validateSearchKeyword($keyword);
        $safeLimit = max(1, min(50, $limit));
        $config = $this->resolveConfig();

        $isCollaborator = self::isCollaboratorHost($config['base_url']);
        $endpoint = $this->resolveSearchEndpoint($config['base_url'], $isCollaborator);

        $query = $isCollaborator
            ? ['keyword' => $validatedKeyword]
            : ['search' => $validatedKeyword, 'limit' => $safeLimit, 'offset' => 0];

        $response = $this->executeGet($config, 'search', $endpoint, null, $query);

        $items = $this->parseSearchResponse(
            $response,
            'search',
            $endpoint,
            $this->authHeaderName($config['base_url']),
            $config['api_key']
        );

        return array_slice($items, 0, $safeLimit);
    }

    public static function validateParentId(string $id): string
    {
        $trimmed = trim($id);

        if ($trimmed === '' || ! preg_match('/^(?!0+$)[0-9]{1,20}$/', $trimmed)) {
            throw RegionLookupException::invalidParentId();
        }

        return $trimmed;
    }

    public static function validateSearchKeyword(string $keyword): string
    {
        $trimmed = trim($keyword);
        $length = mb_strlen($trimmed);

        if ($length < 2 || $length > 100) {
            throw RegionLookupException::invalidSearchKeyword();
        }

        return $trimmed;
    }

    public static function isCollaboratorHost(string $baseUrl): bool
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        return $host !== '' && str_contains($host, 'collaborator.komerce.id');
    }

    protected function authHeaderName(string $baseUrl): string
    {
        return self::isCollaboratorHost($baseUrl) ? 'x-api-key' : 'key';
    }

    protected function resolveSearchEndpoint(string $baseUrl, bool $isCollaborator): string
    {
        $path = trim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

        if ($isCollaborator) {
            if (str_ends_with($path, 'tariff/api/v1')) {
                return 'destination/search';
            }

            return 'tariff/api/v1/destination/search';
        }

        return 'destination/domestic-destination';
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    protected function requestAndNormalize(string $level, string $endpoint, ?string $parentId = null): array
    {
        $config = $this->resolveConfig();

        if (self::isCollaboratorHost($config['base_url'])) {
            throw RegionLookupException::hierarchicalUnsupportedOnCollaboratorSandbox([
                'endpoint' => $endpoint,
                'auth_header' => $this->authHeaderName($config['base_url']),
            ]);
        }

        $response = $this->executeGet($config, $level, $endpoint, $parentId);

        return $this->parseAndNormalizeResponse(
            $response,
            $level,
            $parentId,
            $endpoint,
            $this->authHeaderName($config['base_url']),
            $config['api_key']
        );
    }

    /**
     * @param  array{base_url: string, api_key: string, timeout: int}  $config
     * @param  array<string, scalar>  $query
     */
    protected function executeGet(
        array $config,
        string $level,
        string $endpoint,
        ?string $parentId = null,
        array $query = []
    ): Response {
        $timeout = max(2, min(30, (int) $config['timeout']));
        $connectTimeout = min(5, $timeout);
        $headerName = $this->authHeaderName($config['base_url']);

        try {
            $response = $this->buildPendingRequest($config['base_url'], $config['api_key'], $headerName, $connectTimeout, $timeout)
                ->get($endpoint, $query);
        } catch (ConnectionException $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['api_key']);

            Log::warning('Komerce region lookup connection/timeout failure.', [
                'level' => $level,
                'parent_id' => $parentId,
                'endpoint' => $endpoint,
                'auth_header' => $headerName,
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            throw RegionLookupException::timeout([
                'category' => 'connection_or_timeout',
                'endpoint' => $endpoint,
                'auth_header' => $headerName,
                'exception_class' => $e::class,
                'upstream_message' => $sanitizedError,
            ]);
        } catch (Throwable $e) {
            $sanitizedError = $this->sanitizeDiagnosticMessage($e->getMessage(), $config['api_key']);

            Log::warning('Komerce region lookup unexpected client failure.', [
                'level' => $level,
                'parent_id' => $parentId,
                'endpoint' => $endpoint,
                'auth_header' => $headerName,
                'exception' => $e::class,
                'detail' => $sanitizedError,
            ]);

            throw RegionLookupException::upstreamUnavailable([
                'category' => 'client_exception',
                'endpoint' => $endpoint,
                'auth_header' => $headerName,
                'exception_class' => $e::class,
                'upstream_message' => $sanitizedError,
            ]);
        }

        if ($response->failed()) {
            $status = $response->status();
            $category = $this->classifyHttpFailure($status);
            $metaCode = $this->extractMetaCode($response);
            $sanitizedError = $this->extractSanitizedErrorMessage($response, $config['api_key']);

            Log::warning('Komerce region lookup HTTP error.', [
                'level' => $level,
                'parent_id' => $parentId,
                'endpoint' => $endpoint,
                'auth_header' => $headerName,
                'status' => $status,
                'meta_code' => $metaCode,
                'category' => $category,
                'detail' => $sanitizedError,
            ]);

            throw RegionLookupException::upstreamUnavailable([
                'category' => $category,
                'http_status' => $status,
                'meta_code' => $metaCode,
                'endpoint' => $endpoint,
                'auth_header' => $headerName,
                'upstream_message' => $sanitizedError,
            ]);
        }

        return $response;
    }

    protected function buildPendingRequest(
        string $baseUrl,
        string $apiKey,
        string $headerName,
        int $connectTimeout,
        int $timeout
    ): PendingRequest {
        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders([
                $headerName => $apiKey,
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
     * @return array{base_url: string, api_key: string, timeout: int}
     */
    protected function resolveConfig(): array
    {
        $config = $this->settingService->rajaOngkirConfig();
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');

        if ($apiKey === '') {
            throw RegionLookupException::unconfigured([
                'upstream_message' => 'RAJAONGKIR_API_KEY belum diisi pada .env maupun tabel settings.',
            ]);
        }

        if ($baseUrl === '' || ! $this->isValidHttpUrl($baseUrl)) {
            throw RegionLookupException::unconfigured([
                'upstream_message' => 'RAJAONGKIR_BASE_URL kosong atau bukan URL HTTP/HTTPS yang valid.',
            ]);
        }

        // Hormati toggle nonaktif eksplisit jika API key tersimpan di database Settings.
        $dbSettings = $this->settingService->allDecoded()['rajaongkir'] ?? [];
        $hasDbApiKey = trim((string) ($dbSettings['api_key'] ?? '')) !== '';
        if ($hasDbApiKey && ! ($config['is_active'] ?? false)) {
            throw RegionLookupException::unconfigured([
                'upstream_message' => 'Integrasi rajaongkir di database settings memiliki api_key tetapi is_active bernilai nonaktif.',
            ]);
        }

        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'timeout' => (int) ($config['timeout'] ?? 10),
        ];
    }

    protected function isValidHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractValidatedDataList(
        Response $response,
        string $level,
        ?string $parentId,
        string $endpoint,
        string $authHeader,
        string $apiKey
    ): array {
        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('Komerce region lookup returned non-array JSON.', [
                'level' => $level,
                'parent_id' => $parentId,
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            throw RegionLookupException::malformedResponse([
                'category' => 'malformed_non_json',
                'http_status' => $response->status(),
                'endpoint' => $endpoint,
                'auth_header' => $authHeader,
                'upstream_message' => 'Respons upstream bukan JSON array yang valid.',
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
            );

            Log::warning('Komerce region lookup returned error meta.', [
                'level' => $level,
                'parent_id' => $parentId,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'meta_code' => $metaCode,
                'detail' => $sanitizedMetaMsg,
            ]);

            throw RegionLookupException::upstreamUnavailable([
                'category' => 'upstream_meta_error',
                'http_status' => $response->status(),
                'meta_code' => $metaCode,
                'endpoint' => $endpoint,
                'auth_header' => $authHeader,
                'upstream_message' => $sanitizedMetaMsg,
            ]);
        }

        if (
            array_key_exists('data', $payload)
            && $payload['data'] === null
            && ($metaStatus === 'success' || ($metaCode !== null && $metaCode >= 200 && $metaCode < 300))
        ) {
            return [];
        }

        if (! array_key_exists('data', $payload) || ! is_array($payload['data']) || ! array_is_list($payload['data'])) {
            Log::warning('Komerce region lookup missing valid data list.', [
                'level' => $level,
                'parent_id' => $parentId,
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            throw RegionLookupException::malformedResponse([
                'category' => 'malformed_data_structure',
                'http_status' => $response->status(),
                'meta_code' => $metaCode,
                'endpoint' => $endpoint,
                'auth_header' => $authHeader,
                'upstream_message' => 'Field data tidak ada atau bukan list JSON yang valid.',
            ]);
        }

        return $payload['data'];
    }

    /**
     * @return array<int, array{id: string, name: string, zip_code: ?string}>
     */
    protected function parseAndNormalizeResponse(
        Response $response,
        string $level,
        ?string $parentId,
        string $endpoint,
        string $authHeader,
        string $apiKey
    ): array {
        $dataList = $this->extractValidatedDataList($response, $level, $parentId, $endpoint, $authHeader, $apiKey);
        $normalized = [];

        foreach ($dataList as $item) {
            if (! is_array($item)) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_type',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $rawId = $item['id'] ?? null;
            $rawName = $item['name'] ?? null;

            if ((! is_int($rawId) && ! is_string($rawId)) || (! is_string($rawName) && ! is_numeric($rawName))) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_fields',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $id = trim((string) $rawId);
            $name = trim((string) $rawName);

            if ($id === '' || $name === '' || mb_strlen($id) > 30) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_values',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $zipCode = $this->normalizeOptionalText($item['zip_code'] ?? null, 15);

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'zip_code' => $zipCode,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     label: string,
     *     province_name: ?string,
     *     city_name: ?string,
     *     district_name: ?string,
     *     subdistrict_name: ?string,
     *     zip_code: ?string
     * }>
     */
    protected function parseSearchResponse(
        Response $response,
        string $level,
        string $endpoint,
        string $authHeader,
        string $apiKey
    ): array {
        $dataList = $this->extractValidatedDataList($response, $level, null, $endpoint, $authHeader, $apiKey);
        $normalized = [];

        foreach ($dataList as $item) {
            if (! is_array($item)) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_type',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $rawId = $item['id'] ?? null;
            if (! is_int($rawId) && ! is_string($rawId)) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_fields',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $id = trim((string) $rawId);
            if ($id === '' || mb_strlen($id) > 30) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_values',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $label = $this->normalizeOptionalText($item['label'] ?? null, 255);
            $subdistrictName = $this->normalizeOptionalText($item['subdistrict_name'] ?? null, 120);
            $districtName = $this->normalizeOptionalText($item['district_name'] ?? null, 120);
            $cityName = $this->normalizeOptionalText($item['city_name'] ?? null, 120);
            $provinceName = $this->normalizeOptionalText($item['province_name'] ?? null, 120);
            $zipCode = $this->normalizeOptionalText($item['zip_code'] ?? null, 15);

            $fallbackName = $this->normalizeOptionalText($item['name'] ?? null, 255);
            $displayName = $label ?? $fallbackName ?? $subdistrictName ?? $districtName ?? $cityName;

            if ($displayName === null) {
                throw RegionLookupException::malformedResponse([
                    'category' => 'malformed_item_fields',
                    'http_status' => $response->status(),
                    'endpoint' => $endpoint,
                    'auth_header' => $authHeader,
                ]);
            }

            $normalized[] = [
                'id' => $id,
                'name' => $displayName,
                'label' => $label ?? $displayName,
                'province_name' => $provinceName,
                'city_name' => $cityName,
                'district_name' => $districtName,
                'subdistrict_name' => $subdistrictName,
                'zip_code' => $zipCode,
            ];
        }

        return $normalized;
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
            '/\b(x-api-key|api[_-]?key|authorization|bearer|token|secret)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $cleaned
        ) ?? $cleaned;

        $cleaned = preg_replace('/[\r\n\t]+/', ' ', $cleaned) ?? $cleaned;

        return mb_substr(trim($cleaned), 0, 200);
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
}
