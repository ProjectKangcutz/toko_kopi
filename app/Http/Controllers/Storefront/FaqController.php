<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Content\HtmlSanitizer;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function __construct(
        protected StorefrontContentService $contentService,
        protected HtmlSanitizer $sanitizer,
    ) {
    }

    public function index(): View
    {
        $groupedFaqs = $this->contentService->activeFaqsGrouped();
        $sanitizedAnswers = [];

        foreach ($groupedFaqs as $faqs) {
            foreach ($faqs as $faq) {
                $sanitizedAnswers[$faq->id] = $this->sanitizer->sanitize($faq->answer);
            }
        }

        return view('storefront.pages.faq', compact('groupedFaqs', 'sanitizedAnswers'));
    }
}
