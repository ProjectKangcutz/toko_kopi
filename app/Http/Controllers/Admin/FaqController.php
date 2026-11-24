<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveFaqRequest;
use App\Models\Faq;
use App\Models\User;
use App\Services\Admin\ContentManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class FaqController extends Controller
{
    public function __construct(
        protected ContentManagementService $contentService,
    ) {
    }

    public function index(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Faq::class);

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $category = trim((string) $request->query('category', ''));

        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $categories = Faq::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->filter(fn ($cat) => trim((string) $cat) !== '')
            ->values();

        $faqs = Faq::query()
            ->when($search !== '', function ($query) use ($search) {
                $escaped = addcslashes($search, '%_\\');
                $query->where(function ($sub) use ($escaped) {
                    $sub->where('question', 'like', '%'.$escaped.'%')
                        ->orWhere('answer', 'like', '%'.$escaped.'%')
                        ->orWhere('category', 'like', '%'.$escaped.'%');
                });
            })
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.faqs.index', compact('faqs', 'categories', 'search', 'status', 'category', 'admin'));
    }

    public function create(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Faq::class);

        $existingCategories = $this->categorySuggestions();
        $nextOrder = ((int) Faq::query()->max('sort_order')) + 1;

        return view('admin.faqs.create', compact('admin', 'existingCategories', 'nextOrder'));
    }

    public function store(SaveFaqRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Faq::class);

        try {
            $this->contentService->createFaq($admin, $request->validated(), $request);

            return redirect()
                ->route('admin.faqs.index')
                ->with('success', 'FAQ baru berhasil ditambahkan.');
        } catch (Throwable $e) {
            Log::error('Gagal menambah FAQ.', [
                'admin_id' => $admin->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan FAQ.']);
        }
    }

    public function edit(Faq $faq): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $faq);

        $existingCategories = $this->categorySuggestions();

        return view('admin.faqs.edit', compact('faq', 'admin', 'existingCategories'));
    }

    public function update(SaveFaqRequest $request, Faq $faq): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $faq);

        try {
            $this->contentService->updateFaq($admin, $faq, $request->validated(), $request);

            return redirect()
                ->route('admin.faqs.index')
                ->with('success', 'FAQ berhasil diperbarui.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui FAQ.', [
                'admin_id' => $admin->id,
                'faq_id' => $faq->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat memperbarui FAQ.']);
        }
    }

    public function toggle(Request $request, Faq $faq): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $faq);

        try {
            $updated = $this->contentService->toggleFaq($admin, $faq, $request);

            return back()->with(
                'success',
                $updated->is_active ? 'FAQ diaktifkan dan ditampilkan di storefront.' : 'FAQ dinonaktifkan dari storefront.'
            );
        } catch (Throwable $e) {
            Log::error('Gagal mengubah status FAQ.', [
                'admin_id' => $admin->id,
                'faq_id' => $faq->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengubah status FAQ.']);
        }
    }

    public function destroy(Request $request, Faq $faq): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('delete', $faq);

        try {
            $this->contentService->deleteFaq($admin, $faq, $request);

            return redirect()
                ->route('admin.faqs.index')
                ->with('success', 'FAQ berhasil dihapus.');
        } catch (Throwable $e) {
            Log::error('Gagal menghapus FAQ.', [
                'admin_id' => $admin->id,
                'faq_id' => $faq->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus FAQ.']);
        }
    }

    protected function categorySuggestions(): array
    {
        $defaults = ['Umum', 'Pemesanan', 'Pembayaran', 'Pengiriman', 'Pengembalian'];
        $existing = Faq::query()->select('category')->distinct()->pluck('category')->all();

        return array_values(array_unique(array_filter(array_merge($defaults, $existing))));
    }
}
