<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePageRequest;
use App\Models\Page;
use App\Models\User;
use App\Services\Admin\ContentManagementService;
use App\Services\Content\HtmlSanitizer;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class PageController extends Controller
{
    public function __construct(
        protected ContentManagementService $contentService,
        protected HtmlSanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Page::class);

        $this->contentService->ensureBuiltInPolicyPages();

        $search = trim((string) $request->query('search', ''));
        $type = (string) $request->query('type', 'all');
        $status = (string) $request->query('status', 'all');

        if (! in_array($type, ['all', 'policy', 'custom'], true)) {
            $type = 'all';
        }
        if (! in_array($status, ['all', 'enabled', 'disabled'], true)) {
            $status = 'all';
        }

        $hasSortOrder = Schema::hasColumn('pages', 'sort_order');

        $pages = Page::query()
            ->with('updater:id,name')
            ->whereNotIn('slug', StorefrontContentService::SYSTEM_SPECIAL_SLUGS)
            ->when($search !== '', function ($query) use ($search) {
                $escaped = addcslashes($search, '%_\\');
                $query->where(function ($sub) use ($escaped) {
                    $sub->where('title', 'like', '%'.$escaped.'%')
                        ->orWhere('slug', 'like', '%'.$escaped.'%');
                });
            })
            ->when($type === 'policy', fn ($query) => $query->whereIn('slug', StorefrontContentService::POLICY_SLUGS))
            ->when($type === 'custom', fn ($query) => $query->whereNotIn('slug', StorefrontContentService::POLICY_SLUGS))
            ->when($status === 'enabled', fn ($query) => $query->where('is_published', true))
            ->when($status === 'disabled', fn ($query) => $query->where('is_published', false))
            ->when($hasSortOrder, fn ($q) => $q->orderBy('sort_order'))
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString();

        return view('admin.pages.index', compact('pages', 'search', 'type', 'status', 'admin'));
    }

    public function create(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Page::class);

        $nextOrder = max(10, ((int) Page::query()->max('sort_order')) + 1);

        return view('admin.pages.create', compact('admin', 'nextOrder'));
    }

    public function store(SavePageRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Page::class);

        try {
            $page = $this->contentService->createPage(
                $admin,
                $request->validated(),
                $request->file('banner_image'),
                $request
            );

            return redirect()
                ->route('admin.pages.edit', $page)
                ->with('success', "Halaman \"{$page->title}\" berhasil dibuat.");
        } catch (Throwable $e) {
            Log::error('Gagal membuat halaman custom admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan halaman baru.']);
        }
    }

    public function edit(Page $page): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_if($page->isSystemSpecialPage(), 404);
        Gate::forUser($admin)->authorize('update', $page);

        $page->loadMissing('updater:id,name');

        return view('admin.pages.edit', compact('page', 'admin'));
    }

    public function update(SavePageRequest $request, Page $page): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_if($page->isSystemSpecialPage(), 404);
        Gate::forUser($admin)->authorize('update', $page);

        try {
            $updated = $this->contentService->updatePage(
                $admin,
                $page,
                $request->validated(),
                $request->file('banner_image'),
                (bool) $request->boolean('remove_banner_image'),
                $request
            );

            return redirect()
                ->route('admin.pages.edit', $updated)
                ->with('success', "Perubahan halaman \"{$updated->title}\" berhasil disimpan.");
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui halaman CMS.', [
                'admin_id' => $admin->id,
                'page_id' => $page->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat memperbarui halaman.']);
        }
    }

    public function toggle(Request $request, Page $page): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_if($page->isSystemSpecialPage(), 404);
        Gate::forUser($admin)->authorize('update', $page);

        try {
            $updated = $this->contentService->togglePageStatus($admin, $page, $request);

            $statusLabel = $updated->is_published
                ? "Halaman \"{$updated->title}\" berhasil diaktifkan (Enabled)."
                : "Halaman \"{$updated->title}\" berhasil dinonaktifkan (Disabled) dan disembunyikan dari publik.";

            return back()->with('success', $statusLabel);
        } catch (Throwable $e) {
            Log::error('Gagal mengubah status Enable/Disable halaman CMS.', [
                'admin_id' => $admin->id,
                'page_id' => $page->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengubah status halaman.']);
        }
    }

    public function preview(Page $page): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_if($page->isSystemSpecialPage(), 404);
        Gate::forUser($admin)->authorize('view', $page);

        $sanitizedContent = $this->sanitizer->sanitize($page->content);
        $isPreview = true;
        $previewBackUrl = route('admin.pages.edit', $page);

        return view('storefront.pages.policy', compact('page', 'sanitizedContent', 'isPreview', 'previewBackUrl'));
    }

    public function previewDraft(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Page::class);

        $existing = null;
        if ($request->filled('page_id') && is_numeric($request->input('page_id'))) {
            $existing = Page::query()->find((int) $request->input('page_id'));
            if ($existing && $existing->isSystemSpecialPage()) {
                abort(404);
            }
        }

        $title = trim((string) $request->input('title', '')) ?: ($existing?->title ?? 'Pratinjau Halaman');
        $rawSlug = trim((string) $request->input('slug', ''));

        if ($existing && $existing->isBuiltInPolicy()) {
            $slug = $existing->slug;
        } else {
            $candidate = $rawSlug !== '' ? Str::slug($rawSlug) : ($existing?->slug ?? Str::slug($title));
            if (
                $candidate === ''
                || in_array($candidate, StorefrontContentService::RESERVED_SLUGS, true)
                || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $candidate)
            ) {
                $candidate = 'pratinjau-halaman';
            }
            $slug = $candidate;
        }
        $removeBanner = $request->boolean('remove_banner_image');

        $page = new Page([
            'title' => $title,
            'slug' => $slug,
            'content' => (string) $request->input('content', ''),
            'banner_image_path' => $removeBanner ? null : $existing?->banner_image_path,
            'template' => $existing?->template ?? 'custom',
            'is_published' => $request->boolean('is_published'),
            'show_in_footer' => $existing && $existing->isBuiltInPolicy() ? true : $request->boolean('show_in_footer'),
            'seo_title' => trim((string) $request->input('seo_title', '')) ?: null,
            'seo_description' => trim((string) $request->input('seo_description', '')) ?: null,
        ]);
        $page->updated_at = now();

        $sanitizedContent = $this->sanitizer->sanitize($page->content);
        $isPreview = true;
        $previewBackUrl = $existing
            ? route('admin.pages.edit', $existing)
            : route('admin.pages.index');

        return view('storefront.pages.policy', compact('page', 'sanitizedContent', 'isPreview', 'previewBackUrl'));
    }

    public function destroy(Request $request, Page $page): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        abort_if($page->isSystemSpecialPage(), 404);
        Gate::forUser($admin)->authorize('delete', $page);

        try {
            $title = $page->title;
            $this->contentService->deleteCustomPage($admin, $page, $request);

            return redirect()
                ->route('admin.pages.index')
                ->with('success', "Halaman custom \"{$title}\" berhasil dihapus.");
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Gagal menghapus halaman custom.', [
                'admin_id' => $admin->id,
                'page_id' => $page->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus halaman.']);
        }
    }
}
