<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAboutPageRequest;
use App\Models\Page;
use App\Models\User;
use App\Services\Admin\ContentManagementService;
use App\Services\Content\HtmlSanitizer;
use App\Services\Setting\StoreSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class AboutPageController extends Controller
{
    public function __construct(
        protected ContentManagementService $contentService,
        protected StoreSettingService $settingService,
        protected HtmlSanitizer $sanitizer,
    ) {
    }

    public function edit(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Page::class);

        $page = Page::query()->where('slug', 'tentang-kami')->first();
        $aboutSettings = $this->settingService->aboutSettings();

        $form = [
            'title' => $page?->title ?? 'Tentang Kami',
            'subtitle' => $aboutSettings['subtitle'],
            'content' => $page?->content ?? '',
            'banner_path' => $aboutSettings['banner_path'],
            'vision' => $aboutSettings['vision'],
            'mission' => $aboutSettings['mission'],
            'business_profile' => $aboutSettings['business_profile'],
            'is_published' => $page?->is_published ?? true,
            'seo_title' => $page?->seo_title ?? '',
            'seo_description' => $page?->seo_description ?? '',
        ];

        return view('admin.about.edit', compact('admin', 'page', 'form'));
    }

    public function update(UpdateAboutPageRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        $existingPage = Page::query()->where('slug', 'tentang-kami')->first();
        Gate::forUser($admin)->authorize('update', $existingPage ?? new Page(['slug' => 'tentang-kami']));

        try {
            $this->contentService->updateAboutPage(
                $admin,
                $request->validated(),
                $request->file('banner_image'),
                (bool) $request->boolean('remove_banner_image'),
                $request
            );

            return redirect()
                ->route('admin.about.edit')
                ->with('success', 'Halaman Tentang Kami berhasil diperbarui.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui halaman Tentang Kami.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan halaman Tentang Kami.']);
        }
    }

    public function preview(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Page::class);

        $page = Page::query()->where('slug', 'tentang-kami')->first() ?? new Page([
            'title' => 'Tentang Kami',
            'slug' => 'tentang-kami',
            'content' => '',
            'is_published' => false,
        ]);

        $aboutSettings = $this->settingService->aboutSettings();
        $sanitizedContent = $this->sanitizer->sanitize($page->content);
        $isPreview = true;
        $previewBackUrl = route('admin.about.edit');

        return view('storefront.pages.about', compact(
            'page',
            'aboutSettings',
            'sanitizedContent',
            'isPreview',
            'previewBackUrl'
        ));
    }

    public function previewDraft(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        $existingPage = Page::query()->where('slug', 'tentang-kami')->first();
        Gate::forUser($admin)->authorize('update', $existingPage ?? new Page(['slug' => 'tentang-kami']));

        $savedSettings = $this->settingService->aboutSettings();
        $removeBanner = $request->boolean('remove_banner_image');

        $page = new Page([
            'title' => trim((string) $request->input('title', '')) ?: ($existingPage?->title ?? 'Tentang Kami'),
            'slug' => 'tentang-kami',
            'content' => (string) $request->input('content', ''),
            'is_published' => $request->boolean('is_published'),
            'seo_title' => trim((string) $request->input('seo_title', '')) ?: null,
            'seo_description' => trim((string) $request->input('seo_description', '')) ?: null,
        ]);

        $aboutSettings = [
            'subtitle' => trim((string) $request->input('subtitle', '')),
            'banner_path' => $removeBanner ? null : $savedSettings['banner_path'],
            'vision' => trim((string) $request->input('vision', '')),
            'mission' => trim((string) $request->input('mission', '')),
            'business_profile' => trim((string) $request->input('business_profile', '')),
        ];

        $sanitizedContent = $this->sanitizer->sanitize($page->content);
        $isPreview = true;
        $previewBackUrl = route('admin.about.edit');

        return view('storefront.pages.about', compact(
            'page',
            'aboutSettings',
            'sanitizedContent',
            'isPreview',
            'previewBackUrl'
        ));
    }
}
