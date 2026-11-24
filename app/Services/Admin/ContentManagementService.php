<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\Faq;
use App\Models\Page;
use App\Models\User;
use App\Services\Content\HtmlSanitizer;
use App\Services\Security\AuditLogService;
use App\Services\Setting\StoreSettingService;
use App\Services\Storefront\HomepageService;
use App\Services\Storefront\StorefrontContentService;
use App\Services\Storefront\StorefrontLayoutService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ContentManagementService
{
    public function __construct(
        protected HtmlSanitizer $sanitizer,
        protected StoreSettingService $settingService,
    ) {
    }

    public function createArticle(User $admin, array $data, ?UploadedFile $featuredImage = null, ?Request $request = null): Article
    {
        $storedImagePath = null;

        try {
            if ($featuredImage !== null) {
                $storedImagePath = $featuredImage->store('articles', 'public');
            }

            $article = DB::transaction(function () use ($admin, $data, $storedImagePath, $request): Article {
                $slug = $this->resolveUniqueArticleSlug($data['slug'] ?? null, $data['title']);
                $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
                $publishedAt = $this->resolvePublishedAt($status, $data['published_at'] ?? null);

                $article = Article::create([
                    'title' => trim((string) $data['title']),
                    'slug' => $slug,
                    'excerpt' => $this->nullableTrimmed($data['excerpt'] ?? null),
                    'content' => $this->sanitizer->sanitize($data['content'] ?? ''),
                    'featured_image_path' => $storedImagePath,
                    'featured_image_alt' => $this->nullableTrimmed($data['featured_image_alt'] ?? null),
                    'author_id' => ! empty($data['author_id']) ? (int) $data['author_id'] : $admin->id,
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'seo_title' => $this->nullableTrimmed($data['seo_title'] ?? null),
                    'seo_description' => $this->nullableTrimmed($data['seo_description'] ?? null),
                    'canonical_url' => $this->sanitizer->sanitizeUrl($data['canonical_url'] ?? null),
                ]);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'article.created',
                    "Menambahkan artikel \"{$article->title}\" ({$article->status}).",
                    $article,
                    ['article_id' => $article->id, 'slug' => $article->slug, 'status' => $article->status],
                    $request
                );

                return $article;
            });

            HomepageService::clearCache();

            return $article;
        } catch (Throwable $e) {
            if ($storedImagePath !== null) {
                Storage::disk('public')->delete($storedImagePath);
            }
            throw $e;
        }
    }

    public function updateArticle(
        User $admin,
        Article $article,
        array $data,
        ?UploadedFile $featuredImage = null,
        bool $removeImage = false,
        ?Request $request = null
    ): Article {
        $newImagePath = null;
        $oldImageToDelete = null;

        try {
            if ($featuredImage !== null) {
                $newImagePath = $featuredImage->store('articles', 'public');
            }

            $updated = DB::transaction(function () use (
                $admin,
                $article,
                $data,
                $newImagePath,
                $removeImage,
                $request,
                &$oldImageToDelete
            ): Article {
                /** @var Article $locked */
                $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

                $slug = $this->resolveUniqueArticleSlug($data['slug'] ?? null, $data['title'], $locked->id);
                $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
                $publishedAt = $this->resolvePublishedAt(
                    $status,
                    $data['published_at'] ?? null,
                    $locked->published_at?->format('Y-m-d H:i:s')
                );

                $currentImage = $locked->featured_image_path;
                $targetImage = $currentImage;

                if ($newImagePath !== null) {
                    $targetImage = $newImagePath;
                    if ($currentImage && $this->isManagedPublicStoragePath($currentImage, 'articles/')) {
                        $oldImageToDelete = $currentImage;
                    }
                } elseif ($removeImage) {
                    $targetImage = null;
                    if ($currentImage && $this->isManagedPublicStoragePath($currentImage, 'articles/')) {
                        $oldImageToDelete = $currentImage;
                    }
                }

                $locked->update([
                    'title' => trim((string) $data['title']),
                    'slug' => $slug,
                    'excerpt' => $this->nullableTrimmed($data['excerpt'] ?? null),
                    'content' => $this->sanitizer->sanitize($data['content'] ?? ''),
                    'featured_image_path' => $targetImage,
                    'featured_image_alt' => $this->nullableTrimmed($data['featured_image_alt'] ?? null),
                    'author_id' => ! empty($data['author_id']) ? (int) $data['author_id'] : $locked->author_id,
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'seo_title' => $this->nullableTrimmed($data['seo_title'] ?? null),
                    'seo_description' => $this->nullableTrimmed($data['seo_description'] ?? null),
                    'canonical_url' => $this->sanitizer->sanitizeUrl($data['canonical_url'] ?? null),
                ]);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'article.updated',
                    "Memperbarui artikel \"{$locked->title}\" ({$locked->status}).",
                    $locked,
                    ['article_id' => $locked->id, 'slug' => $locked->slug, 'status' => $locked->status],
                    $request
                );

                return $locked->fresh(['author:id,name']);
            });

            if ($oldImageToDelete !== null) {
                Storage::disk('public')->delete($oldImageToDelete);
            }

            HomepageService::clearCache();

            return $updated;
        } catch (Throwable $e) {
            if ($newImagePath !== null) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $e;
        }
    }

    public function setArticlePublishStatus(User $admin, Article $article, bool $publish, ?Request $request = null): Article
    {
        $updated = DB::transaction(function () use ($admin, $article, $publish, $request): Article {
            /** @var Article $locked */
            $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            $targetStatus = $publish ? 'published' : 'draft';
            $publishedAt = $locked->published_at;
            if ($publish && $publishedAt === null) {
                $publishedAt = now();
            }

            $locked->update([
                'status' => $targetStatus,
                'published_at' => $publishedAt,
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                $publish ? 'article.published' : 'article.unpublished',
                $publish
                    ? "Mempublikasikan artikel \"{$locked->title}\"."
                    : "Menarik artikel \"{$locked->title}\" ke draf.",
                $locked,
                ['article_id' => $locked->id, 'status' => $targetStatus],
                $request
            );

            return $locked->fresh();
        });

        HomepageService::clearCache();

        return $updated;
    }

    public function deleteArticle(User $admin, Article $article, ?Request $request = null): void
    {
        DB::transaction(function () use ($admin, $article, $request): void {
            /** @var Article $locked */
            $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'status' => 'draft',
            ]);
            $locked->delete();

            AuditLogService::record(
                'user',
                $admin->id,
                'article.deleted',
                "Mengarsipkan artikel \"{$locked->title}\".",
                $locked,
                ['article_id' => $locked->id, 'slug' => $locked->slug],
                $request
            );
        });

        HomepageService::clearCache();
    }

    public function updateAboutPage(
        User $admin,
        array $data,
        ?UploadedFile $bannerImage = null,
        bool $removeBanner = false,
        ?Request $request = null
    ): Page {
        $newBannerPath = null;
        $oldBannerToDelete = null;

        try {
            if ($bannerImage !== null) {
                $newBannerPath = $bannerImage->store('pages/about', 'public');
            }

            $page = DB::transaction(function () use (
                $admin,
                $data,
                $newBannerPath,
                $removeBanner,
                $request,
                &$oldBannerToDelete
            ): Page {
                $page = Page::withTrashed()->firstOrNew(['slug' => 'tentang-kami']);
                if ($page->trashed()) {
                    $page->restore();
                }

                $page->fill([
                    'title' => trim((string) $data['title']),
                    'slug' => 'tentang-kami',
                    'content' => $this->sanitizer->sanitize($data['content'] ?? ''),
                    'template' => 'about',
                    'is_published' => (bool) ($data['is_published'] ?? true),
                    'seo_title' => $this->nullableTrimmed($data['seo_title'] ?? null),
                    'seo_description' => $this->nullableTrimmed($data['seo_description'] ?? null),
                    'robots_meta' => ($data['is_published'] ?? true) ? 'index,follow' : 'noindex,nofollow',
                    'updated_by' => $admin->id,
                ]);
                $page->save();

                $currentBanner = (string) ($this->settingService->get('appearance', 'about_banner_path', '') ?? '');
                $targetBanner = $currentBanner;

                if ($newBannerPath !== null) {
                    $targetBanner = $newBannerPath;
                    if ($currentBanner !== '' && $this->isManagedPublicStoragePath($currentBanner, 'pages/about/')) {
                        $oldBannerToDelete = $currentBanner;
                    }
                } elseif ($removeBanner) {
                    $targetBanner = '';
                    if ($currentBanner !== '' && $this->isManagedPublicStoragePath($currentBanner, 'pages/about/')) {
                        $oldBannerToDelete = $currentBanner;
                    }
                }

                $this->settingService->set('general', 'about_subtitle', trim((string) ($data['subtitle'] ?? '')), 'string', true);
                $this->settingService->set('general', 'about_vision', trim((string) ($data['vision'] ?? '')), 'string', true);
                $this->settingService->set('general', 'about_mission', trim((string) ($data['mission'] ?? '')), 'string', true);
                $this->settingService->set('general', 'about_business_profile', trim((string) ($data['business_profile'] ?? '')), 'string', true);
                $this->settingService->set('appearance', 'about_banner_path', $targetBanner, 'string', true);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'page.about_updated',
                    "Memperbarui halaman Tentang Kami ({$page->title}).",
                    $page,
                    ['page_id' => $page->id, 'is_published' => $page->is_published],
                    $request
                );

                return $page;
            });

            if ($oldBannerToDelete !== null) {
                Storage::disk('public')->delete($oldBannerToDelete);
            }

            StorefrontContentService::clearCache();

            return $page;
        } catch (Throwable $e) {
            if ($newBannerPath !== null) {
                Storage::disk('public')->delete($newBannerPath);
            }
            throw $e;
        }
    }

    public function createFaq(User $admin, array $data, ?Request $request = null): Faq
    {
        $faq = DB::transaction(function () use ($admin, $data, $request): Faq {
            $faq = Faq::create([
                'category' => trim((string) ($data['category'] ?? 'Umum')) ?: 'Umum',
                'question' => trim((string) $data['question']),
                'answer' => $this->sanitizer->sanitize($data['answer'] ?? ''),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                'faq.created',
                "Menambahkan FAQ \"{$faq->question}\".",
                $faq,
                ['faq_id' => $faq->id, 'category' => $faq->category, 'is_active' => $faq->is_active],
                $request
            );

            return $faq;
        });

        StorefrontContentService::clearCache();

        return $faq;
    }

    public function updateFaq(User $admin, Faq $faq, array $data, ?Request $request = null): Faq
    {
        $updated = DB::transaction(function () use ($admin, $faq, $data, $request): Faq {
            /** @var Faq $locked */
            $locked = Faq::query()->whereKey($faq->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'category' => trim((string) ($data['category'] ?? 'Umum')) ?: 'Umum',
                'question' => trim((string) $data['question']),
                'answer' => $this->sanitizer->sanitize($data['answer'] ?? ''),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                'faq.updated',
                "Memperbarui FAQ \"{$locked->question}\".",
                $locked,
                ['faq_id' => $locked->id, 'category' => $locked->category, 'is_active' => $locked->is_active],
                $request
            );

            return $locked->fresh();
        });

        StorefrontContentService::clearCache();

        return $updated;
    }

    public function toggleFaq(User $admin, Faq $faq, ?Request $request = null): Faq
    {
        $updated = DB::transaction(function () use ($admin, $faq, $request): Faq {
            /** @var Faq $locked */
            $locked = Faq::query()->whereKey($faq->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'is_active' => ! $locked->is_active,
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                'faq.toggled',
                ($locked->is_active ? 'Mengaktifkan' : 'Menonaktifkan')." FAQ \"{$locked->question}\".",
                $locked,
                ['faq_id' => $locked->id, 'is_active' => $locked->is_active],
                $request
            );

            return $locked->fresh();
        });

        StorefrontContentService::clearCache();

        return $updated;
    }

    public function deleteFaq(User $admin, Faq $faq, ?Request $request = null): void
    {
        DB::transaction(function () use ($admin, $faq, $request): void {
            /** @var Faq $locked */
            $locked = Faq::query()->whereKey($faq->id)->lockForUpdate()->firstOrFail();
            $question = $locked->question;
            $faqId = $locked->id;

            $locked->delete();

            AuditLogService::record(
                'user',
                $admin->id,
                'faq.deleted',
                "Menghapus FAQ \"{$question}\".",
                null,
                ['faq_id' => $faqId, 'question' => $question],
                $request
            );
        });

        StorefrontContentService::clearCache();
    }

    public function updateContactPage(User $admin, array $data, ?Request $request = null): Page
    {
        $page = DB::transaction(function () use ($admin, $data, $request): Page {
            $page = Page::withTrashed()->firstOrNew(['slug' => 'kontak']);
            if ($page->trashed()) {
                $page->restore();
            }

            $page->fill([
                'title' => trim((string) $data['title']),
                'slug' => 'kontak',
                'content' => $this->sanitizer->sanitize($data['content'] ?? ''),
                'template' => 'contact',
                'is_published' => (bool) ($data['is_published'] ?? true),
                'seo_title' => $this->nullableTrimmed($data['seo_title'] ?? null),
                'seo_description' => $this->nullableTrimmed($data['seo_description'] ?? null),
                'robots_meta' => ($data['is_published'] ?? true) ? 'index,follow' : 'noindex,nofollow',
                'updated_by' => $admin->id,
            ]);
            $page->save();

            $this->settingService->set('general', 'store_address', trim((string) ($data['store_address'] ?? '')), 'string', true);
            $this->settingService->set('general', 'store_whatsapp', trim((string) ($data['store_whatsapp'] ?? '')), 'string', true);
            $this->settingService->set('general', 'store_phone', trim((string) ($data['store_phone'] ?? '')), 'string', true);
            $this->settingService->set('general', 'store_email', trim((string) ($data['store_email'] ?? '')), 'string', true);
            $this->settingService->set('general', 'store_hours', trim((string) ($data['store_hours'] ?? '')), 'string', true);
            $this->settingService->set('general', 'social_instagram', $this->sanitizer->sanitizeUrl($data['social_instagram'] ?? null) ?? '', 'string', true);
            $this->settingService->set('general', 'social_facebook', $this->sanitizer->sanitizeUrl($data['social_facebook'] ?? null) ?? '', 'string', true);
            $this->settingService->set('general', 'social_tiktok', $this->sanitizer->sanitizeUrl($data['social_tiktok'] ?? null) ?? '', 'string', true);
            $this->settingService->set('general', 'social_youtube', $this->sanitizer->sanitizeUrl($data['social_youtube'] ?? null) ?? '', 'string', true);
            $this->settingService->set('general', 'contact_additional_info', trim((string) ($data['contact_additional_info'] ?? '')), 'string', true);

            AuditLogService::record(
                'user',
                $admin->id,
                'page.contact_updated',
                'Memperbarui informasi kontak toko dan halaman Kontak.',
                $page,
                ['page_id' => $page->id, 'is_published' => $page->is_published],
                $request
            );

            return $page;
        });

        StorefrontContentService::clearCache();
        HomepageService::clearCache();

        return $page;
    }

    public function ensureBuiltInPolicyPages(): void
    {
        $defaults = [
            'kebijakan-privasi' => [
                'title' => 'Kebijakan Privasi',
                'content' => '<p>Data pribadi dan transaksi pelanggan dijaga kerahasiaannya dan hanya digunakan untuk pemrosesan pesanan serta layanan pelanggan.</p>',
                'sort_order' => 1,
            ],
            'syarat-ketentuan' => [
                'title' => 'Syarat & Ketentuan',
                'content' => '<p>Dengan berbelanja di toko kami, Anda menyetujui ketentuan transaksi, pembayaran, dan pengiriman yang berlaku.</p>',
                'sort_order' => 2,
            ],
            'kebijakan-pengiriman' => [
                'title' => 'Kebijakan Pengiriman',
                'content' => '<p>Pesanan yang terkonfirmasi lunas sebelum pukul 15.00 WIB akan diproses pada hari kerja yang sama menggunakan kurir pilihan Anda.</p>',
                'sort_order' => 3,
            ],
            'kebijakan-pengembalian' => [
                'title' => 'Kebijakan Pengembalian',
                'content' => '<p>Pengajuan pengembalian barang dapat dilakukan maksimal 3x24 jam setelah paket diterima dengan menyertakan video unboxing.</p>',
                'sort_order' => 4,
            ],
        ];

        $changed = false;
        $hasShowInFooter = Schema::hasColumn('pages', 'show_in_footer');
        $hasSortOrder = Schema::hasColumn('pages', 'sort_order');

        foreach ($defaults as $slug => $meta) {
            $page = Page::withTrashed()->where('slug', $slug)->first();

            if ($page === null) {
                $attributes = [
                    'title' => $meta['title'],
                    'slug' => $slug,
                    'content' => $meta['content'],
                    'template' => 'default',
                    'is_published' => true,
                    'seo_title' => $meta['title'],
                    'seo_description' => strip_tags($meta['content']),
                    'robots_meta' => 'index,follow',
                ];
                if ($hasShowInFooter) {
                    $attributes['show_in_footer'] = true;
                }
                if ($hasSortOrder) {
                    $attributes['sort_order'] = $meta['sort_order'];
                }

                Page::create($attributes);
                $changed = true;
            } elseif ($page->trashed()) {
                $page->restore();
                $updateData = [];
                if ($hasShowInFooter) {
                    $updateData['show_in_footer'] = true;
                }
                if ($hasSortOrder) {
                    $updateData['sort_order'] = (isset($page->sort_order) && $page->sort_order) ? $page->sort_order : $meta['sort_order'];
                }
                if ($updateData !== []) {
                    $page->update($updateData);
                }
                $changed = true;
            }
        }

        if ($changed) {
            StorefrontContentService::clearCache();
            app(StorefrontLayoutService::class)->clearNavigationCache();
        }
    }

    public function createPage(
        User $admin,
        array $data,
        ?UploadedFile $bannerImage = null,
        ?Request $request = null
    ): Page {
        $storedBannerPath = null;
        $hasBanner = Schema::hasColumn('pages', 'banner_image_path');
        $hasShowInFooter = Schema::hasColumn('pages', 'show_in_footer');
        $hasSortOrder = Schema::hasColumn('pages', 'sort_order');

        try {
            if ($bannerImage !== null && $hasBanner) {
                $storedBannerPath = $bannerImage->store('pages/custom', 'public');
            }

            $page = DB::transaction(function () use ($admin, $data, $storedBannerPath, $request, $hasBanner, $hasShowInFooter, $hasSortOrder): Page {
                $slug = $this->resolveUniqueCustomPageSlug($data['slug'] ?? null, (string) $data['title']);
                $isPublished = (bool) ($data['is_published'] ?? true);
                $showInFooter = $hasShowInFooter ? (bool) ($data['show_in_footer'] ?? false) : false;

                $attributes = [
                    'title' => trim((string) $data['title']),
                    'slug' => $slug,
                    'content' => $this->sanitizer->sanitize($data['content'] ?? ''),
                    'template' => 'custom',
                    'is_published' => $isPublished,
                    'seo_title' => $this->nullableTrimmed($data['seo_title'] ?? null),
                    'seo_description' => $this->nullableTrimmed($data['seo_description'] ?? null),
                    'robots_meta' => $isPublished ? 'index,follow' : 'noindex,nofollow',
                    'updated_by' => $admin->id,
                ];

                if ($hasBanner) {
                    $attributes['banner_image_path'] = $storedBannerPath;
                }
                if ($hasShowInFooter) {
                    $attributes['show_in_footer'] = $showInFooter;
                }
                if ($hasSortOrder) {
                    $attributes['sort_order'] = (int) ($data['sort_order'] ?? 10);
                }

                $page = Page::create($attributes);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'page.created',
                    "Membuat halaman custom \"{$page->title}\" (/{$page->slug}).",
                    $page,
                    [
                        'page_id' => $page->id,
                        'slug' => $page->slug,
                        'is_published' => $page->is_published,
                        'show_in_footer' => $hasShowInFooter ? $page->show_in_footer : false,
                    ],
                    $request
                );

                return $page->fresh(['updater:id,name']);
            });

            StorefrontContentService::clearCache();
            StorefrontContentService::clearPageCache($page->slug);
            app(StorefrontLayoutService::class)->clearNavigationCache();

            return $page;
        } catch (Throwable $e) {
            if ($storedBannerPath !== null) {
                Storage::disk('public')->delete($storedBannerPath);
            }
            throw $e;
        }
    }

    public function updatePage(
        User $admin,
        Page $page,
        array $data,
        ?UploadedFile $bannerImage = null,
        bool $removeBanner = false,
        ?Request $request = null
    ): Page {
        $newBannerPath = null;
        $oldBannerToDelete = null;
        $hasBanner = Schema::hasColumn('pages', 'banner_image_path');
        $hasShowInFooter = Schema::hasColumn('pages', 'show_in_footer');
        $hasSortOrder = Schema::hasColumn('pages', 'sort_order');

        try {
            if ($bannerImage !== null && $hasBanner) {
                $folder = $page->isBuiltInPolicy() ? 'pages/policies' : 'pages/custom';
                $newBannerPath = $bannerImage->store($folder, 'public');
            }

            $oldSlug = $page->slug;

            $updated = DB::transaction(function () use (
                $admin,
                $page,
                $data,
                $newBannerPath,
                $removeBanner,
                $request,
                &$oldBannerToDelete,
                $hasBanner,
                $hasShowInFooter,
                $hasSortOrder
            ): Page {
                /** @var Page $locked */
                $locked = Page::withTrashed()->whereKey($page->id)->lockForUpdate()->firstOrFail();

                if ($locked->isSystemSpecialPage()) {
                    throw new \InvalidArgumentException('Halaman sistem khusus harus dikelola melalui menunya masing-masing.');
                }

                $isBuiltIn = $locked->isBuiltInPolicy();
                $slug = $isBuiltIn
                    ? $locked->slug
                    : $this->resolveUniqueCustomPageSlug($data['slug'] ?? null, (string) $data['title'], $locked->id);

                $isPublished = (bool) ($data['is_published'] ?? true);
                $showInFooter = $isBuiltIn ? true : ($hasShowInFooter ? (bool) ($data['show_in_footer'] ?? false) : false);

                $currentBanner = $hasBanner ? ($locked->banner_image_path ?? null) : null;
                $targetBanner = $currentBanner;

                if ($newBannerPath !== null && $hasBanner) {
                    $targetBanner = $newBannerPath;
                    if ($currentBanner && ($this->isManagedPublicStoragePath($currentBanner, 'pages/custom/') || $this->isManagedPublicStoragePath($currentBanner, 'pages/policies/'))) {
                        $oldBannerToDelete = $currentBanner;
                    }
                } elseif ($removeBanner && $hasBanner) {
                    $targetBanner = null;
                    if ($currentBanner && ($this->isManagedPublicStoragePath($currentBanner, 'pages/custom/') || $this->isManagedPublicStoragePath($currentBanner, 'pages/policies/'))) {
                        $oldBannerToDelete = $currentBanner;
                    }
                }

                $attributes = [
                    'title' => trim((string) $data['title']),
                    'slug' => $slug,
                    'content' => $this->sanitizer->sanitize($data['content'] ?? ''),
                    'template' => $isBuiltIn ? ($locked->template ?: 'default') : 'custom',
                    'is_published' => $isPublished,
                    'seo_title' => $this->nullableTrimmed($data['seo_title'] ?? null),
                    'seo_description' => $this->nullableTrimmed($data['seo_description'] ?? null),
                    'robots_meta' => $isPublished ? 'index,follow' : 'noindex,nofollow',
                    'updated_by' => $admin->id,
                ];

                if ($hasBanner) {
                    $attributes['banner_image_path'] = $targetBanner;
                }
                if ($hasShowInFooter) {
                    $attributes['show_in_footer'] = $showInFooter;
                }
                if ($hasSortOrder) {
                    $attributes['sort_order'] = (int) ($data['sort_order'] ?? ($locked->sort_order ?? 10));
                }

                $locked->update($attributes);

                AuditLogService::record(
                    'user',
                    $admin->id,
                    'page.updated',
                    "Memperbarui halaman \"{$locked->title}\" (/{$locked->slug}).",
                    $locked,
                    [
                        'page_id' => $locked->id,
                        'slug' => $locked->slug,
                        'is_built_in' => $isBuiltIn,
                        'is_published' => $locked->is_published,
                        'show_in_footer' => $hasShowInFooter ? $locked->show_in_footer : false,
                    ],
                    $request
                );

                return $locked->fresh(['updater:id,name']);
            });

            if ($oldBannerToDelete !== null) {
                Storage::disk('public')->delete($oldBannerToDelete);
            }

            StorefrontContentService::clearCache();
            StorefrontContentService::clearPageCache($oldSlug);
            StorefrontContentService::clearPageCache($updated->slug);
            app(StorefrontLayoutService::class)->clearNavigationCache();

            return $updated;
        } catch (Throwable $e) {
            if ($newBannerPath !== null) {
                Storage::disk('public')->delete($newBannerPath);
            }
            throw $e;
        }
    }

    public function togglePageStatus(User $admin, Page $page, ?Request $request = null): Page
    {
        $updated = DB::transaction(function () use ($admin, $page, $request): Page {
            /** @var Page $locked */
            $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSystemSpecialPage()) {
                throw new \InvalidArgumentException('Halaman sistem khusus tidak dapat diubah dari menu ini.');
            }

            $nextState = ! $locked->is_published;

            $locked->update([
                'is_published' => $nextState,
                'robots_meta' => $nextState ? 'index,follow' : 'noindex,nofollow',
                'updated_by' => $admin->id,
            ]);

            AuditLogService::record(
                'user',
                $admin->id,
                'page.toggled',
                ($nextState ? 'Mengaktifkan (Enabled)' : 'Menonaktifkan (Disabled)')." halaman \"{$locked->title}\" (/{$locked->slug}).",
                $locked,
                ['page_id' => $locked->id, 'slug' => $locked->slug, 'is_published' => $nextState],
                $request
            );

            return $locked->fresh(['updater:id,name']);
        });

        StorefrontContentService::clearCache();
        StorefrontContentService::clearPageCache($updated->slug);
        app(StorefrontLayoutService::class)->clearNavigationCache();

        return $updated;
    }

    public function deleteCustomPage(User $admin, Page $page, ?Request $request = null): void
    {
        $slug = $page->slug;

        DB::transaction(function () use ($admin, $page, $request): void {
            /** @var Page $locked */
            $locked = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCustomPage()) {
                throw new \InvalidArgumentException('Halaman kebijakan bawaan tidak dapat dihapus.');
            }

            $locked->update([
                'is_published' => false,
                'show_in_footer' => false,
                'robots_meta' => 'noindex,nofollow',
                'updated_by' => $admin->id,
            ]);
            $locked->delete();

            AuditLogService::record(
                'user',
                $admin->id,
                'page.deleted',
                "Menghapus halaman custom \"{$locked->title}\" (/{$locked->slug}).",
                $locked,
                ['page_id' => $locked->id, 'slug' => $locked->slug],
                $request
            );
        });

        StorefrontContentService::clearCache();
        StorefrontContentService::clearPageCache($slug);
        app(StorefrontLayoutService::class)->clearNavigationCache();
    }

    protected function resolveUniqueCustomPageSlug(?string $rawSlug, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim((string) $rawSlug) !== '' ? (string) $rawSlug : $title);
        if ($base === '') {
            $base = 'halaman';
        }

        $candidate = Str::limit($base, 180, '');
        $suffix = 2;

        while (
            in_array($candidate, StorefrontContentService::RESERVED_SLUGS, true)
            || Page::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = Str::limit($base, 170, '').'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function resolveUniqueArticleSlug(?string $rawSlug, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(trim((string) $rawSlug) !== '' ? (string) $rawSlug : $title);
        if ($base === '') {
            $base = 'artikel';
        }

        $candidate = Str::limit($base, 180, '');
        $suffix = 2;

        while (
            Article::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = Str::limit($base, 170, '').'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function resolvePublishedAt(string $status, ?string $rawPublishedAt, ?string $existingPublishedAt = null): ?Carbon
    {
        $raw = trim((string) $rawPublishedAt);
        if ($raw !== '') {
            return Carbon::parse($raw);
        }

        if ($status === 'published') {
            return $existingPublishedAt ? Carbon::parse($existingPublishedAt) : now();
        }

        return $existingPublishedAt ? Carbon::parse($existingPublishedAt) : null;
    }

    protected function nullableTrimmed(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function isManagedPublicStoragePath(string $path, string $prefix): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($normalized, $prefix) && ! str_contains($normalized, '..');
    }
}
