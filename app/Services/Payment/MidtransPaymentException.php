<?php

namespace App\Services\Payment;

use RuntimeException;

class MidtransPaymentException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public function __construct(
        string $message,
        protected int $statusCode = 503,
        protected string $errorCode = 'midtrans_payment_error',
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
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function inactive(array $diagnostics = []): self
    {
        return new self(
            'Pembayaran online Midtrans sedang dinonaktifkan oleh toko.',
            503,
            'midtrans_integration_inactive',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function unconfigured(array $diagnostics = []): self
    {
        return new self(
            'Konfigurasi pembayaran Midtrans belum lengkap. Silakan hubungi pengelola toko.',
            503,
            'midtrans_service_unconfigured',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function alreadyPaid(string $message = 'Pesanan ini sudah lunas dan tidak memerlukan pembayaran ulang.', array $diagnostics = []): self
    {
        return new self(
            $message,
            422,
            'payment_already_paid',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function orderCancelled(string $message = 'Pesanan yang telah dibatalkan atau direfund tidak dapat dibayar.', array $diagnostics = []): self
    {
        return new self(
            $message,
            422,
            'payment_order_cancelled',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function orderExpired(string $message = 'Batas waktu pembayaran pesanan ini telah berakhir.', array $diagnostics = []): self
    {
        return new self(
            $message,
            422,
            'payment_order_expired',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function invalidOrderState(string $message = 'Status pesanan saat ini tidak mengizinkan inisiasi pembayaran.', array $diagnostics = []): self
    {
        return new self(
            $message,
            422,
            'payment_invalid_order_state',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function amountInvalid(string $message = 'Nominal total pembayaran pesanan tidak valid.', array $diagnostics = []): self
    {
        return new self(
            $message,
            422,
            'payment_amount_invalid',
            $diagnostics
        );
    }

    public static function lockBusy(): self
    {
        return new self(
            'Permintaan pembayaran untuk pesanan ini sedang diproses. Silakan coba sesaat lagi.',
            409,
            'payment_initiation_in_progress'
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function timeout(array $diagnostics = []): self
    {
        return new self(
            'Koneksi ke layanan pembayaran Midtrans sedang lambat. Silakan coba lagi beberapa saat.',
            503,
            'midtrans_timeout',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function upstreamUnavailable(array $diagnostics = []): self
    {
        return new self(
            'Layanan pembayaran Midtrans sedang tidak dapat dihubungi. Silakan coba lagi nanti.',
            503,
            'midtrans_upstream_unavailable',
            $diagnostics
        );
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public static function malformedResponse(array $diagnostics = []): self
    {
        return new self(
            'Respons layanan pembayaran Midtrans tidak valid. Silakan coba lagi beberapa saat.',
            502,
            'midtrans_malformed_response',
            $diagnostics
        );
    }
}
