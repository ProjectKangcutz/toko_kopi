<?php

namespace App\Services\Shipping;

use RuntimeException;

class RegionLookupException extends RuntimeException
{
    /**
     * @param  array{
     *     category?: string,
     *     http_status?: ?int,
     *     meta_code?: ?int,
     *     endpoint?: ?string,
     *     auth_header?: ?string,
     *     exception_class?: ?string,
     *     upstream_message?: ?string
     * }  $diagnostics
     */
    public function __construct(
        string $message,
        protected int $statusCode = 503,
        protected string $errorCode = 'region_lookup_unavailable',
        protected array $diagnostics = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array{
     *     category: string,
     *     http_status: ?int,
     *     meta_code: ?int,
     *     endpoint: ?string,
     *     auth_header: ?string,
     *     exception_class: ?string,
     *     upstream_message: ?string
     * }
     */
    public function diagnostics(): array
    {
        return [
            'category' => (string) ($this->diagnostics['category'] ?? $this->errorCode),
            'http_status' => isset($this->diagnostics['http_status']) ? (int) $this->diagnostics['http_status'] : null,
            'meta_code' => isset($this->diagnostics['meta_code']) ? (int) $this->diagnostics['meta_code'] : null,
            'endpoint' => isset($this->diagnostics['endpoint']) ? (string) $this->diagnostics['endpoint'] : null,
            'auth_header' => isset($this->diagnostics['auth_header']) ? (string) $this->diagnostics['auth_header'] : null,
            'exception_class' => isset($this->diagnostics['exception_class']) ? (string) $this->diagnostics['exception_class'] : null,
            'upstream_message' => isset($this->diagnostics['upstream_message']) ? (string) $this->diagnostics['upstream_message'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function unconfigured(array $diagnostics = []): self
    {
        return new self(
            'Layanan pencarian wilayah belum dikonfigurasi. Anda tetap dapat mengisi detail wilayah secara manual.',
            503,
            'region_service_unconfigured',
            array_merge(['category' => 'unconfigured'], $diagnostics)
        );
    }

    public static function invalidParentId(): self
    {
        return new self(
            'Parameter wilayah induk tidak valid.',
            422,
            'invalid_region_parent',
            ['category' => 'invalid_parent_id']
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function timeout(array $diagnostics = []): self
    {
        return new self(
            'Koneksi ke layanan pencarian wilayah sedang lambat. Silakan coba lagi beberapa saat atau isi wilayah secara manual.',
            503,
            'region_timeout',
            array_merge(['category' => 'connection_or_timeout'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function upstreamUnavailable(array $diagnostics = []): self
    {
        return new self(
            'Layanan pencarian wilayah sedang tidak tersedia. Silakan coba lagi nanti.',
            503,
            'region_upstream_unavailable',
            array_merge(['category' => 'upstream_unavailable'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function malformedResponse(array $diagnostics = []): self
    {
        return new self(
            'Respons layanan pencarian wilayah tidak valid. Silakan coba lagi beberapa saat.',
            502,
            'region_malformed_response',
            array_merge(['category' => 'malformed_response'], $diagnostics)
        );
    }

    public static function invalidSearchKeyword(): self
    {
        return new self(
            'Kata kunci pencarian wilayah harus terdiri dari 2 hingga 100 karakter.',
            422,
            'invalid_region_keyword',
            ['category' => 'invalid_search_keyword']
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function hierarchicalUnsupportedOnCollaboratorSandbox(array $diagnostics = []): self
    {
        return new self(
            'Base URL Collaborator Sandbox hanya mendukung pencarian kata kunci wilayah dan tidak menyediakan endpoint provinsi/kota bertingkat. Gunakan pencarian wilayah atau atur RAJAONGKIR_BASE_URL ke https://rajaongkir.komerce.id/api/v1.',
            503,
            'region_hierarchical_unsupported',
            array_merge(['category' => 'hierarchical_unsupported_on_sandbox'], $diagnostics)
        );
    }
}
