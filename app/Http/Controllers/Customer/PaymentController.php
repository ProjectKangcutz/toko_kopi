<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Payment\MidtransPaymentException;
use App\Services\Payment\PaymentInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentInitiationService $paymentInitiationService,
    ) {}

    public function initiate(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        Gate::forUser($customer)->authorize('view', $order);

        try {
            $result = $this->paymentInitiationService->initiateForOrder($customer, $order);
        } catch (MidtransPaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'data' => null,
            ], $e->statusCode());
        }

        return response()->json([
            'success' => true,
            'message' => $result['reused_token']
                ? 'Sesi pembayaran aktif ditemukan dan siap dilanjutkan.'
                : 'Token pembayaran Midtrans berhasil dibuat.',
            'data' => [
                'payment_id' => $result['payment']->id,
                'midtrans_order_id' => $result['payment']->midtrans_order_id,
                'snap_token' => $result['snap_token'],
                'snap_redirect_url' => $result['snap_redirect_url'],
                'gross_amount' => (float) $result['payment']->gross_amount,
                'expired_at' => $result['payment']->expired_at?->toIso8601String(),
                'order_status' => $result['order']->order_status,
                'payment_status' => $result['order']->payment_status,
                'reused_token' => $result['reused_token'],
                'client_key' => $result['client_key'],
                'snap_js_url' => $result['snap_js_url'],
            ],
        ], $result['reused_token'] ? 200 : 201);
    }

    public function checkStatus(Request $request, Order $order): JsonResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        Gate::forUser($customer)->authorize('view', $order);

        try {
            $result = $this->paymentInitiationService->syncOrderPaymentStatusFromMidtrans($customer, $order);
        } catch (MidtransPaymentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
                'data' => null,
            ], $e->statusCode());
        }

        if (! $result['found_on_midtrans']) {
            $message = 'Transaksi belum tercatat selesai di Midtrans. Jika Anda belum memilih metode pembayaran, silakan klik Bayar Sekarang.';
        } elseif ($result['is_paid']) {
            $message = 'Pembayaran telah dikonfirmasi lunas oleh server Midtrans.';
        } else {
            $message = 'Status transaksi Midtrans saat ini: '.($result['transaction_status'] ?? $result['order']->payment_status).'.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'order_status' => $result['order']->order_status,
                'payment_status' => $result['order']->payment_status,
                'is_paid' => $result['is_paid'],
                'found_on_midtrans' => $result['found_on_midtrans'],
                'synced' => $result['synced'],
                'transaction_status' => $result['transaction_status'],
            ],
        ], 200);
    }
}
