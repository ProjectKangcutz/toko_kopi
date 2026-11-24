<?php

namespace App\Services\Payment;

use App\Models\PaymentWebhookLog;
use App\Services\Setting\StoreSettingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MidtransWebhookIngestionService
{
    private const GATEWAY = 'midtrans';

    private const MAX_ORDER_ID_LENGTH = 80;

    private const MAX_TRANSACTION_ID_LENGTH = 100;

    private const MAX_TRANSACTION_STATUS_LENGTH = 40;

    private const MAX_STATUS_CODE_LENGTH = 10;

    private const MAX_FRAUD_STATUS_LENGTH = 40;

    private const MAX_GROSS_AMOUNT_LENGTH = 40;

    private const MAX_SIGNATURE_LENGTH = 255;

    /**
     * Allowlist header HTTP yang aman untuk disimpan di audit webhook log.
     * Header credential (authorization, cookie, set-cookie, x-api-key, dll.) tidak pernah disimpan.
     *
     * @var array<int, string>
     */
    private const ALLOWED_HEADERS = [
        'content-type',
        'user-agent',
        'x-request-id',
    ];

    /**
     * Allowlist field payload notifikasi Midtrans yang relevan untuk Phase 7B.
     * Field signature_key maupun field tak dikenal/sensitif tidak disimpan ke DB.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PAYLOAD_SCALAR_FIELDS = [
        'order_id',
        'transaction_id',
        'transaction_status',
        'status_code',
        'status_message',
        'gross_amount',
        'currency',
        'payment_type',
        'fraud_status',
        'transaction_time',
        'settlement_time',
        'expiry_time',
        'merchant_id',
        'bank',
        'acquirer',
        'issuer',
        'store',
        'payment_code',
        'biller_code',
        'bill_key',
        'permata_va_number',
        'channel_response_code',
        'channel_response_message',
        'approval_code',
        'masked_card',
        'card_type',
        'eci',
        'refund_amount',
    ];

    public function __construct(
        protected StoreSettingService $settingService,
        protected PaymentStateSynchronizationService $synchronizationService,
    ) {}

    /**
     * Memverifikasi payload & signature webhook Midtrans, mencatat notifikasi valid secara idempoten,
     * lalu mendelegasikan pembaruan status pembayaran ke PaymentStateSynchronizationService (Phase 7B)
     * tanpa mengubah stok maupun StockReservation (ditunda ke Phase 7C).
     *
     * @return array{
     *     status_code: int,
     *     body: array{message: string, error_code?: string}
     * }
     */
    public function ingest(Request $request): array
    {
        $midtrans = $this->settingService->midtransConfig();
        $serverKey = trim((string) ($midtrans['server_key'] ?? ''));

        if ($serverKey === '') {
            Log::channel('payment')->warning('Midtrans webhook diterima namun Server Key belum dikonfigurasi.');

            return [
                'status_code' => 503,
                'body' => [
                    'message' => 'Payment gateway not configured',
                ],
            ];
        }

        $rawPayload = $request->all();

        $signatureFields = $this->extractAndValidateSignatureFields($rawPayload);
        if (! $signatureFields['valid']) {
            return [
                'status_code' => 422,
                'body' => [
                    'message' => $signatureFields['message'],
                    'error_code' => 'invalid_webhook_payload',
                ],
            ];
        }

        // Jika transaction_status disertakan sebelum signature check, pastikan bukan non-scalar/kosong
        // agar payload manipulatif seperti array tidak pernah lolos menjadi error 500.
        if (array_key_exists('transaction_status', $rawPayload)) {
            $preStatusCheck = $this->validateRequiredStringField(
                $rawPayload,
                'transaction_status',
                self::MAX_TRANSACTION_STATUS_LENGTH
            );
            if (! $preStatusCheck['valid']) {
                return [
                    'status_code' => 422,
                    'body' => [
                        'message' => $preStatusCheck['message'],
                        'error_code' => 'invalid_webhook_payload',
                    ],
                ];
            }
        }

        $orderId = $signatureFields['order_id'];
        $statusCode = $signatureFields['status_code'];
        $grossAmount = $signatureFields['gross_amount'];
        $signatureKey = $signatureFields['signature_key'];

        $expectedSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
        if (! hash_equals($expectedSignature, $signatureKey)) {
            Log::channel('security')->warning('Signature webhook Midtrans tidak valid.', [
                'order_id' => mb_substr($orderId, 0, self::MAX_ORDER_ID_LENGTH, 'UTF-8'),
                'ip' => $request->ip(),
            ]);

            return [
                'status_code' => 403,
                'body' => [
                    'message' => 'Invalid signature',
                ],
            ];
        }

        $remainingFields = $this->extractAndValidatePostSignatureFields($rawPayload);
        if (! $remainingFields['valid']) {
            return [
                'status_code' => 422,
                'body' => [
                    'message' => $remainingFields['message'],
                    'error_code' => 'invalid_webhook_payload',
                ],
            ];
        }

        $transactionStatus = $remainingFields['transaction_status'];
        $transactionId = $remainingFields['transaction_id'];
        $fraudStatus = $remainingFields['fraud_status'];

        $idempotencyKey = self::buildIdempotencyKey(
            $orderId,
            $transactionId,
            $transactionStatus,
            $statusCode,
            $fraudStatus,
            $grossAmount
        );

        $attributes = [
            'idempotency_key' => $idempotencyKey,
            'gateway' => self::GATEWAY,
            'midtrans_order_id' => $orderId,
            'transaction_id' => $transactionId,
            'transaction_status' => $transactionStatus,
            'status_code' => $statusCode,
            'signature_valid' => true,
            'processed' => false,
            'processing_error' => null,
            'headers' => $this->sanitizeHeaders($request),
            'payload' => $this->sanitizePayload($rawPayload),
            'received_at' => now(),
        ];

        try {
            $persisted = $this->persistIdempotentLog($attributes, $idempotencyKey);
            $log = $persisted['log'];
            $duplicate = $persisted['duplicate'];

            if ($log !== null && ! $log->processed) {
                $this->synchronizationService->synchronizeFromWebhookLog($log);
            }

            return [
                'status_code' => 200,
                'body' => [
                    'message' => $duplicate
                        ? 'Webhook already recorded'
                        : 'Webhook verified and processed',
                ],
            ];
        } catch (Throwable $e) {
            Log::channel('payment')->error('Gagal memproses log webhook Midtrans.', [
                'midtrans_order_id' => $orderId,
                'idempotency_key' => $idempotencyKey,
                'exception' => $e::class,
                'detail' => $this->sanitizeDiagnosticMessage($e->getMessage(), $serverKey),
            ]);

            return [
                'status_code' => 500,
                'body' => [
                    'message' => 'Unable to process webhook notification',
                ],
            ];
        }
    }

    public static function buildIdempotencyKey(
        string $orderId,
        ?string $transactionId,
        string $transactionStatus,
        string $statusCode,
        ?string $fraudStatus,
        string $grossAmount
    ): string {
        $normalized = implode('|', [
            self::GATEWAY,
            trim($orderId),
            trim((string) ($transactionId ?? '')),
            strtolower(trim($transactionStatus)),
            trim($statusCode),
            strtolower(trim((string) ($fraudStatus ?? ''))),
            trim($grossAmount),
        ]);

        return hash('sha256', $normalized);
    }

    /**
     * Menyimpan log webhook di dalam transaksi DB dengan perlindungan utama unique index `idempotency_key`.
     * Hanya `UniqueConstraintViolationException` yang dianggap sebagai duplikasi.
     *
     * @return array{duplicate: bool, log: ?PaymentWebhookLog}
     */
    protected function persistIdempotentLog(array $attributes, string $idempotencyKey): array
    {
        try {
            $log = DB::transaction(static function () use ($attributes): PaymentWebhookLog {
                return PaymentWebhookLog::create($attributes);
            });

            return [
                'duplicate' => false,
                'log' => $log,
            ];
        } catch (UniqueConstraintViolationException) {
            $existing = PaymentWebhookLog::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            return [
                'duplicate' => true,
                'log' => $existing,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{
     *     valid: bool,
     *     message?: string,
     *     order_id?: string,
     *     status_code?: string,
     *     gross_amount?: string,
     *     signature_key?: string
     * }
     */
    protected function extractAndValidateSignatureFields(array $rawPayload): array
    {
        $orderIdCheck = $this->validateRequiredStringField($rawPayload, 'order_id', self::MAX_ORDER_ID_LENGTH);
        if (! $orderIdCheck['valid']) {
            return ['valid' => false, 'message' => $orderIdCheck['message']];
        }

        $statusCodeCheck = $this->validateRequiredStringField($rawPayload, 'status_code', self::MAX_STATUS_CODE_LENGTH);
        if (! $statusCodeCheck['valid']) {
            return ['valid' => false, 'message' => $statusCodeCheck['message']];
        }

        $grossAmountCheck = $this->validateRequiredStringField($rawPayload, 'gross_amount', self::MAX_GROSS_AMOUNT_LENGTH);
        if (! $grossAmountCheck['valid']) {
            return ['valid' => false, 'message' => $grossAmountCheck['message']];
        }

        $signatureCheck = $this->validateRequiredStringField($rawPayload, 'signature_key', self::MAX_SIGNATURE_LENGTH);
        if (! $signatureCheck['valid']) {
            return ['valid' => false, 'message' => $signatureCheck['message']];
        }

        return [
            'valid' => true,
            'order_id' => $orderIdCheck['value'],
            'status_code' => $statusCodeCheck['value'],
            'gross_amount' => $grossAmountCheck['value'],
            'signature_key' => $signatureCheck['value'],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{
     *     valid: bool,
     *     message?: string,
     *     transaction_status?: string,
     *     transaction_id?: ?string,
     *     fraud_status?: ?string
     * }
     */
    protected function extractAndValidatePostSignatureFields(array $rawPayload): array
    {
        $statusCheck = $this->validateRequiredStringField(
            $rawPayload,
            'transaction_status',
            self::MAX_TRANSACTION_STATUS_LENGTH
        );
        if (! $statusCheck['valid']) {
            return ['valid' => false, 'message' => $statusCheck['message']];
        }

        $transactionIdCheck = $this->validateOptionalStringField(
            $rawPayload,
            'transaction_id',
            self::MAX_TRANSACTION_ID_LENGTH
        );
        if (! $transactionIdCheck['valid']) {
            return ['valid' => false, 'message' => $transactionIdCheck['message']];
        }

        $fraudStatusCheck = $this->validateOptionalStringField(
            $rawPayload,
            'fraud_status',
            self::MAX_FRAUD_STATUS_LENGTH
        );
        if (! $fraudStatusCheck['valid']) {
            return ['valid' => false, 'message' => $fraudStatusCheck['message']];
        }

        return [
            'valid' => true,
            'transaction_status' => $statusCheck['value'],
            'transaction_id' => $transactionIdCheck['value'],
            'fraud_status' => $fraudStatusCheck['value'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{valid: bool, message?: string, value?: string}
     */
    protected function validateRequiredStringField(array $payload, string $field, int $maxLength): array
    {
        if (! array_key_exists($field, $payload) || ! is_string($payload[$field])) {
            return [
                'valid' => false,
                'message' => "Field {$field} wajib berupa string yang valid.",
            ];
        }

        $value = $payload[$field];
        if (trim($value) === '') {
            return [
                'valid' => false,
                'message' => "Field {$field} tidak boleh kosong.",
            ];
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return [
                'valid' => false,
                'message' => "Field {$field} melebihi batas maksimal {$maxLength} karakter.",
            ];
        }

        return [
            'valid' => true,
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{valid: bool, message?: string, value?: ?string}
     */
    protected function validateOptionalStringField(array $payload, string $field, int $maxLength): array
    {
        if (! array_key_exists($field, $payload) || $payload[$field] === null) {
            return [
                'valid' => true,
                'value' => null,
            ];
        }

        if (! is_string($payload[$field])) {
            return [
                'valid' => false,
                'message' => "Field {$field} harus berupa string yang valid.",
            ];
        }

        $rawValue = $payload[$field];
        if (mb_strlen($rawValue, 'UTF-8') > $maxLength) {
            return [
                'valid' => false,
                'message' => "Field {$field} melebihi batas maksimal {$maxLength} karakter.",
            ];
        }

        $value = trim($rawValue);
        if ($value === '') {
            return [
                'valid' => true,
                'value' => null,
            ];
        }

        return [
            'valid' => true,
            'value' => $value,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function sanitizeHeaders(Request $request): array
    {
        $sanitized = [];

        foreach (self::ALLOWED_HEADERS as $headerName) {
            $value = $request->headers->get($headerName);
            if (! is_string($value)) {
                continue;
            }

            $cleaned = trim(preg_replace('/[\r\n\t]+/', ' ', $value) ?? '');
            if ($cleaned === '') {
                continue;
            }

            $sanitized[$headerName] = mb_substr($cleaned, 0, 255, 'UTF-8');
        }

        return $sanitized;
    }

    /**
     * Menyimpan hanya field notifikasi Midtrans yang relevan untuk pemrosesan Phase 7B.
     *
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    protected function sanitizePayload(array $rawPayload): array
    {
        $sanitized = [];

        foreach (self::ALLOWED_PAYLOAD_SCALAR_FIELDS as $field) {
            if (! array_key_exists($field, $rawPayload)) {
                continue;
            }

            $value = $rawPayload[$field];
            if (is_string($value)) {
                $sanitized[$field] = trim($value);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $sanitized[$field] = $value;
            }
        }

        if (is_array($rawPayload['va_numbers'] ?? null)) {
            $vaNumbers = [];
            foreach ($rawPayload['va_numbers'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $item = [];
                if (is_string($entry['bank'] ?? null) && trim($entry['bank']) !== '') {
                    $item['bank'] = trim($entry['bank']);
                }
                if (is_string($entry['va_number'] ?? null) && trim($entry['va_number']) !== '') {
                    $item['va_number'] = trim($entry['va_number']);
                }

                if ($item !== []) {
                    $vaNumbers[] = $item;
                }
            }

            if ($vaNumbers !== []) {
                $sanitized['va_numbers'] = $vaNumbers;
            }
        }

        if (is_array($rawPayload['refunds'] ?? null)) {
            $refunds = [];
            foreach ($rawPayload['refunds'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $item = [];
                foreach (['refund_chargeback_id', 'refund_amount', 'reason', 'refund_datetime'] as $refundField) {
                    $val = $entry[$refundField] ?? null;
                    if (is_string($val) && trim($val) !== '') {
                        $item[$refundField] = trim($val);
                    } elseif (is_int($val) || is_float($val)) {
                        $item[$refundField] = $val;
                    }
                }

                if ($item !== []) {
                    $refunds[] = $item;
                }
            }

            if ($refunds !== []) {
                $sanitized['refunds'] = $refunds;
            }
        }

        return $sanitized;
    }

    protected function sanitizeDiagnosticMessage(?string $message, string $serverKey): ?string
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
            '/\b(server[_-]?key|api[_-]?key|authorization|secret|token|password)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $cleaned
        ) ?? $cleaned;

        $cleaned = preg_replace('/[\r\n\t]+/', ' ', $cleaned) ?? $cleaned;

        return mb_substr(trim($cleaned), 0, 200, 'UTF-8');
    }
}
