<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveArticleRequest;
use App\Models\Article;
use App\Models\User;
use App\Services\Admin\ContentManagementService;
use App\Services\Content\HtmlSanitizer;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ArticleController extends Controller
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
        Gate::forUser($admin)->authorize('viewAny', Article::class);

        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'draft', 'published'], true)) {
            $status = 'all';
        }

        $articles = Article::query()
            ->with('author:id,name')
            ->when($search !== '', function ($query) use ($search) {
                $escaped = addcslashes($search, '%_\\');
                $query->where(function ($sub) use ($escaped) {
                    $sub->where('title', 'like', '%'.$escaped.'%')
                        ->orWhere('slug', 'like', '%'.$escaped.'%')
                        ->orWhere('excerpt', 'like', '%'.$escaped.'%');
                });
            })
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return view('admin.articles.index', compact('articles', 'search', 'status', 'admin'));
    }

    public function create(): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Article::class);

        $authors = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.articles.create', compact('admin', 'authors'));
    }

    public function store(SaveArticleRequest $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Article::class);

        try {
            $article = $this->contentService->createArticle(
                $admin,
                $request->validated(),
                $request->file('featured_image'),
                $request
            );

            return redirect()
                ->route('admin.articles.edit', $article)
                ->with('success', 'Artikel berhasil disimpan.');
        } catch (Throwable $e) {
            Log::error('Gagal membuat artikel admin.', [
                'admin_id' => $admin->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat menyimpan artikel.']);
        }
    }

    public function edit(Article $article): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $article);

        $authors = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.articles.edit', compact('article', 'admin', 'authors'));
    }

    public function update(SaveArticleRequest $request, Article $article): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $article);

        try {
            $this->contentService->updateArticle(
                $admin,
                $article,
                $request->validated(),
                $request->file('featured_image'),
                (bool) $request->boolean('remove_featured_image'),
                $request
            );

            return redirect()
                ->route('admin.articles.edit', $article)
                ->with('success', 'Perubahan artikel berhasil disimpan.');
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui artikel admin.', [
                'admin_id' => $admin->id,
                'article_id' => $article->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->withErrors(['error' => 'Terjadi kesalahan saat memperbarui artikel.']);
        }
    }

    public function preview(Article $article): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('view', $article);

        $article->loadMissing('author:id,name');
        $sanitizedContent = $this->sanitizer->sanitize($article->content);
        $relatedArticles = collect();
        $isPreview = true;
        $previewBackUrl = route('admin.articles.edit', $article);

        return view('storefront.articles.show', compact(
            'article',
            'sanitizedContent',
            'relatedArticles',
            'isPreview',
            'previewBackUrl'
        ));
    }

    public function previewDraft(Request $request): View
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('create', Article::class);

        $existing = null;
        if ($request->filled('article_id') && is_numeric($request->input('article_id'))) {
            $existing = Article::query()->find((int) $request->input('article_id'));
        }

        $title = trim((string) $request->input('title', '')) ?: ($existing?->title ?? 'Pratinjau Artikel');
        $slug = trim((string) $request->input('slug', '')) ?: ($existing?->slug ?? Str::slug($title) ?: 'pratinjau-artikel');
        $authorId = $request->filled('author_id') ? (int) $request->input('author_id') : ($existing?->author_id ?? $admin->id);
        $author = User::query()->find($authorId, ['id', 'name']) ?? $admin;

        $publishedAtInput = trim((string) $request->input('published_at', ''));
        $publishedAt = null;
        if ($publishedAtInput !== '') {
            try {
                $publishedAt = Carbon::parse($publishedAtInput);
            } catch (Throwable) {
                $publishedAt = $existing?->published_at;
            }
        } else {
            $publishedAt = $existing?->published_at ?? now();
        }

        $removeImage = $request->boolean('remove_featured_image');

        $article = new Article([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => trim((string) $request->input('excerpt', '')) ?: null,
            'content' => (string) $request->input('content', ''),
            'featured_image_path' => $removeImage ? null : $existing?->featured_image_path,
            'featured_image_alt' => trim((string) $request->input('featured_image_alt', '')) ?: null,
            'author_id' => $author->id,
            'status' => $request->input('status') === 'published' ? 'published' : 'draft',
            'published_at' => $publishedAt,
            'seo_title' => trim((string) $request->input('seo_title', '')) ?: null,
            'seo_description' => trim((string) $request->input('seo_description', '')) ?: null,
            'canonical_url' => $this->sanitizer->sanitizeUrl($request->input('canonical_url')),
        ]);
        $article->setRelation('author', $author);

        $sanitizedContent = $this->sanitizer->sanitize($article->content);
        $relatedArticles = collect();
        $isPreview = true;
        $previewBackUrl = $existing
            ? route('admin.articles.edit', $existing)
            : route('admin.articles.index');

        return view('storefront.articles.show', compact(
            'article',
            'sanitizedContent',
            'relatedArticles',
            'isPreview',
            'previewBackUrl'
        ));
    }

    public function publish(Request $request, Article $article): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $article);

        try {
            $this->contentService->setArticlePublishStatus($admin, $article, true, $request);

            return back()->with('success', 'Artikel berhasil dipublikasikan ke storefront.');
        } catch (Throwable $e) {
            Log::error('Gagal mempublikasikan artikel.', [
                'admin_id' => $admin->id,
                'article_id' => $article->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat mempublikasikan artikel.']);
        }
    }

    public function unpublish(Request $request, Article $article): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('update', $article);

        try {
            $this->contentService->setArticlePublishStatus($admin, $article, false, $request);

            return back()->with('success', 'Artikel berhasil ditarik kembali menjadi draf.');
        } catch (Throwable $e) {
            Log::error('Gagal menarik artikel ke draf.', [
                'admin_id' => $admin->id,
                'article_id' => $article->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat mengubah status artikel.']);
        }
    }

    public function destroy(Request $request, Article $article): RedirectResponse
    {
        /** @var User $admin */
        $admin = Auth::guard('admin')->user();
        Gate::forUser($admin)->authorize('delete', $article);

        try {
            $this->contentService->deleteArticle($admin, $article, $request);

            return redirect()
                ->route('admin.articles.index')
                ->with('success', 'Artikel berhasil dihapus dan diarsipkan dari storefront.');
        } catch (Throwable $e) {
            Log::error('Gagal menghapus artikel.', [
                'admin_id' => $admin->id,
                'article_id' => $article->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Terjadi kesalahan saat menghapus artikel.']);
        }
    }
}
