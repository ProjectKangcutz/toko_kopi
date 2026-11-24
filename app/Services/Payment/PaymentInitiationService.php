<?php

namespace App\Services\Payment;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Security\AuditLogService;
use App\Services\Setting\StoreSettingService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PaymentInitiationService
{
    public function __construct(
        protected MidtransClient $midtransClient,
        protected StoreSettingService $settingService,
        protected PaymentStateSynchronizationService $synchronizationService,
    ) {}

    /**
     * Memulai atau melanjutkan sesi pembayaran Midtrans Snap untuk pesanan milik pelanggan.
     * Tidak pernah memanggil HTTP eksternal di dalam DB transaction, tidak menerima
     * nominal/status dari request browser, dan memastikan expiry Snap <= payment_due_at.
     *
     * @return array{
     *     order: Order,
     *     payment: Payment,
     *     snap_token: string,
     *     snap_redirect_url: ?string,
     *     client_key: string,
     *     snap_js_url: string,
     *     reused_token: bool
     * }
     */
    public function initiateForOrder(Customer $customer, Order $order): array
    {
        if ($customer->status !== 'active') {
            throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
        }

        Gate::forUser($customer)->authorize('view', $order);

        // Pastikan integrasi Midtrans aktif dan terkonfigurasi sebelum menyentuh DB / status order.
        $config = $this->midtransClient->resolveConfig();
        $snapJsUrl = $this->midtransClient->snapJsUrl($config['is_production']);

        $lock = Cache::lock('payment:initiate:order:'.$order->id, 15);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw MidtransPaymentException::lockBusy();
        }

        try {
            $prepared = DB::transaction(function () use ($customer, $order, $config): array {
                $lockedCustomer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if ($lockedCustomer->status !== 'active') {
                    throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
                }

                $lockedOrder = Order::with(['items', 'address'])
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedOrder->customer_id !== (int) $lockedCustomer->id) {
                    throw new AuthorizationException('Anda tidak memiliki akses ke pesanan ini.');
                }

                $this->assertOrderEligibleForPayment($lockedOrder);

                $existingPayments = Payment::where('order_id', $lockedOrder->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                $reusablePayment = $this->findReusablePendingPayment($existingPayments, $lockedOrder);
                if ($reusablePayment !== null) {
                    $this->transitionOrderToAwaitingPaymentIfNeeded(
                        $lockedOrder,
                        $lockedCustomer,
                        $reusablePayment->midtrans_order_id
                    );

                    return [
                        'reused' => true,
                        'order' => $lockedOrder->fresh(),
                        'payment' => $reusablePayment,
                    ];
                }

                $expiryPlan = $this->calculateSnapExpiry($lockedOrder);
                $midtransOrderId = $this->generateUniqueMidtransOrderId(
                    $lockedOrder,
                    $existingPayments->count() + 1
                );
                $snapPayload = $this->buildSnapPayload(
                    $lockedOrder,
                    $midtransOrderId,
                    $expiryPlan['now'],
                    $expiryPlan['expiry_minutes'],
                    $config['is_3ds']
                );

                return [
                    'reused' => false,
                    'midtrans_order_id' => $midtransOrderId,
                    'snap_payload' => $snapPayload,
                    'snap_expires_at' => $expiryPlan['expires_at'],
                    'expiry_minutes' => $expiryPlan['expiry_minutes'],
                    'expected_gross_amount' => (int) round((float) $lockedOrder->grand_total),
                ];
            });

            if ($prepared['reused']) {
                /** @var Order $reusedOrder */
                $reusedOrder = $prepared['order'];
                /** @var Payment $reusedPayment */
                $reusedPayment = $prepared['payment'];

                return [
                    'order' => $reusedOrder,
                    'payment' => $reusedPayment,
                    'snap_token' => (string) $reusedPayment->snap_token,
                    'snap_redirect_url' => $reusedPayment->snap_redirect_url,
                    'client_key' => $config['client_key'],
                    'snap_js_url' => $snapJsUrl,
                    'reused_token' => true,
                ];
            }

            // Panggilan HTTP eksternal dijalankan di luar DB transaction.
            $snapResponse = $this->midtransClient->createSnapTransaction($prepared['snap_payload']);

            $persisted = DB::transaction(function () use ($customer, $order, $prepared, $snapResponse): array {
                $lockedCustomer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if ($lockedCustomer->status !== 'active') {
                    throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
                }

                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedOrder->customer_id !== (int) $lockedCustomer->id) {
                    throw new AuthorizationException('Anda tidak memiliki akses ke pesanan ini.');
                }

                $this->assertOrderEligibleForPayment($lockedOrder);

                $existingPayments = Payment::where('order_id', $lockedOrder->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get();

                $reusablePayment = $this->findReusablePendingPayment($existingPayments, $lockedOrder);
                if ($reusablePayment !== null) {
                    $this->transitionOrderToAwaitingPaymentIfNeeded(
                        $lockedOrder,
                        $lockedCustomer,
                        $reusablePayment->midtrans_order_id
                    );

                    return [
                        'reused' => true,
                        'order' => $lockedOrder->fresh(),
                        'payment' => $reusablePayment,
                    ];
                }

                $payment = Payment::create([
                    'order_id' => $lockedOrder->id,
                    'payment_gateway' => 'midtrans',
                    'midtrans_order_id' => $prepared['midtrans_order_id'],
                    'snap_token' => $snapResponse['token'],
                    'snap_redirect_url' => $snapResponse['redirect_url'] !== null
                        ? Str::limit($snapResponse['redirect_url'], 500, '')
                        : null,
                    'gross_amount' => $lockedOrder->grand_total,
                    'status' => 'pending',
                    'expired_at' => $prepared['snap_expires_at'],
                ]);

                PaymentTransaction::create([
                    'payment_id' => $payment->id,
                    'transaction_id' => null,
                    'event_type' => 'snap_created',
                    'status' => 'pending',
                    'gross_amount' => $lockedOrder->grand_total,
                    'payment_type' => null,
                    'signature_verified' => true,
                    'raw_payload' => [
                        'midtrans_order_id' => $prepared['midtrans_order_id'],
                        'expiry_minutes' => $prepared['expiry_minutes'],
                    ],
                    'occurred_at' => now(),
                ]);

                $this->transitionOrderToAwaitingPaymentIfNeeded(
                    $lockedOrder,
                    $lockedCustomer,
                    $prepared['midtrans_order_id']
                );

                AuditLogService::record(
                    'customer',
                    $lockedCustomer->id,
                    'payment.initiated',
                    "Menginisiasi pembayaran Midtrans Snap untuk pesanan {$lockedOrder->order_number}.",
                    $payment,
                    [
                        'order_id' => $lockedOrder->id,
                        'order_number' => $lockedOrder->order_number,
                        'midtrans_order_id' => $prepared['midtrans_order_id'],
                        'gross_amount' => (float) $lockedOrder->grand_total,
                    ]
                );

                return [
                    'reused' => false,
                    'order' => $lockedOrder->fresh(),
                    'payment' => $payment,
                ];
            });

            /** @var Order $finalOrder */
            $finalOrder = $persisted['order'];
            /** @var Payment $finalPayment */
            $finalPayment = $persisted['payment'];

            return [
                'order' => $finalOrder,
                'payment' => $finalPayment,
                'snap_token' => (string) $finalPayment->snap_token,
                'snap_redirect_url' => $finalPayment->snap_redirect_url,
                'client_key' => $config['client_key'],
                'snap_js_url' => $snapJsUrl,
                'reused_token' => (bool) $persisted['reused'],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Memeriksa status pembayaran terbaru langsung ke Midtrans Core API server-side
     * (sebagai fallback jika webhook tertunda atau dijalankan pada domain lokal tanpa tunnel).
     *
     * @return array{
     *     order: Order,
     *     payment: ?Payment,
     *     synced: bool,
     *     found_on_midtrans: bool,
     *     is_paid: bool,
     *     transaction_status: ?string
     * }
     */
    public function syncOrderPaymentStatusFromMidtrans(Customer $customer, Order $order): array
    {
        if ($customer->status !== 'active') {
            throw new AuthorizationException('Akun pelanggan Anda sedang dinonaktifkan.');
        }

        Gate::forUser($customer)->authorize('view', $order);

        $this->midtransClient->resolveConfig();

        $lock = Cache::lock('payment:initiate:order:'.$order->id, 15);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw MidtransPaymentException::lockBusy();
        }

        try {
            $freshOrder = Order::query()->findOrFail($order->id);
            if ((int) $freshOrder->customer_id !== (int) $customer->id) {
                throw new AuthorizationException('Anda tidak memiliki akses ke pesanan ini.');
            }

            /** @var ?Payment $latestPayment */
            $latestPayment = Payment::query()
                ->where('order_id', $freshOrder->id)
                ->orderByDesc('id')
                ->first();

            if ($freshOrder->payment_status === 'paid' || $freshOrder->order_status === 'paid') {
                return [
                    'order' => $freshOrder,
                    'payment' => $latestPayment,
                    'synced' => true,
                    'found_on_midtrans' => true,
                    'is_paid' => true,
                    'transaction_status' => $latestPayment?->status ?? 'settlement',
                ];
            }

            if (! $latestPayment || trim((string) $latestPayment->midtrans_order_id) === '') {
                throw MidtransPaymentException::invalidOrderState(
                    'Belum ada transaksi pembayaran Midtrans untuk pesanan ini. Silakan klik Bayar Sekarang terlebih dahulu.'
                );
            }

            try {
                $corePayload = $this->midtransClient->getTransactionStatus((string) $latestPayment->midtrans_order_id);
            } catch (MidtransPaymentException $e) {
                if (($e->diagnostics()['category'] ?? null) === 'upstream_not_found') {
                    return [
                        'order' => $freshOrder,
                        'payment' => $latestPayment,
                        'synced' => false,
                        'found_on_midtrans' => false,
                        'is_paid' => false,
                        'transaction_status' => null,
                    ];
                }

                throw $e;
            }

            $returnedOrderId = trim((string) ($corePayload['order_id'] ?? ''));
            if ($returnedOrderId !== '' && $returnedOrderId !== $latestPayment->midtrans_order_id) {
                throw MidtransPaymentException::malformedResponse([
                    'category' => 'order_id_mismatch',
                ]);
            }

            $txStatus = strtolower(trim((string) ($corePayload['transaction_status'] ?? '')));
            if ($txStatus === '') {
                throw MidtransPaymentException::malformedResponse([
                    'category' => 'missing_transaction_status',
                ]);
            }

            $syncResult = $this->synchronizationService->synchronizeFromCoreApiStatus($latestPayment, $corePayload);
            if (in_array($syncResult['status'], ['amount_mismatch', 'invalid_status'], true)) {
                throw MidtransPaymentException::malformedResponse([
                    'category' => $syncResult['status'],
                ]);
            }

            $updatedOrder = $freshOrder->fresh();
            $updatedPayment = $latestPayment->fresh();

            return [
                'order' => $updatedOrder,
                'payment' => $updatedPayment,
                'synced' => $syncResult['status'] === 'synchronized',
                'found_on_midtrans' => true,
                'is_paid' => $updatedOrder->payment_status === 'paid',
                'transaction_status' => $txStatus,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Membangun state UI pembayaran yang aman untuk Blade order detail.
     * Tidak pernah menyertakan server_key.
     *
     * @return array{
     *     can_pay: bool,
     *     midtrans_ready: bool,
     *     is_expired: bool,
     *     client_key: ?string,
     *     snap_js_url: ?string,
     *     is_production: bool,
     *     initiate_url: ?string,
     *     js_config: array<string, mixed>|null
     * }
     */
    public function buildOrderViewPaymentState(Order $order): array
    {
        $publicUiConfig = $this->midtransClient->resolvePublicUiConfig();
        $midtransReady = $publicUiConfig !== null;
        $isExpired = $order->payment_due_at !== null && $order->payment_due_at->isPast();

        $orderEligible = $order->payment_status !== 'paid'
            && in_array($order->order_status, ['pending', 'awaiting_payment'], true)
            && in_array($order->payment_status, ['unpaid', 'pending', 'failed', 'expired'], true)
            && $order->payment_due_at !== null
            && ! $isExpired
            && (int) round((float) $order->grand_total) >= 1;

        $canPay = $midtransReady && $orderEligible;

        if (! $canPay) {
            return [
                'can_pay' => false,
                'midtrans_ready' => $midtransReady,
                'is_expired' => $isExpired,
                'client_key' => null,
                'snap_js_url' => null,
                'is_production' => false,
                'initiate_url' => null,
                'js_config' => null,
            ];
        }

        $initiateUrl = route('customer.orders.payment.initiate', $order);
        $statusCheckUrl = route('customer.orders.payment.status', $order);
        $refreshUrl = route('customer.orders.show', $order);

        return [
            'can_pay' => true,
            'midtrans_ready' => true,
            'is_expired' => false,
            'client_key' => $publicUiConfig['client_key'],
            'snap_js_url' => $publicUiConfig['snap_js_url'],
            'is_production' => $publicUiConfig['is_production'],
            'initiate_url' => $initiateUrl,
            'js_config' => [
                'initiateUrl' => $initiateUrl,
                'statusCheckUrl' => $statusCheckUrl,
                'refreshUrl' => $refreshUrl,
                'clientKey' => $publicUiConfig['client_key'],
                'snapJsUrl' => $publicUiConfig['snap_js_url'],
            ],
        ];
    }

    protected function assertOrderEligibleForPayment(Order $order): void
    {
        if ($order->payment_status === 'paid' || $order->order_status === 'paid') {
            throw MidtransPaymentException::alreadyPaid();
        }

        if (
            in_array($order->order_status, ['cancelled', 'refunded'], true)
            || in_array($order->payment_status, ['refunded', 'partially_refunded'], true)
        ) {
            throw MidtransPaymentException::orderCancelled();
        }

        if (
            ! in_array($order->order_status, ['pending', 'awaiting_payment'], true)
            || ! in_array($order->payment_status, ['unpaid', 'pending', 'failed', 'expired'], true)
        ) {
            throw MidtransPaymentException::invalidOrderState();
        }

        if ($order->payment_due_at === null || $order->payment_due_at->isPast()) {
            throw MidtransPaymentException::orderExpired();
        }

        if ((int) round((float) $order->grand_total) < 1) {
            throw MidtransPaymentException::amountInvalid();
        }
    }

    /**
     * @param  Collection<int, Payment>  $payments
     */
    protected function findReusablePendingPayment(Collection $payments, Order $order): ?Payment
    {
        $expectedAmount = (int) round((float) $order->grand_total);

        foreach ($payments as $payment) {
            if ($payment->status !== 'pending') {
                continue;
            }

            if (trim((string) $payment->snap_token) === '') {
                continue;
            }

            if ((int) round((float) $payment->gross_amount) !== $expectedAmount) {
                continue;
            }

            if ($payment->expired_at === null || ! $payment->expired_at->isFuture()) {
                continue;
            }

            if ($order->payment_due_at !== null && $payment->expired_at->greaterThan($order->payment_due_at)) {
                continue;
            }

            return $payment;
        }

        return null;
    }

    /**
     * @return array{now: CarbonInterface, expiry_minutes: int, expires_at: CarbonInterface}
     */
    protected function calculateSnapExpiry(Order $order): array
    {
        $now = now();
        $dueAt = $order->payment_due_at;

        if ($dueAt === null || $dueAt->isPast()) {
            throw MidtransPaymentException::orderExpired();
        }

        $remainingSeconds = (int) $now->diffInSeconds($dueAt, false);
        $remainingMinutes = intdiv(max(0, $remainingSeconds), 60);

        if ($remainingMinutes < 1) {
            throw MidtransPaymentException::orderExpired(
                'Batas waktu pembayaran tersisa kurang dari 1 menit atau telah berakhir.'
            );
        }

        $configuredMinutes = max(1, (int) $this->settingService->get('checkout', 'payment_expiry_minutes', 1440));
        $expiryMinutes = min($remainingMinutes, $configuredMinutes);
        $expiresAt = $now->copy()->addMinutes($expiryMinutes);

        return [
            'now' => $now,
            'expiry_minutes' => $expiryMinutes,
            'expires_at' => $expiresAt,
        ];
    }

    protected function generateUniqueMidtransOrderId(Order $order, int $attemptNumber): string
    {
        $cleanOrderNumber = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $order->order_number) ?: ('ORD'.$order->id);
        $prefix = Str::limit($cleanOrderNumber, 52, '');

        for ($i = 0; $i < 5; $i++) {
            $suffix = strtoupper(Str::random(6));
            $candidate = Str::limit("{$prefix}-P{$attemptNumber}-{$suffix}", 80, '');

            if (! Payment::where('midtrans_order_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        return Str::limit("{$prefix}-P{$attemptNumber}-".time(), 80, '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSnapPayload(
        Order $order,
        string $midtransOrderId,
        CarbonInterface $now,
        int $expiryMinutes,
        bool $is3ds
    ): array {
        $grossAmount = (int) round((float) $order->grand_total);

        $payload = [
            'transaction_details' => [
                'order_id' => $midtransOrderId,
                'gross_amount' => $grossAmount,
            ],
            'credit_card' => [
                'secure' => $is3ds,
            ],
            'customer_details' => $this->buildCustomerDetails($order),
            'custom_expiry' => [
                'order_time' => $now->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s O'),
                'expiry_duration' => $expiryMinutes,
                'unit' => 'minute',
            ],
        ];

        $itemDetails = $this->buildBalancedItemDetails($order, $grossAmount);
        if ($itemDetails !== null) {
            $payload['item_details'] = $itemDetails;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCustomerDetails(Order $order): array
    {
        $details = [
            'first_name' => Str::limit((string) $order->customer_name_snapshot, 100, ''),
            'email' => (string) $order->customer_email_snapshot,
            'phone' => Str::limit((string) $order->customer_phone_snapshot, 30, ''),
        ];

        if ($order->address !== null) {
            $cityParts = array_filter([
                $order->address->district_name,
                $order->address->city_name,
            ]);

            $details['shipping_address'] = [
                'first_name' => Str::limit((string) $order->address->recipient_name, 100, ''),
                'phone' => Str::limit((string) $order->address->recipient_phone, 30, ''),
                'address' => Str::limit((string) $order->address->address_line, 200, ''),
                'city' => Str::limit(implode(', ', $cityParts), 60, ''),
                'postal_code' => Str::limit((string) $order->address->postal_code, 15, ''),
                'country_code' => 'IDN',
            ];
        }

        return $details;
    }

    /**
     * Menyusun item_details hanya jika total integer komponen sama persis dengan gross_amount
     * agar Midtrans Snap API tidak menolak karena selisih pembulatan 1 rupiah.
     *
     * @return array<int, array<string, int|string>>|null
     */
    protected function buildBalancedItemDetails(Order $order, int $expectedGrossAmount): ?array
    {
        $items = [];
        $sum = 0;

        foreach ($order->items as $item) {
            $qty = max(1, (int) $item->quantity);
            $unitPrice = (int) round((float) $item->unit_price_snapshot);
            $lineTotal = (int) round((float) $item->line_total);

            if ($unitPrice * $qty !== $lineTotal || $unitPrice < 0) {
                return null;
            }

            $label = $item->product_name_snapshot;
            if (! empty($item->variant_name_snapshot)) {
                $label .= ' ('.$item->variant_name_snapshot.')';
            }

            $items[] = [
                'id' => Str::limit((string) $item->sku_snapshot, 50, ''),
                'price' => $unitPrice,
                'quantity' => $qty,
                'name' => Str::limit($label, 50, ''),
            ];

            $sum += $lineTotal;
        }

        $shippingCost = (int) round((float) $order->shipping_cost);
        if ($shippingCost > 0) {
            $items[] = [
                'id' => 'SHIPPING',
                'price' => $shippingCost,
                'quantity' => 1,
                'name' => 'Ongkos Kirim',
            ];
            $sum += $shippingCost;
        }

        $taxAmount = (int) round((float) $order->tax_amount);
        if ($taxAmount > 0) {
            $items[] = [
                'id' => 'TAX',
                'price' => $taxAmount,
                'quantity' => 1,
                'name' => 'Pajak',
            ];
            $sum += $taxAmount;
        }

        $paymentFee = (int) round((float) $order->payment_fee);
        if ($paymentFee > 0) {
            $items[] = [
                'id' => 'PAYMENT_FEE',
                'price' => $paymentFee,
                'quantity' => 1,
                'name' => 'Biaya Layanan',
            ];
            $sum += $paymentFee;
        }

        $discountAmount = (int) round((float) $order->discount_amount);
        if ($discountAmount > 0) {
            $items[] = [
                'id' => 'DISCOUNT',
                'price' => -$discountAmount,
                'quantity' => 1,
                'name' => 'Diskon Pesanan',
            ];
            $sum -= $discountAmount;
        }

        if ($items === [] || $sum !== $expectedGrossAmount) {
            return null;
        }

        return $items;
    }

    protected function transitionOrderToAwaitingPaymentIfNeeded(
        Order $order,
        Customer $customer,
        string $midtransOrderId
    ): void {
        if ($order->order_status !== 'pending') {
            return;
        }

        $fromStatus = $order->order_status;
        $order->order_status = 'awaiting_payment';
        $order->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status_type' => 'order',
            'from_status' => $fromStatus,
            'to_status' => 'awaiting_payment',
            'notes' => "Token pembayaran Midtrans Snap dibuat ({$midtransOrderId}).",
            'actor_type' => 'customer',
            'actor_id' => $customer->id,
            'created_at' => now(),
        ]);
    }
}
