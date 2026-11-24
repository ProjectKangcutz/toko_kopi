<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\ProductDetailService;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        protected ProductDetailService $productDetailService,
    ) {
    }

    public function show(string $slug): View
    {
        return view('storefront.products.show', $this->productDetailService->getProductDetailData($slug));
    }
}
