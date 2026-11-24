<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveProductRequest;
use App\Http\Requests\Admin\SaveProductVariantRequest;
use App\Http\Requests\Admin\StoreProductImagesRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Admin\CatalogManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    public function __construct(
        protected CatalogManagementService $catalogService,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Product::class);

        $search = trim((string) $request->query('search', ''));
        $categoryId = (int) $request->query('category_id', 0);
        $status = (string) $request->query('status', '');

        $query = Product::query()
            ->with([
                'categories' => fn ($q) => $q->orderByPivot('is_primary', 'desc')->orderBy('name'),
                'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
                'variants' => fn ($q) => $q->orderBy('id'),
            ])
            ->latest('products.id');

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($escaped): void {
                $q->where('products.name', 'like', "%{$escaped}%")
                    ->orWhere('products.sku', 'like', "%{$escaped}%")
                    ->orWhereHas('variants', fn ($vq) => $vq->where('sku', 'like', "%{$escaped}%"));
            });
        }

        if ($categoryId > 0) {
            $query->whereHas('categories', fn ($cq) => $cq->where('categories.id', $categoryId));
        }

        if ($status === 'active') {
            $query->where('products.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('products.is_active', false);
        } elseif ($status === 'low_stock') {
            $query->where(function ($q): void {
                $q->where(function ($simple): void {
                    $simple->where('products.has_variants', false)
                        ->whereColumn('products.stock', '<=', 'products.min_stock');
                })->orWhere(function ($varianted): void {
                    $varianted->where('products.has_variants', true)
                        ->whereHas('variants', fn ($vq) => $vq->whereColumn('product_variants.stock', '<=', 'product_variants.min_stock'));
                });
            });
        }

        $products = $query->paginate(15)->withQueryString();
        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();

        return view('admin.products.index', compact('products', 'categories', 'search', 'categoryId', 'status', 'admin'));
    }

    public function create(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Product::class);

        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return view('admin.products.create', compact('categories'));
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Product::class);

        try {
            $product = $this->catalogService->createProduct(
                $admin,
                $request->validated(),
                $request->file('images', []),
                $request
            );

            return redirect()->route('admin.products.edit', $product)
                ->with('success', "Produk {$product->name} berhasil dibuat. Anda dapat mengelola gambar atau varian tambahan di bawah.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal membuat produk oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan produk.']);
        }
    }

    public function edit(Product $product): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        $product->load([
            'categories',
            'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            'variants' => fn ($q) => $q->orderBy('id'),
        ]);

        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $primaryCategory = $product->categories->first(fn ($cat) => (bool) ($cat->pivot->is_primary ?? false));
        $primaryCategoryId = $primaryCategory?->id ?? $product->categories->first()?->id;
        $selectedCategoryIds = $product->categories->pluck('id')->all();

        return view('admin.products.edit', compact(
            'product',
            'categories',
            'primaryCategoryId',
            'selectedCategoryIds',
            'admin'
        ));
    }

    public function update(SaveProductRequest $request, Product $product): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $updated = $this->catalogService->updateProduct($admin, $product, $request->validated(), $request);

            return redirect()->route('admin.products.edit', $updated)
                ->with('success', "Data produk {$updated->name} berhasil diperbarui.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui produk oleh admin.', [
                'product_id' => $product->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat memperbarui produk.']);
        }
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('delete', $product);

        try {
            $name = $product->name;
            $outcome = $this->catalogService->deleteProduct($admin, $product, $request);

            $message = $outcome === 'soft_deleted'
                ? "Produk {$name} memiliki riwayat transaksi/stok sehingga dinonaktifkan dan diarsipkan (soft delete)."
                : "Produk {$name} berhasil dihapus permanen.";

            return redirect()->route('admin.products.index')->with('success', $message);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menghapus produk oleh admin.', [
                'product_id' => $product->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus produk.']);
        }
    }

    public function storeImages(StoreProductImagesRequest $request, Product $product): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $created = $this->catalogService->addProductImages(
                $admin,
                $product,
                $request->file('images', []),
                $request
            );

            return redirect()->route('admin.products.edit', $product)
                ->with('success', count($created).' gambar produk berhasil diunggah.');
        } catch (Throwable $e) {
            Log::error('Gagal mengunggah gambar produk.', [
                'product_id' => $product->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengunggah gambar produk.']);
        }
    }

    public function setPrimaryImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $this->catalogService->setPrimaryProductImage($admin, $product, $image, $request);

            return redirect()->route('admin.products.edit', $product)
                ->with('success', 'Gambar utama produk berhasil diperbarui.');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal mengganti gambar utama produk.', [
                'product_id' => $product->id,
                'image_id' => $image->id,
                'admin_id' => $admin->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengganti gambar utama.']);
        }
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $this->catalogService->deleteProductImage($admin, $product, $image, $request);

            return redirect()->route('admin.products.edit', $product)
                ->with('success', 'Gambar produk berhasil dihapus.');
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menghapus gambar produk.', [
                'product_id' => $product->id,
                'image_id' => $image->id,
                'admin_id' => $admin->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus gambar produk.']);
        }
    }

    public function storeVariant(SaveProductVariantRequest $request, Product $product): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $variant = $this->catalogService->createVariant($admin, $product, $request->validated(), $request);

            return redirect()->route('admin.products.edit', $product)
                ->with('success', "Varian {$variant->name} berhasil ditambahkan.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menambah varian produk.', [
                'product_id' => $product->id,
                'admin_id' => $admin->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menambah varian produk.']);
        }
    }

    public function updateVariant(SaveProductVariantRequest $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $updated = $this->catalogService->updateVariant($admin, $product, $variant, $request->validated(), $request);

            return redirect()->route('admin.products.edit', $product)
                ->with('success', "Varian {$updated->name} berhasil diperbarui.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui varian produk.', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'admin_id' => $admin->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat memperbarui varian.']);
        }
    }

    public function destroyVariant(Request $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $product);

        try {
            $name = $variant->name;
            $outcome = $this->catalogService->deleteVariant($admin, $product, $variant, $request);

            $message = $outcome === 'soft_deleted'
                ? "Varian {$name} memiliki riwayat transaksi/stok sehingga dinonaktifkan dan diarsipkan."
                : "Varian {$name} berhasil dihapus.";

            return redirect()->route('admin.products.edit', $product)->with('success', $message);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menghapus varian produk.', [
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'admin_id' => $admin->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus varian.']);
        }
    }
}
