<?php

namespace App\Services\Checkout;

use RuntimeException;

class CheckoutOrderException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        string $message,
        protected string $errorCode,
        protected int $statusCode = 422,
        protected array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function storeClosed(): self
    {
        return new self(
            'Toko sedang tutup atau dalam pemeliharaan. Pemesanan sementara belum dapat diproses.',
            'store_closed',
            422
        );
    }

    public static function emptyCart(): self
    {
        return new self(
            'Keranjang belanja Anda kosong atau tidak memiliki produk aktif yang siap dipesan.',
            'empty_cart',
            422
        );
    }

    public static function unavailableCartItem(string $message): self
    {
        return new self($message, 'cart_item_unavailable', 422);
    }

    public static function insufficientStock(string $message): self
    {
        return new self($message, 'insufficient_stock', 422);
    }

    public static function belowMinimumOrder(float $minAmount): self
    {
        return new self(
            'Subtotal pesanan belum memenuhi minimal belanja sebesar Rp '.number_format($minAmount, 0, ',', '.').'.',
            'below_minimum_order',
            422
        );
    }

    public static function invalidAddress(string $message = 'Alamat pengiriman tidak valid atau sudah tidak aktif.'): self
    {
        return new self($message, 'invalid_shipping_address', 422);
    }

    public static function quoteMismatch(
        string $message = 'Data keranjang, berat, harga, atau tarif pengiriman berubah sebelum pesanan diproses. Silakan muat ulang tarif pengiriman.'
    ): self {
        return new self($message, 'checkout_quote_mismatch', 409);
    }

    public static function idempotencyConflict(
        string $message = 'Kunci transaksi ini sudah pernah dipakai untuk detail pesanan yang berbeda. Silakan muat ulang halaman checkout untuk mencoba lagi.'
    ): self {
        return new self($message, 'idempotency_key_conflict', 409);
    }
}
