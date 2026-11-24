<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Content\HtmlSanitizer;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(
        protected StorefrontContentService $contentService,
        protected HtmlSanitizer $sanitizer,
    ) {
    }

    public function about(): View
    {
        $data = $this->contentService->aboutPageData();
        abort_if($data['page'] === null, 404);

        $page = $data['page'];
        $aboutSettings = $data['settings'];
        $sanitizedContent = $this->sanitizer->sanitize($page->content);
        $isPreview = false;
        $previewBackUrl = null;

        return view('storefront.pages.about', compact(
            'page',
            'aboutSettings',
            'sanitizedContent',
            'isPreview',
            'previewBackUrl'
        ));
    }

    public function contact(): View
    {
        $data = $this->contentService->contactPageData();
        abort_if($data['page'] === null, 404);

        $page = $data['page'];
        $contact = $data['contact'];
        $sanitizedContent = $this->sanitizer->sanitize($page->content);

        return view('storefront.pages.contact', compact('page', 'contact', 'sanitizedContent'));
    }

    public function show(string $slug): View
    {
        $page = $this->contentService->findPublishedPageBySlug($slug);
        abort_if($page === null, 404);

        $sanitizedContent = $this->sanitizer->sanitize($page->content);
        $isPreview = false;
        $previewBackUrl = null;

        return view('storefront.pages.policy', compact('page', 'sanitizedContent', 'isPreview', 'previewBackUrl'));
    }

    public function showPolicy(string $slug): View
    {
        return $this->show($slug);
    }
}
