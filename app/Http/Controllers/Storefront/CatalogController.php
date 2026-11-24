<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\CatalogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function __construct(
        protected CatalogService $catalogService,
    ) {
    }

    public function index(Request $request): View
    {
        return view('storefront.catalog.index', $this->catalogService->getCatalogData($request));
    }
}
