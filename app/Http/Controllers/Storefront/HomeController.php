<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\HomepageService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(
        protected HomepageService $homepageService,
    ) {
    }

    public function index(): View
    {
        return view('storefront.home', $this->homepageService->getHomepageData());
    }
}
