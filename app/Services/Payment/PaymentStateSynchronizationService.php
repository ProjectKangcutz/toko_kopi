<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookLog;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Security\AuditLogService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentStateSynchronizationService
{
    public function __construct(
        protected InventoryReservationService $inventoryReservationService,
    ) {}

    /**
     * Menyinkronkan status Payment, Order, PaymentTransaction, OrderStatusHistory,
     * dan reservasi stok (commit/release) berdasarkan PaymentWebhookLog yang sudah
     * lolos verifikasi signature SHA-512 secara atomik dan idempoten.
     *
     * @return array{
     *     status: string,
     *     processed: bool
     * }
     */
    public function synchronizeFromWebhookLog(PaymentWebhookLog $webhookLog): array
    {
        return DB::transaction(function () use ($webhookLog): array {
            // 1. Kunci baris PaymentWebhookLog terlebih dahulu.
            /** @var ?PaymentWebhookLog $lockedLog */
            $lockedLog = PaymentWebhookLog::query()
                ->whereKey($webhookLog->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedLog) {
                return ['status' => 'log_not_found', 'processed' => false];
            }

            if ($lockedLog->processed) {
                return ['status' => 'already_processed', 'processed' => true];
            }

            if (! $lockedLog->signature_valid) {
                return ['status' => 'invalid_signature', 'processed' => false];
            }

            // 2. Kunci baris Payment berdasarkan midtrans_order_id.
            /** @var ?Payment $payment */
            $payment = Payment::query()
                ->where('midtrans_order_id', $lockedLog->midtrans_order_id)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                $lockedLog->processed = true;
                $lockedLog->processing_error = 'Data pembayaran tidak ditemukan untuk referensi transaksi ini.';
                $lockedLog->save();

                Log::channel('payment')->warning('Webhook Midtrans diterima untuk midtrans_order_id yang tidak terdaftar.', [
                    'webhook_log_id' => $lockedLog->id,
                    'midtrans_order_id' => $lockedLog->midtrans_order_id,
                    'transaction_status' => $lockedLog->transaction_status,
                ]);

                return ['status' => 'payment_not_found', 'processed' => true];
            }

            // 3. Kunci baris Order berdasarkan payment.order_id.
            /** @var ?Order $order */
            $order = Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                $lockedLog->processed = true;
                $lockedLog->processing_error = 'Data pesanan tidak ditemukan untuk pembayaran ini.';
                $lockedLog->save();

                Log::channel('payment')->warning('Webhook Midtrans kehilangan relasi Order pada Payment.', [
                    'webhook_log_id' => $lockedLog->id,
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                ]);

                return ['status' => 'order_not_found', 'processed' => true];
            }

            $payload = is_array($lockedLog->payload) ? $lockedLog->payload : [];
            $rawGrossAmount = $payload['gross_amount'] ?? null;

            // Validasi kecocokan gross_amount terhadap nominal Payment di server.
            if (! is_numeric($rawGrossAmount) || abs((float) $rawGrossAmount - (float) $payment->gross_amount) >= 0.01) {
                $lockedLog->processed = true;
                $lockedLog->processing_error = 'Nominal gross_amount webhook tidak sesuai dengan nilai pembayaran.';
                $lockedLog->save();

                Log::channel('security')->warning('Anomali gross_amount pada webhook Midtrans.', [
                    'webhook_log_id' => $lockedLog->id,
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'midtrans_order_id' => $lockedLog->midtrans_order_id,
                    'expected_gross_amount' => (float) $payment->gross_amount,
                    'received_gross_amount' => is_scalar($rawGrossAmount) ? (string) $rawGrossAmount : 'non_scalar',
                ]);

                return ['status' => 'amount_mismatch_quarantined', 'processed' => true];
            }

            $txStatus = strtolower(trim((string) $lockedLog->transaction_status));
            $fraudStatus = $this->extractOptionalNormalizedString($payload, 'fraud_status', 40);
            $paymentType = $this->extractOptionalNormalizedString($payload, 'payment_type', 60);
            $paymentChannel = $this->extractPaymentChannel($payload);
            $occurredAt = $this->resolveOccurredAt($payload, $lockedLog, $txStatus);

            // Catat setiap notifikasi terverifikasi ke payment_transactions (termasuk event lama out-of-order).
            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'transaction_id' => $lockedLog->transaction_id,
                'event_type' => mb_substr('webhook_'.$txStatus, 0, 60, 'UTF-8'),
                'status' => mb_substr($txStatus, 0, 40, 'UTF-8'),
                'gross_amount' => round((float) $rawGrossAmount, 2),
                'payment_type' => $paymentType ?? $payment->payment_type,
                'signature_verified' => true,
                'raw_payload' => $payload,
                'occurred_at' => $occurredAt,
            ]);

            $classification = $this->classifyWebhookEvent($txStatus, $fraudStatus);

            $paymentChanged = $this->applyPaymentTransition(
                $payment,
                $classification,
                $lockedLog->transaction_id,
                $paymentType,
                $paymentChannel,
                $fraudStatus,
                $occurredAt
            );

            $orderChanged = $this->applyOrderTransition(
                $order,
                $payment,
                $classification,
                $occurredAt
            );

            $inventorySync = $this->synchronizeInventoryReservation(
                $order,
                $classification,
                $txStatus
            );

            $lockedLog->processed = true;
            $lockedLog->processing_error = null;
            $lockedLog->save();

            AuditLogService::record(
                'webhook',
                null,
                'payment.synchronized',
                "Sinkronisasi webhook Midtrans ({$txStatus}) untuk {$payment->midtrans_order_id}.",
                $payment,
                [
                    'webhook_log_id' => $lockedLog->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'midtrans_order_id' => $payment->midtrans_order_id,
                    'transaction_status' => $txStatus,
                    'fraud_status' => $fraudStatus,
                    'payment_status' => $payment->status,
                    'order_payment_status' => $order->payment_status,
                    'order_status' => $order->order_status,
                    'payment_changed' => $paymentChanged,
                    'order_changed' => $orderChanged,
                    'inventory_action' => $inventorySync['action'],
                    'inventory_affected_count' => $inventorySync['count'],
                ]
            );

            return [
                'status' => 'synchronized',
                'processed' => true,
            ];
        }, 3);
    }

    /**
     * Menyinkronkan status Payment, Order, PaymentTransaction, OrderStatusHistory,
     * dan reservasi stok berdasarkan hasil verifikasi langsung server-to-server ke
     * Midtrans Core API (/v2/{order_id}/status).
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: string,
     *     transaction_status: string,
     *     payment_changed: bool,
     *     order_changed: bool,
     *     inventory_action: string
     * }
     */
    public function synchronizeFromCoreApiStatus(Payment $payment, array $payload): array
    {
        return DB::transaction(function () use ($payment, $payload): array {
            /** @var ?Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPayment) {
                return [
                    'status' => 'payment_not_found',
                    'transaction_status' => '',
                    'payment_changed' => false,
                    'order_changed' => false,
                    'inventory_action' => 'none',
                ];
            }

            /** @var ?Order $order */
            $order = Order::query()
                ->whereKey($lockedPayment->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return [
                    'status' => 'order_not_found',
                    'transaction_status' => '',
                    'payment_changed' => false,
                    'order_changed' => false,
                    'inventory_action' => 'none',
                ];
            }

            $rawGrossAmount = $payload['gross_amount'] ?? $lockedPayment->gross_amount;
            if (! is_numeric($rawGrossAmount) || abs((float) $rawGrossAmount - (float) $lockedPayment->gross_amount) >= 0.01) {
                Log::channel('security')->warning('Anomali gross_amount pada verifikasi status Midtrans Core API.', [
                    'payment_id' => $lockedPayment->id,
                    'order_id' => $order->id,
                    'midtrans_order_id' => $lockedPayment->midtrans_order_id,
                    'expected_gross_amount' => (float) $lockedPayment->gross_amount,
                    'received_gross_amount' => is_scalar($rawGrossAmount) ? (string) $rawGrossAmount : 'non_scalar',
                ]);

                return [
                    'status' => 'amount_mismatch',
                    'transaction_status' => '',
                    'payment_changed' => false,
                    'order_changed' => false,
                    'inventory_action' => 'none',
                ];
            }

            $txStatus = strtolower(trim((string) ($payload['transaction_status'] ?? '')));
            if ($txStatus === '') {
                return [
                    'status' => 'invalid_status',
                    'transaction_status' => '',
                    'payment_changed' => false,
                    'order_changed' => false,
                    'inventory_action' => 'none',
                ];
            }

            $transactionId = $this->extractOptionalNormalizedStringPreserveCase($payload, 'transaction_id', 120);
            $fraudStatus = $this->extractOptionalNormalizedString($payload, 'fraud_status', 40);
            $paymentType = $this->extractOptionalNormalizedString($payload, 'payment_type', 60);
            $paymentChannel = $this->extractPaymentChannel($payload);
            $occurredAt = $this->resolveOccurredAt($payload, null, $txStatus);

            $sanitizedPayload = $payload;
            unset($sanitizedPayload['signature_key']);

            PaymentTransaction::create([
                'payment_id' => $lockedPayment->id,
                'transaction_id' => $transactionId,
                'event_type' => mb_substr('status_check_'.$txStatus, 0, 60, 'UTF-8'),
                'status' => mb_substr($txStatus, 0, 40, 'UTF-8'),
                'gross_amount' => round((float) $rawGrossAmount, 2),
                'payment_type' => $paymentType ?? $lockedPayment->payment_type,
                'signature_verified' => true,
                'raw_payload' => $sanitizedPayload,
                'occurred_at' => $occurredAt,
            ]);

            $classification = $this->classifyWebhookEvent($txStatus, $fraudStatus);

            $paymentChanged = $this->applyPaymentTransition(
                $lockedPayment,
                $classification,
                $transactionId,
                $paymentType,
                $paymentChannel,
                $fraudStatus,
                $occurredAt
            );

            $orderChanged = $this->applyOrderTransition(
                $order,
                $lockedPayment,
                $classification,
                $occurredAt,
                'verifikasi status Midtrans',
                'system'
            );

            $inventorySync = $this->synchronizeInventoryReservation(
                $order,
                $classification,
                $txStatus,
                'verifikasi status Midtrans'
            );

            AuditLogService::record(
                'system',
                null,
                'payment.status_synced',
                "Sinkronisasi status Core API Midtrans ({$txStatus}) untuk {$lockedPayment->midtrans_order_id}.",
                $lockedPayment,
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'midtrans_order_id' => $lockedPayment->midtrans_order_id,
                    'transaction_status' => $txStatus,
                    'fraud_status' => $fraudStatus,
                    'payment_status' => $lockedPayment->status,
                    'order_payment_status' => $order->payment_status,
                    'order_status' => $order->order_status,
                    'payment_changed' => $paymentChanged,
                    'order_changed' => $orderChanged,
                    'inventory_action' => $inventorySync['action'],
                    'inventory_affected_count' => $inventorySync['count'],
                ]
            );

            return [
                'status' => 'synchronized',
                'transaction_status' => $txStatus,
                'payment_changed' => $paymentChanged,
                'order_changed' => $orderChanged,
                'inventory_action' => $inventorySync['action'],
            ];
        }, 3);
    }

    /**
     * @return array{
     *     category: string,
     *     target_payment_status: ?string,
     *     target_order_payment_status: ?string,
     *     is_paid_event: bool
     * }
     */
    protected function classifyWebhookEvent(string $txStatus, ?string $fraudStatus): array
    {
        return match ($txStatus) {
            'settlement' => [
                'category' => 'paid',
                'target_payment_status' => 'settlement',
                'target_order_payment_status' => 'paid',
                'is_paid_event' => true,
            ],
            'capture' => match ($fraudStatus) {
                'accept' => [
                    'category' => 'paid',
                    'target_payment_status' => 'capture',
                    'target_order_payment_status' => 'paid',
                    'is_paid_event' => true,
                ],
                'deny' => [
                    'category' => 'failure',
                    'target_payment_status' => 'deny',
                    'target_order_payment_status' => 'failed',
                    'is_paid_event' => false,
                ],
                default => [
                    'category' => 'challenge',
                    'target_payment_status' => 'capture',
                    'target_order_payment_status' => 'pending',
                    'is_paid_event' => false,
                ],
            },
            'pending' => [
                'category' => 'pending',
                'target_payment_status' => 'pending',
                'target_order_payment_status' => 'pending',
                'is_paid_event' => false,
            ],
            'deny' => [
                'category' => 'failure',
                'target_payment_status' => 'deny',
                'target_order_payment_status' => 'failed',
                'is_paid_event' => false,
            ],
            'cancel' => [
                'category' => 'failure',
                'target_payment_status' => 'cancel',
                'target_order_payment_status' => 'failed',
                'is_paid_event' => false,
            ],
            'expire' => [
                'category' => 'failure',
                'target_payment_status' => 'expire',
                'target_order_payment_status' => 'expired',
                'is_paid_event' => false,
            ],
            'refund' => [
                'category' => 'refund',
                'target_payment_status' => 'refund',
                'target_order_payment_status' => 'refunded',
                'is_paid_event' => false,
            ],
            'partial_refund' => [
                'category' => 'partial_refund',
                'target_payment_status' => 'partial_refund',
                'target_order_payment_status' => 'partially_refunded',
                'is_paid_event' => false,
            ],
            default => [
                'category' => 'other',
                'target_payment_status' => null,
                'target_order_payment_status' => null,
                'is_paid_event' => false,
            ],
        };
    }

    /**
     * Memperbarui status Payment secara monotonik (tidak mengizinkan downgrade status).
     *
     * @param  array{category: string, target_payment_status: ?string, target_order_payment_status: ?string, is_paid_event: bool}  $classification
     */
    protected function applyPaymentTransition(
        Payment $payment,
        array $classification,
        ?string $transactionId,
        ?string $paymentType,
        ?string $paymentChannel,
        ?string $fraudStatus,
        CarbonInterface $occurredAt
    ): bool {
        $category = $classification['category'];
        $targetStatus = $classification['target_payment_status'];

        if ($targetStatus === null) {
            return false;
        }

        $isCurrentlyPaid = $this->isPaymentPaid($payment);
        $isCurrentlyPendingVerificationCapture = $this->isPaymentPendingVerificationCapture($payment);
        $isCurrentlyTerminalFailure = in_array($payment->status, ['deny', 'cancel', 'expire'], true);
        $isCurrentlyRefunded = in_array($payment->status, ['refund', 'partial_refund'], true);

        $shouldTransition = match ($category) {
            'pending' => $payment->status === 'pending',
            'challenge' => $payment->status === 'pending' || $isCurrentlyPendingVerificationCapture,
            'failure' => ! $isCurrentlyPaid && ! $isCurrentlyRefunded && ($payment->status === 'pending' || $isCurrentlyPendingVerificationCapture || $isCurrentlyTerminalFailure),
            'paid' => ! $isCurrentlyRefunded && (! $isCurrentlyPaid || ($targetStatus === 'settlement' && $payment->status === 'capture')),
            'partial_refund' => $isCurrentlyPaid || $payment->status === 'partial_refund',
            'refund' => $isCurrentlyPaid || $payment->status === 'partial_refund' || $payment->status === 'refund',
            default => false,
        };

        if (! $shouldTransition) {
            return false;
        }

        $dirty = false;

        if ($payment->status !== $targetStatus) {
            $payment->status = $targetStatus;
            $dirty = true;
        }

        if ($transactionId !== null && $payment->midtrans_transaction_id !== $transactionId) {
            $payment->midtrans_transaction_id = $transactionId;
            $dirty = true;
        }

        if ($paymentType !== null && $payment->payment_type !== $paymentType) {
            $payment->payment_type = $paymentType;
            $dirty = true;
        }

        if ($paymentChannel !== null && $payment->payment_channel !== $paymentChannel) {
            $payment->payment_channel = $paymentChannel;
            $dirty = true;
        }

        if ($fraudStatus !== null && $payment->fraud_status !== $fraudStatus) {
            $payment->fraud_status = $fraudStatus;
            $dirty = true;
        }

        if ($classification['is_paid_event'] && $payment->paid_at === null) {
            $payment->paid_at = $occurredAt;
            $dirty = true;
        }

        if ($dirty) {
            $payment->save();
        }

        return $dirty;
    }

    /**
     * Memperbarui status pembayaran & status pesanan pada Order secara aman
     * dengan perlindungan multi-payment (P1 vs P2) dan anti-regresi.
     *
     * @param  array{category: string, target_payment_status: ?string, target_order_payment_status: ?string, is_paid_event: bool}  $classification
     */
    protected function applyOrderTransition(
        Order $order,
        Payment $payment,
        array $classification,
        CarbonInterface $occurredAt,
        string $sourceLabel = 'webhook Midtrans',
        string $actorType = 'webhook'
    ): bool {
        $category = $classification['category'];
        $now = now();

        $isOrderClosed = in_array($order->order_status, ['cancelled', 'refunded'], true)
            || $order->payment_status === 'refunded';

        $isOrderFulfillmentOrCompleted = in_array(
            $order->order_status,
            ['paid', 'processing', 'shipped', 'delivered', 'completed'],
            true
        );

        $isOrderAlreadyPaid = $order->payment_status === 'paid'
            || $order->payment_status === 'partially_refunded'
            || $isOrderFulfillmentOrCompleted;

        $changed = false;

        if ($category === 'paid') {
            if ($isOrderClosed) {
                return false;
            }

            if ($order->payment_status !== 'paid' && $order->payment_status !== 'partially_refunded') {
                $fromPaymentStatus = $order->payment_status;
                $order->payment_status = 'paid';
                if ($order->paid_at === null) {
                    $order->paid_at = $occurredAt;
                }
                $changed = true;

                $this->recordStatusHistory(
                    $order,
                    'payment',
                    $fromPaymentStatus,
                    'paid',
                    "Pembayaran dikonfirmasi lunas melalui {$sourceLabel} ({$payment->midtrans_order_id}).",
                    $now,
                    $actorType
                );
            } elseif ($order->paid_at === null) {
                $order->paid_at = $occurredAt;
                $changed = true;
            }

            if (in_array($order->order_status, ['pending', 'awaiting_payment'], true)) {
                $fromOrderStatus = $order->order_status;
                $order->order_status = 'paid';
                $changed = true;

                $this->recordStatusHistory(
                    $order,
                    'order',
                    $fromOrderStatus,
                    'paid',
                    "Status pesanan diperbarui menjadi lunas dari {$sourceLabel} ({$payment->midtrans_order_id}).",
                    $now,
                    $actorType
                );
            }
        } elseif ($category === 'challenge' || $category === 'pending') {
            if ($isOrderClosed || $isOrderAlreadyPaid) {
                return false;
            }

            if ($this->hasNewerOrOtherActivePaidPayment($order, $payment)) {
                return false;
            }

            if ($order->payment_status === 'unpaid') {
                $fromPaymentStatus = $order->payment_status;
                $order->payment_status = 'pending';
                $changed = true;

                $note = $category === 'challenge'
                    ? "Pembayaran berada dalam peninjauan keamanan (challenge) Midtrans ({$payment->midtrans_order_id})."
                    : "Menunggu penyelesaian pembayaran pelanggan melalui Midtrans ({$payment->midtrans_order_id}).";

                $this->recordStatusHistory($order, 'payment', $fromPaymentStatus, 'pending', $note, $now, $actorType);
            }

            if ($order->order_status === 'pending') {
                $fromOrderStatus = $order->order_status;
                $order->order_status = 'awaiting_payment';
                $changed = true;

                $this->recordStatusHistory(
                    $order,
                    'order',
                    $fromOrderStatus,
                    'awaiting_payment',
                    "Menunggu pembayaran melalui Midtrans ({$payment->midtrans_order_id}).",
                    $now,
                    $actorType
                );
            }
        } elseif ($category === 'failure') {
            if ($isOrderClosed || $isOrderAlreadyPaid) {
                return false;
            }

            // Lindungi Order jika sudah ada Payment aktif/lunas atau kegagalan sudah tercatat dari Payment lebih baru.
            if ($this->hasNewerOrOtherActivePaidPayment($order, $payment, true)) {
                return false;
            }

            $targetOrderPaymentStatus = $classification['target_order_payment_status'];
            if (
                $targetOrderPaymentStatus !== null
                && $order->payment_status !== $targetOrderPaymentStatus
                && in_array($order->payment_status, ['unpaid', 'pending', 'failed', 'expired'], true)
            ) {
                $fromPaymentStatus = $order->payment_status;
                $order->payment_status = $targetOrderPaymentStatus;
                $changed = true;

                // Catatan: order_status TIDAK ditutup/dibatalkan paksa saat payment_due_at masih di masa depan
                // agar pelanggan tetap dapat melakukan percobaan pembayaran berikutnya (P2).
                $this->recordStatusHistory(
                    $order,
                    'payment',
                    $fromPaymentStatus,
                    $targetOrderPaymentStatus,
                    "Percobaan pembayaran {$payment->midtrans_order_id} berstatus {$payment->status} dari {$sourceLabel}.",
                    $now,
                    $actorType
                );
            }
        } elseif ($category === 'partial_refund') {
            if (
                $order->payment_status !== 'refunded'
                && $order->payment_status !== 'partially_refunded'
                && ($isOrderAlreadyPaid || $payment->status === 'partial_refund')
            ) {
                $fromPaymentStatus = $order->payment_status;
                $order->payment_status = 'partially_refunded';
                $changed = true;

                $this->recordStatusHistory(
                    $order,
                    'payment',
                    $fromPaymentStatus,
                    'partially_refunded',
                    "Pembayaran direfund sebagian melalui {$sourceLabel} ({$payment->midtrans_order_id}).",
                    $now,
                    $actorType
                );
            }
        } elseif ($category === 'refund') {
            if ($order->payment_status !== 'refunded') {
                $fromPaymentStatus = $order->payment_status;
                $order->payment_status = 'refunded';
                $changed = true;

                $this->recordStatusHistory(
                    $order,
                    'payment',
                    $fromPaymentStatus,
                    'refunded',
                    "Pembayaran direfund penuh melalui {$sourceLabel} ({$payment->midtrans_order_id}).",
                    $now,
                    $actorType
                );
            }

            if ($order->order_status !== 'refunded' && $order->order_status !== 'cancelled') {
                $fromOrderStatus = $order->order_status;
                $order->order_status = 'refunded';
                $changed = true;

                $this->recordStatusHistory(
                    $order,
                    'order',
                    $fromOrderStatus,
                    'refunded',
                    "Pesanan ditandai refund penuh berdasarkan {$sourceLabel} ({$payment->midtrans_order_id}).",
                    $now,
                    $actorType
                );
            }
        }

        if ($changed) {
            $order->save();
        }

        return $changed;
    }

    protected function hasNewerOrOtherActivePaidPayment(
        Order $order,
        Payment $currentPayment,
        bool $allowFailureIfOrderStillPending = false
    ): bool {
        return Payment::query()
            ->where('order_id', $order->id)
            ->where('id', '!=', $currentPayment->id)
            ->where(function ($query) use ($currentPayment, $order, $allowFailureIfOrderStillPending): void {
                $query->whereIn('status', ['settlement', 'capture', 'refund', 'partial_refund'])
                    ->orWhere(function ($pendingQuery): void {
                        $pendingQuery->where('status', 'pending')
                            ->where(function ($expiryQuery): void {
                                $expiryQuery->whereNull('expired_at')
                                    ->orWhere('expired_at', '>', now());
                            });
                    });

                if (
                    ! $allowFailureIfOrderStillPending
                    || in_array($order->payment_status, ['failed', 'expired'], true)
                ) {
                    $query->orWhere('id', '>', $currentPayment->id);
                }
            })
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Mengoordinasikan commit atau release reservasi stok dalam transaksi DB yang sama.
     *
     * @param  array{category: string, target_payment_status: ?string, target_order_payment_status: ?string, is_paid_event: bool}  $classification
     * @return array{action: string, count: int}
     */
    protected function synchronizeInventoryReservation(
        Order $order,
        array $classification,
        string $txStatus,
        string $sourceLabel = 'webhook Midtrans'
    ): array {
        if ($classification['is_paid_event']) {
            $isOrderClosed = in_array($order->order_status, ['cancelled', 'refunded'], true)
                || $order->payment_status === 'refunded';

            if ($isOrderClosed || $order->payment_status !== 'paid') {
                return ['action' => 'none', 'count' => 0];
            }

            $committedCount = $this->inventoryReservationService->commitForOrder($order);

            return [
                'action' => $committedCount > 0 ? 'committed' : 'commit_noop',
                'count' => $committedCount,
            ];
        }

        if ($classification['category'] !== 'failure') {
            return ['action' => 'none', 'count' => 0];
        }

        if ($this->isOrderPaidOrFulfillmentOrRefunded($order)) {
            return ['action' => 'none', 'count' => 0];
        }

        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->get();

        if ($this->hasAnyPaidPayment($payments) || $this->hasAnyActivePayment($payments)) {
            return ['action' => 'none', 'count' => 0];
        }

        $targetReleaseStatus = $this->resolveEligibleReleaseStatus($order);
        if ($targetReleaseStatus === null) {
            return ['action' => 'none', 'count' => 0];
        }

        $notes = $targetReleaseStatus === 'released'
            ? "Pelepasan reservasi stok pesanan dibatalkan {$order->order_number} melalui {$sourceLabel} ({$txStatus})."
            : "Pelepasan reservasi stok pesanan kedaluwarsa {$order->order_number} melalui {$sourceLabel} ({$txStatus}).";

        $releasedCount = $this->inventoryReservationService->releaseForOrder(
            $order,
            $targetReleaseStatus,
            $notes
        );

        return [
            'action' => $releasedCount > 0 ? $targetReleaseStatus : 'release_noop',
            'count' => $releasedCount,
        ];
    }

    protected function resolveEligibleReleaseStatus(Order $order): ?string
    {
        if ($order->order_status === 'cancelled') {
            return 'released';
        }

        if (
            in_array($order->order_status, ['pending', 'awaiting_payment'], true)
            && $order->payment_due_at !== null
            && $order->payment_due_at->isPast()
        ) {
            return 'expired';
        }

        return null;
    }

    protected function isOrderPaidOrFulfillmentOrRefunded(Order $order): bool
    {
        return in_array($order->payment_status, ['paid', 'refunded', 'partially_refunded'], true)
            || in_array(
                $order->order_status,
                ['paid', 'processing', 'shipped', 'delivered', 'completed', 'refunded'],
                true
            )
            || $order->paid_at !== null;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    protected function hasAnyPaidPayment(Collection $payments): bool
    {
        foreach ($payments as $item) {
            if (
                in_array($item->status, ['settlement', 'refund', 'partial_refund'], true)
                || $this->isPaymentPaid($item)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    protected function hasAnyActivePayment(Collection $payments): bool
    {
        foreach ($payments as $item) {
            if ($item->status === 'pending') {
                if ($item->expired_at === null || $item->expired_at->isFuture()) {
                    return true;
                }
            }

            if ($this->isPaymentPendingVerificationCapture($item)) {
                return true;
            }
        }

        return false;
    }

    protected function isPaymentPaid(Payment $payment): bool
    {
        if ($payment->status === 'settlement') {
            return true;
        }

        if ($payment->status === 'capture') {
            return strtolower(trim((string) $payment->fraud_status)) === 'accept';
        }

        return false;
    }

    protected function isPaymentPendingVerificationCapture(Payment $payment): bool
    {
        return $payment->status === 'capture' && ! $this->isPaymentPaid($payment);
    }

    protected function recordStatusHistory(
        Order $order,
        string $statusType,
        ?string $fromStatus,
        string $toStatus,
        string $notes,
        CarbonInterface $timestamp,
        string $actorType = 'webhook'
    ): void {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status_type' => $statusType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'actor_type' => $actorType,
            'actor_id' => null,
            'created_at' => $timestamp,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveOccurredAt(array $payload, ?PaymentWebhookLog $log, string $txStatus): CarbonInterface
    {
        $candidates = $txStatus === 'settlement'
            ? [$payload['settlement_time'] ?? null, $payload['transaction_time'] ?? null]
            : [$payload['transaction_time'] ?? null, $payload['settlement_time'] ?? null];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $raw = trim($candidate);
            if ($raw === '') {
                continue;
            }

            try {
                $hasExplicitOffset = (bool) preg_match('/(Z|[+\-]\d{2}:?\d{2}|GMT|UTC)$/i', $raw);
                $parsed = $hasExplicitOffset
                    ? Carbon::parse($raw)
                    : Carbon::parse($raw, 'Asia/Jakarta');

                return $parsed->timezone(config('app.timezone', 'UTC'));
            } catch (Throwable) {
                continue;
            }
        }

        return $log?->received_at ?? now();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOptionalNormalizedString(array $payload, string $field, int $maxLength): ?string
    {
        $val = $payload[$field] ?? null;
        if (! is_string($val)) {
            return null;
        }

        $cleaned = strtolower(trim($val));
        if ($cleaned === '') {
            return null;
        }

        return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractOptionalNormalizedStringPreserveCase(array $payload, string $field, int $maxLength): ?string
    {
        $val = $payload[$field] ?? null;
        if (! is_string($val)) {
            return null;
        }

        $cleaned = trim($val);
        if ($cleaned === '') {
            return null;
        }

        return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractPaymentChannel(array $payload): ?string
    {
        $vaBank = $payload['va_numbers'][0]['bank'] ?? null;
        if (is_string($vaBank) && trim($vaBank) !== '') {
            return mb_substr(strtolower(trim($vaBank)), 0, 60, 'UTF-8');
        }

        if (is_string($payload['permata_va_number'] ?? null) && trim((string) $payload['permata_va_number']) !== '') {
            return 'permata';
        }

        foreach (['bank', 'store', 'acquirer', 'issuer'] as $key) {
            $val = $payload[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                return mb_substr(strtolower(trim($val)), 0, 60, 'UTF-8');
            }
        }

        if (
            (is_string($payload['bill_key'] ?? null) && trim((string) $payload['bill_key']) !== '')
            || (is_string($payload['biller_code'] ?? null) && trim((string) $payload['biller_code']) !== '')
        ) {
            return 'mandiri_bill';
        }

        return $this->extractOptionalNormalizedString($payload, 'payment_type', 60);
    }
}
