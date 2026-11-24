<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Order\OrderLifecycleService;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Setting\StoreSettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        protected StoreSettingService $settingService,
        protected PaymentInitiationService $paymentInitiationService,
    ) {}

    public function index(Request $request): View
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        $allowedStatuses = [
            'pending',
            'awaiting_payment',
            'paid',
            'processing',
            'shipped',
            'delivered',
            'completed',
            'cancelled',
            'refunded',
        ];

        $status = (string) $request->query('status', '');
        if (! in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $search = trim((string) $request->query('search', ''));

        $query = $customer->orders()
            ->with(['latestPayment', 'shipment'])
            ->latest('created_at')
            ->latest('id');

        if ($status !== '') {
            $query->where('order_status', $status);
        }

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where('order_number', 'like', "%{$escaped}%");
        }

        $orders = $query->paginate(10)->withQueryString();

        $orderPaymentStates = $orders->getCollection()->mapWithKeys(
            fn (Order $order): array => [
                $order->id => $this->paymentInitiationService->buildOrderViewPaymentState($order),
            ]
        );

        $snapPaymentUi = $orderPaymentStates->first(
            static fn (array $state): bool => ! empty($state['can_pay'])
                && ! empty($state['snap_js_url'])
                && ! empty($state['client_key'])
        );

        return view('customer.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'search' => $search,
            'orderPaymentStates' => $orderPaymentStates,
            'snapPaymentUi' => $snapPaymentUi,
            'breadcrumbs' => [
                ['label' => 'Akun Saya', 'url' => route('customer.dashboard')],
                ['label' => 'Pesanan Saya'],
            ],
        ]);
    }

    public function show(Order $order): View
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        Gate::forUser($customer)->authorize('view', $order);

        $order->load([
            'items',
            'address',
            'shipment.histories',
            'shipment.trackingEvents',
            'statusHistories',
            'latestPayment',
        ]);

        $storeName = $this->resolveStoreName();
        $paymentUi = $this->paymentInitiationService->buildOrderViewPaymentState($order);
        $canCancel = Gate::forUser($customer)->allows('cancel', $order);
        $canComplete = Gate::forUser($customer)->allows('complete', $order);

        return view('customer.orders.show', [
            'order' => $order,
            'storeName' => $storeName,
            'paymentUi' => $paymentUi,
            'canCancel' => $canCancel,
            'canComplete' => $canComplete,
            'breadcrumbs' => [
                ['label' => 'Akun Saya', 'url' => route('customer.dashboard')],
                ['label' => 'Detail Pesanan '.$order->order_number],
            ],
        ]);
    }

    public function cancel(Request $request, Order $order, OrderLifecycleService $lifecycleService): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        Gate::forUser($customer)->authorize('view', $order);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $lifecycleService->cancelOrder($order, $customer, $validated['reason'] ?? null);

            return redirect()->route('customer.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} berhasil dibatalkan.");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal membatalkan pesanan pelanggan karena error internal.', [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat membatalkan pesanan. Silakan coba lagi.']);
        }
    }

    public function complete(Request $request, Order $order, OrderLifecycleService $lifecycleService): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = Auth::guard('customer')->user();

        Gate::forUser($customer)->authorize('view', $order);

        try {
            $lifecycleService->markCompleted($order, $customer, 'Pesanan dikonfirmasi selesai oleh pelanggan.');

            return redirect()->route('customer.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} telah diselesaikan. Terima kasih telah berbelanja.");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menyelesaikan pesanan pelanggan karena error internal.', [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat menyelesaikan pesanan. Silakan coba lagi.']);
        }
    }

    protected function resolveStoreName(): string
    {
        try {
            return (string) ($this->settingService->publicSettings()['store_name'] ?? config('app.name', 'Pasar Lokal Nusantara'));
        } catch (Throwable) {
            return (string) config('app.name', 'Pasar Lokal Nusantara');
        }
    }
}
