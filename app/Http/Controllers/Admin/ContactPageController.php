<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContactPageRequest;
use App\Models\Page;
use App\Models\User;
use App\Services\Admin\ContentManagementService;
use App\Services\Setting\StoreSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ContactPageController extends Controller
{
    public function __construct(
        protected ContentManagementService $contentService,
        protected StoreSettingService $settingService,
    ) {
    }

    public function edit(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('viewAny', Page::class);

        $page = Page::query()->where('slug', 'kontak')->first();
        $contactSettings = $this->settingService->contactSettings();

        $form = [
            'title' => $page?->title ?? 'Kontak',
            'content' => $page?->content ?? '',
            'is_published' => $page?->is_published ?? true,
            'seo_title' => $page?->seo_title ?? '',
            'seo_description' => $page?->seo_description ?? '',
            'store_address' => $contactSettings['store_address'],
            'store_whatsapp' => $contactSettings['store_whatsapp'],
            'store_phone' => $contactSettings['store_phone'],
            'store_email' => $contactSettings['store_email'],
            'store_hours' => $contactSettings['store_hours'],
            'social_instagram' => $contactSettings['social_instagram'],
            'social_facebook' => $contactSettings['social_facebook'],
            'social_tiktok' => $contactSettings['social_tiktok'],
            'social_youtube' => $contactSettings['social_youtube'],
            'contact_additional_info' => $contactSettings['contact_additional_info'],
        ];

        return view('admin.contact.edit', compact('admin', 'page', 'form'));
    }

    public function update(UpdateContactPageRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        $existingPage = Page::query()->where('slug', 'kontak')->first();
        Gate::forUser($admin)->authorize('update', $existingPage ?? new Page(['slug' => 'kontak']));

        try {
            $this->contentService->updateContactPage($admin, $request->validated(), $request);

            return redirect()
                ->route('admin.contact.edit')
                ->with('success', 'Informasi kontak dan halaman Kontak berhasil diperbarui.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui halaman Kontak.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan informasi kontak.']);
        }
    }
}
