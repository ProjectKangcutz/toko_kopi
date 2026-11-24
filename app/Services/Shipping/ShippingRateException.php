<?php

namespace App\Services\Shipping;

use RuntimeException;

class ShippingRateException extends RuntimeException
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
        protected string $errorCode = 'shipping_rate_unavailable',
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
    public static function inactive(array $diagnostics = []): self
    {
        return new self(
            'Layanan perhitungan ongkos kirim sedang dinonaktifkan.',
            503,
            'shipping_integration_inactive',
            array_merge(['category' => 'integration_inactive'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function unconfigured(array $diagnostics = []): self
    {
        return new self(
            'Layanan perhitungan ongkos kirim belum dikonfigurasi.',
            503,
            'shipping_service_unconfigured',
            array_merge(['category' => 'unconfigured'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function missingOrigin(array $diagnostics = []): self
    {
        return new self(
            'Lokasi asal pengiriman toko belum dikonfigurasi.',
            503,
            'shipping_origin_missing',
            array_merge(['category' => 'missing_origin'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function unsupportedProvider(array $diagnostics = []): self
    {
        return new self(
            'Konfigurasi Base URL pengiriman tidak mendukung endpoint RajaOngkir V2.',
            503,
            'shipping_provider_unsupported',
            array_merge(['category' => 'unsupported_provider'], $diagnostics)
        );
    }

    public static function invalidDestination(string $message = 'Alamat tujuan belum memiliki ID wilayah pengiriman yang valid. Silakan pilih wilayah melalui pencarian wilayah.'): self
    {
        return new self(
            $message,
            422,
            'shipping_invalid_destination',
            ['category' => 'invalid_destination']
        );
    }

    public static function invalidWeight(string $message = 'Berat paket pengiriman tidak valid.'): self
    {
        return new self(
            $message,
            422,
            'shipping_invalid_weight',
            ['category' => 'invalid_weight']
        );
    }

    public static function emptyCart(): self
    {
        return new self(
            'Keranjang belanja kosong atau tidak memiliki item yang tersedia untuk dihitung ongkos kirimnya.',
            422,
            'shipping_empty_cart',
            ['category' => 'empty_cart']
        );
    }

    public static function noActiveCouriers(): self
    {
        return new self(
            'Belum ada kurir pengiriman aktif yang tersedia saat ini.',
            503,
            'shipping_no_active_couriers',
            ['category' => 'no_active_couriers']
        );
    }

    public static function rateNotFound(): self
    {
        return new self(
            'Layanan kurir yang dipilih tidak tersedia untuk rute pengiriman ini.',
            422,
            'shipping_rate_not_found',
            ['category' => 'rate_not_found']
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function timeout(array $diagnostics = []): self
    {
        return new self(
            'Koneksi ke layanan cek ongkos kirim sedang lambat. Silakan coba lagi beberapa saat.',
            503,
            'shipping_timeout',
            array_merge(['category' => 'connection_or_timeout'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function upstreamUnavailable(array $diagnostics = []): self
    {
        return new self(
            'Layanan perhitungan ongkos kirim sedang tidak tersedia. Silakan coba lagi nanti.',
            503,
            'shipping_upstream_unavailable',
            array_merge(['category' => 'upstream_unavailable'], $diagnostics)
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function malformedResponse(array $diagnostics = []): self
    {
        return new self(
            'Respons layanan ongkos kirim tidak valid. Silakan coba lagi beberapa saat.',
            502,
            'shipping_malformed_response',
            array_merge(['category' => 'malformed_response'], $diagnostics)
        );
    }
}
