<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCategoryRequest;
use App\Models\Category;
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

class CategoryController extends Controller
{
    public function __construct(
        protected CatalogManagementService $catalogService,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Category::class);

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $query = Category::query()
            ->with(['parent'])
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($escaped): void {
                $q->where('name', 'like', "%{$escaped}%")
                    ->orWhere('slug', 'like', "%{$escaped}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $categories = $query->paginate(15)->withQueryString();

        return view('admin.categories.index', compact('categories', 'search', 'status', 'admin'));
    }

    public function create(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Category::class);

        $parentCategories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return view('admin.categories.create', compact('parentCategories'));
    }

    public function store(SaveCategoryRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Category::class);

        try {
            $category = $this->catalogService->createCategory(
                $admin,
                $request->validated(),
                $request->file('image'),
                $request
            );

            return redirect()->route('admin.categories.index')
                ->with('success', "Kategori {$category->name} berhasil ditambahkan.");
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal membuat kategori oleh admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan kategori.']);
        }
    }

    public function edit(Category $category): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $category);

        $parentCategories = Category::query()
            ->whereKeyNot($category->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return view('admin.categories.edit', compact('category', 'parentCategories', 'admin'));
    }

    public function update(SaveCategoryRequest $request, Category $category): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $category);

        try {
            $updated = $this->catalogService->updateCategory(
                $admin,
                $category,
                $request->validated(),
                $request->file('image'),
                $request->boolean('remove_image'),
                $request
            );

            return redirect()->route('admin.categories.index')
                ->with('success', "Kategori {$updated->name} berhasil diperbarui.");
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['parent_id' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui kategori oleh admin.', [
                'category_id' => $category->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat memperbarui kategori.']);
        }
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('delete', $category);

        try {
            $name = $category->name;
            $this->catalogService->deleteCategory($admin, $category, $request);

            return redirect()->route('admin.categories.index')
                ->with('success', "Kategori {$name} berhasil dihapus/diarsipkan.");
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menghapus kategori oleh admin.', [
                'category_id' => $category->id,
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus kategori.']);
        }
    }
}
