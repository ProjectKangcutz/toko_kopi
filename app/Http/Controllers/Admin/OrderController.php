<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderLifecycleService;
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
        protected OrderLifecycleService $lifecycleService,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('orders.view'), 403);

        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $query = Order::with(['customer', 'shipment', 'latestPayment'])->latest();

        if ($status && in_array($status, ['pending', 'awaiting_payment', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'], true)) {
            $query->where('order_status', $status);
        }

        if ($search !== '') {
            $escapedSearch = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($escapedSearch): void {
                $q->where('order_number', 'like', "%{$escapedSearch}%")
                    ->orWhere('customer_name_snapshot', 'like', "%{$escapedSearch}%")
                    ->orWhere('customer_email_snapshot', 'like', "%{$escapedSearch}%")
                    ->orWhere('customer_phone_snapshot', 'like', "%{$escapedSearch}%");
            });
        }

        $orders = $query->paginate(15)->withQueryString();

        return view('admin.orders.index', compact('orders', 'status', 'search'));
    }

    public function show(Order $order): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $order);

        $order->load([
            'customer',
            'items',
            'address',
            'shipment.histories',
            'shipment.trackingEvents',
            'payments' => fn ($q) => $q->latest('id'),
            'payments.transactions' => fn ($q) => $q->orderByDesc('occurred_at')->orderByDesc('id'),
            'payments.webhookLogs' => fn ($q) => $q->orderByDesc('received_at')->orderByDesc('id'),
            'statusHistories',
        ]);

        return view('admin.orders.show', compact('order', 'admin'));
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('cancel', $order);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->lifecycleService->cancelOrder($order, $admin, $validated['reason'] ?? null);

            return redirect()->route('admin.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} berhasil dibatalkan.");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal membatalkan pesanan oleh admin karena error internal.', [
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat membatalkan pesanan. Silakan coba lagi.']);
        }
    }

    public function markProcessing(Request $request, Order $order): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('updateStatus', $order);

        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->lifecycleService->markProcessing($order, $admin, $validated['internal_notes'] ?? null);

            return redirect()->route('admin.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} kini berstatus Diproses (Processing).");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal memproses pesanan ke processing karena error internal.', [
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat memperbarui status pesanan. Silakan coba lagi.']);
        }
    }

    public function markShipped(Request $request, Order $order): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('updateShipment', $order);

        $validated = $request->validate([
            'tracking_number' => ['required', 'string', 'max:100'],
            'courier_name' => ['nullable', 'string', 'max:100'],
            'service_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->lifecycleService->markShipped(
                $order,
                $admin,
                $validated['tracking_number'],
                $validated['courier_name'] ?? null,
                $validated['service_name'] ?? null,
                $validated['notes'] ?? null
            );

            return redirect()->route('admin.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} berhasil dikirim dengan resi {$validated['tracking_number']}.");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal mengirim pesanan karena error internal.', [
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat memperbarui status pengiriman. Silakan coba lagi.']);
        }
    }

    public function addTrackingEvent(Request $request, Order $order): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('updateShipment', $order);

        $validated = $request->validate([
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->lifecycleService->addManualTrackingEvent(
                $order,
                $admin,
                $validated['description'],
                $validated['location'] ?? null
            );

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Catatan tracking manual berhasil ditambahkan.');
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menambah tracking manual karena error internal.', [
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat menyimpan catatan tracking. Silakan coba lagi.']);
        }
    }

    public function markDelivered(Request $request, Order $order): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('updateShipment', $order);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->lifecycleService->markDelivered($order, $admin, $validated['notes'] ?? null);

            return redirect()->route('admin.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} dikonfirmasi telah sampai (delivered).");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menandai pesanan delivered karena error internal.', [
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat memperbarui status pengiriman. Silakan coba lagi.']);
        }
    }

    public function markCompleted(Request $request, Order $order): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('updateStatus', $order);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->lifecycleService->markCompleted($order, $admin, $validated['notes'] ?? null);

            return redirect()->route('admin.orders.show', $order)
                ->with('success', "Pesanan {$order->order_number} telah diselesaikan.");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menyelesaikan pesanan oleh admin karena error internal.', [
                'order_id' => $order->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan sistem saat menyelesaikan pesanan. Silakan coba lagi.']);
        }
    }
}
