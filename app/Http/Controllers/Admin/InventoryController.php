<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustStockRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Inventory\InventoryAdjustmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryAdjustmentService $adjustmentService,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('inventory.view'), 403);

        $search = trim((string) $request->query('search', ''));
        $filter = (string) $request->query('filter', 'all');

        $simpleQuery = Product::query()
            ->where('has_variants', false)
            ->orderBy('name');

        $variantQuery = ProductVariant::query()
            ->with(['product'])
            ->whereHas('product', fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('name');

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $simpleQuery->where(function ($q) use ($escaped): void {
                $q->where('name', 'like', "%{$escaped}%")
                    ->orWhere('sku', 'like', "%{$escaped}%");
            });
            $variantQuery->where(function ($q) use ($escaped): void {
                $q->where('name', 'like', "%{$escaped}%")
                    ->orWhere('sku', 'like', "%{$escaped}%")
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$escaped}%"));
            });
        }

        $simpleItems = $simpleQuery->get()->map(function (Product $product): array {
            $available = $product->availableStock();

            return [
                'product_id' => $product->id,
                'variant_id' => null,
                'target_key' => $product->id.':0',
                'sku' => $product->sku,
                'name' => $product->name,
                'variant_name' => null,
                'is_active' => (bool) $product->is_active,
                'stock' => (int) $product->stock,
                'reserved_stock' => (int) $product->reserved_stock,
                'available_stock' => $available,
                'min_stock' => (int) $product->min_stock,
            ];
        });

        $variantItems = $variantQuery->get()->map(function (ProductVariant $variant): array {
            $available = $variant->availableStock();

            return [
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'target_key' => $variant->product_id.':'.$variant->id,
                'sku' => $variant->sku,
                'name' => $variant->product?->name ?? '-',
                'variant_name' => $variant->name,
                'is_active' => (bool) ($variant->is_active && ($variant->product?->is_active ?? false)),
                'stock' => (int) $variant->stock,
                'reserved_stock' => (int) $variant->reserved_stock,
                'available_stock' => $available,
                'min_stock' => (int) $variant->min_stock,
            ];
        });

        $allStockItems = $simpleItems
            ->concat($variantItems)
            ->sortBy([
                ['available_stock', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        $summaryStats = [
            'total_sku' => $allStockItems->count(),
            'low_stock_sku' => $allStockItems->filter(fn (array $item) => $item['stock'] <= $item['min_stock'])->count(),
            'out_of_stock_sku' => $allStockItems->filter(fn (array $item) => $item['available_stock'] <= 0)->count(),
            'total_reserved_units' => (int) $allStockItems->sum('reserved_stock'),
        ];

        $filteredItems = $allStockItems->filter(function (array $item) use ($filter): bool {
            return match ($filter) {
                'low' => $item['stock'] <= $item['min_stock'],
                'out' => $item['available_stock'] <= 0,
                default => true,
            };
        })->values();

        $perPage = 15;
        $currentPage = max(1, (int) $request->query('page', 1));
        $stockItems = new LengthAwarePaginator(
            $filteredItems->forPage($currentPage, $perPage)->values(),
            $filteredItems->count(),
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $adjustableTargets = Product::query()
            ->where('has_variants', false)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p): array => [
                'key' => $p->id.':0',
                'product_id' => $p->id,
                'variant_id' => null,
                'sku' => $p->sku,
                'name' => $p->name,
                'stock' => (int) $p->stock,
                'reserved_stock' => (int) $p->reserved_stock,
                'available_stock' => $p->availableStock(),
                'label' => "{$p->sku} — {$p->name} (Fisik: {$p->stock}, Reservasi: {$p->reserved_stock})",
            ])
            ->concat(
                ProductVariant::query()
                    ->with('product')
                    ->whereHas('product', fn ($q) => $q->whereNull('deleted_at'))
                    ->orderBy('name')
                    ->get()
                    ->map(fn (ProductVariant $v): array => [
                        'key' => $v->product_id.':'.$v->id,
                        'product_id' => $v->product_id,
                        'variant_id' => $v->id,
                        'sku' => $v->sku,
                        'name' => ($v->product?->name ?? '-')." ({$v->name})",
                        'stock' => (int) $v->stock,
                        'reserved_stock' => (int) $v->reserved_stock,
                        'available_stock' => $v->availableStock(),
                        'label' => "{$v->sku} — ".($v->product?->name ?? '-')." [{$v->name}] (Fisik: {$v->stock}, Reservasi: {$v->reserved_stock})",
                    ])
            )
            ->values();

        $activeReservations = StockReservation::query()
            ->with([
                'order:id,order_number,order_status,payment_status',
                'product' => fn ($q) => $q->withTrashed(),
                'variant' => fn ($q) => $q->withTrashed(),
            ])
            ->where('status', 'active')
            ->orderBy('expires_at')
            ->paginate(10, ['*'], 'reservations_page')
            ->withQueryString();

        $movements = StockMovement::query()
            ->with([
                'product' => fn ($q) => $q->withTrashed(),
                'variant' => fn ($q) => $q->withTrashed(),
                'creator:id,name',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(15, ['*'], 'movements_page')
            ->withQueryString();

        $adjustments = StockAdjustment::query()
            ->with([
                'product' => fn ($q) => $q->withTrashed(),
                'variant' => fn ($q) => $q->withTrashed(),
                'adjuster:id,name',
            ])
            ->latest('adjusted_at')
            ->latest('id')
            ->limit(10)
            ->get();

        $reasons = InventoryAdjustmentService::REASONS;

        return view('admin.inventory.index', compact(
            'admin',
            'search',
            'filter',
            'summaryStats',
            'stockItems',
            'adjustableTargets',
            'activeReservations',
            'movements',
            'adjustments',
            'reasons'
        ));
    }

    public function adjust(AdjustStockRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_unless($admin->hasPermission('inventory.adjust'), 403);

        try {
            $adjustment = $this->adjustmentService->adjustStock($admin, $request->validated(), $request);

            return redirect()->route('admin.inventory.index')
                ->with('success', "Penyesuaian stok {$adjustment->adjustment_number} berhasil dicatat.");
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal memproses penyesuaian stok manual oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan sistem saat menyesuaikan stok.']);
        }
    }
}
