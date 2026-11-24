<?php

use App\Http\Controllers\Storefront\ArticleController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\CategoryController;
use App\Http\Controllers\Storefront\FaqController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\Support\Facades\Route;

Route::middleware('maintenance.store')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('storefront.home');
    Route::get('/produk', [CatalogController::class, 'index'])->name('storefront.catalog');
    Route::get('/produk/{slug}', [ProductController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('storefront.product');
    Route::get('/kategori/{slug}', [CategoryController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('storefront.category');

    Route::get('/artikel', [ArticleController::class, 'index'])->name('storefront.articles');
    Route::get('/artikel/{slug}', [ArticleController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('storefront.article');
    Route::get('/tentang-kami', [PageController::class, 'about'])->name('storefront.about');
    Route::get('/faq', [FaqController::class, 'index'])->name('storefront.faq');
    Route::get('/kontak', [PageController::class, 'contact'])->name('storefront.contact');
    Route::get('/{slug}', [PageController::class, 'show'])
        ->where(
            'slug',
            '^(?!admin$|akun$|api$|up$|storage$|assets$|produk$|kategori$|keranjang$|artikel$|tentang-kami$|faq$|kontak$|webhooks?$)[a-z0-9]+(?:-[a-z0-9]+)*$'
        )
        ->name('storefront.page');

    Route::middleware('customer.active')->group(function () {
        Route::get('/keranjang', [CartController::class, 'index'])->name('storefront.cart');
        Route::post('/keranjang/tambah', [CartController::class, 'store'])->name('storefront.cart.add');
        Route::patch('/keranjang/{item}', [CartController::class, 'update'])
            ->where('item', '[0-9]+(-[0-9]+)?')
            ->name('storefront.cart.update');
        Route::delete('/keranjang/{item}', [CartController::class, 'destroy'])
            ->where('item', '[0-9]+(-[0-9]+)?')
            ->name('storefront.cart.remove');
        Route::delete('/keranjang', [CartController::class, 'clear'])->name('storefront.cart.clear');
    });
});
