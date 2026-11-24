<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\CatalogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        protected CatalogService $catalogService,
    ) {
    }

    public function show(Request $request, string $slug): View
    {
        return view('storefront.catalog.category', $this->catalogService->getCategoryData($slug, $request));
    }
}
