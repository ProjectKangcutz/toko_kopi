<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payment\PaymentInitiationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected PaymentInitiationService $paymentInitiationService,
    ) {}

    public function index(): View
    {
        $customer = Auth::guard('customer')->user();

        $orderStats = [
            'total' => $customer->orders()->count(),
            'awaiting_payment' => $customer->orders()->whereIn('payment_status', ['unpaid', 'pending'])->count(),
            'processing' => $customer->orders()->where('order_status', 'processing')->count(),
            'shipped' => $customer->orders()->where('order_status', 'shipped')->count(),
            'completed' => $customer->orders()->where('order_status', 'completed')->count(),
        ];

        $unreadMessages = $customer->conversations()->sum('unread_by_customer');

        $recentOrders = $customer->orders()
            ->with(['latestPayment', 'shipment'])
            ->latest()
            ->limit(5)
            ->get();

        $orderPaymentStates = $recentOrders->mapWithKeys(
            fn (Order $order): array => [
                $order->id => $this->paymentInitiationService->buildOrderViewPaymentState($order),
            ]
        );

        $snapPaymentUi = $orderPaymentStates->first(
            static fn (array $state): bool => ! empty($state['can_pay'])
                && ! empty($state['snap_js_url'])
                && ! empty($state['client_key'])
        );

        return view('customer.dashboard', compact(
            'customer',
            'orderStats',
            'unreadMessages',
            'recentOrders',
            'orderPaymentStates',
            'snapPaymentUi'
        ));
    }
}
