<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Content\HtmlSanitizer;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function __construct(
        protected StorefrontContentService $contentService,
        protected HtmlSanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $articles = $this->contentService->publishedArticles($search, 9);

        return view('storefront.articles.index', compact('articles', 'search'));
    }

    public function show(string $slug): View
    {
        $article = $this->contentService->findPublishedArticleBySlug($slug);
        abort_if($article === null, 404);

        $sanitizedContent = $this->sanitizer->sanitize($article->content);
        $relatedArticles = $this->contentService->relatedArticles($article, 3);
        $isPreview = false;
        $previewBackUrl = null;

        return view('storefront.articles.show', compact(
            'article',
            'sanitizedContent',
            'relatedArticles',
            'isPreview',
            'previewBackUrl'
        ));
    }
}
