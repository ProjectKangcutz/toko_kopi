<?php

use App\Http\Controllers\Admin\AboutPageController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContactPageController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login.submit');
    });

    Route::middleware('auth.admin')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('permission:dashboard.view')
            ->name('dashboard');

        Route::prefix('kategori')->name('categories.')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])
                ->middleware('permission:categories.view')
                ->name('index');
            Route::get('/tambah', [CategoryController::class, 'create'])
                ->middleware('permission:categories.create')
                ->name('create');
            Route::post('/', [CategoryController::class, 'store'])
                ->middleware('permission:categories.create')
                ->name('store');
            Route::get('/{category}/edit', [CategoryController::class, 'edit'])
                ->whereNumber('category')
                ->middleware('permission:categories.update')
                ->name('edit');
            Route::match(['put', 'patch'], '/{category}', [CategoryController::class, 'update'])
                ->whereNumber('category')
                ->middleware('permission:categories.update')
                ->name('update');
            Route::delete('/{category}', [CategoryController::class, 'destroy'])
                ->whereNumber('category')
                ->middleware('permission:categories.delete')
                ->name('destroy');
        });

        Route::prefix('produk')->name('products.')->group(function () {
            Route::get('/', [ProductController::class, 'index'])
                ->middleware('permission:products.view')
                ->name('index');
            Route::get('/tambah', [ProductController::class, 'create'])
                ->middleware('permission:products.create')
                ->name('create');
            Route::post('/', [ProductController::class, 'store'])
                ->middleware('permission:products.create')
                ->name('store');
            Route::get('/{product}/edit', [ProductController::class, 'edit'])
                ->whereNumber('product')
                ->middleware('permission:products.update')
                ->name('edit');
            Route::match(['put', 'patch'], '/{product}', [ProductController::class, 'update'])
                ->whereNumber('product')
                ->middleware('permission:products.update')
                ->name('update');
            Route::delete('/{product}', [ProductController::class, 'destroy'])
                ->whereNumber('product')
                ->middleware('permission:products.delete')
                ->name('destroy');

            Route::post('/{product}/gambar', [ProductController::class, 'storeImages'])
                ->whereNumber('product')
                ->middleware('permission:products.update')
                ->name('images.store');
            Route::patch('/{product}/gambar/{image}/utama', [ProductController::class, 'setPrimaryImage'])
                ->whereNumber('product')
                ->whereNumber('image')
                ->middleware('permission:products.update')
                ->name('images.primary');
            Route::delete('/{product}/gambar/{image}', [ProductController::class, 'destroyImage'])
                ->whereNumber('product')
                ->whereNumber('image')
                ->middleware('permission:products.update')
                ->name('images.destroy');

            Route::post('/{product}/varian', [ProductController::class, 'storeVariant'])
                ->whereNumber('product')
                ->middleware('permission:products.update')
                ->name('variants.store');
            Route::match(['put', 'patch'], '/{product}/varian/{variant}', [ProductController::class, 'updateVariant'])
                ->whereNumber('product')
                ->whereNumber('variant')
                ->middleware('permission:products.update')
                ->name('variants.update');
            Route::delete('/{product}/varian/{variant}', [ProductController::class, 'destroyVariant'])
                ->whereNumber('product')
                ->whereNumber('variant')
                ->middleware('permission:products.update')
                ->name('variants.destroy');
        });

        Route::prefix('inventori')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])
                ->middleware('permission:inventory.view')
                ->name('index');
            Route::post('/penyesuaian', [InventoryController::class, 'adjust'])
                ->middleware('permission:inventory.adjust')
                ->name('adjust');
        });

        Route::prefix('pengaturan')->name('settings.')->group(function () {
            Route::get('/', [SettingController::class, 'edit'])
                ->middleware('permission:settings.view')
                ->name('edit');
            Route::match(['put', 'patch'], '/toko', [SettingController::class, 'updateStore'])
                ->middleware('permission:settings.manage')
                ->name('store.update');
            Route::match(['put', 'patch'], '/integrasi', [SettingController::class, 'updateIntegrations'])
                ->middleware('permission:settings.integrations')
                ->name('integrations.update');
            Route::match(['put', 'patch'], '/midtrans', [SettingController::class, 'updateMidtrans'])
                ->middleware('permission:settings.integrations')
                ->name('midtrans.update');
            Route::post('/midtrans/cek', [SettingController::class, 'checkMidtrans'])
                ->middleware('permission:settings.integrations')
                ->name('midtrans.check');
            Route::match(['put', 'patch'], '/rajaongkir', [SettingController::class, 'updateRajaOngkir'])
                ->middleware('permission:settings.integrations')
                ->name('rajaongkir.update');
            Route::post('/rajaongkir/cek', [SettingController::class, 'checkRajaOngkir'])
                ->middleware('permission:settings.integrations')
                ->name('rajaongkir.check');
        });

        Route::prefix('artikel')->name('articles.')->group(function () {
            Route::get('/', [ArticleController::class, 'index'])
                ->middleware('permission:content.view')
                ->name('index');
            Route::get('/tambah', [ArticleController::class, 'create'])
                ->middleware('permission:content.manage')
                ->name('create');
            Route::post('/', [ArticleController::class, 'store'])
                ->middleware('permission:content.manage')
                ->name('store');
            Route::match(['post', 'put', 'patch'], '/preview', [ArticleController::class, 'previewDraft'])
                ->middleware('permission:content.manage')
                ->name('preview.draft');
            Route::get('/{article}/edit', [ArticleController::class, 'edit'])
                ->whereNumber('article')
                ->middleware('permission:content.manage')
                ->name('edit');
            Route::get('/{article}/preview', [ArticleController::class, 'preview'])
                ->whereNumber('article')
                ->middleware('permission:content.view')
                ->name('preview');
            Route::match(['put', 'patch'], '/{article}', [ArticleController::class, 'update'])
                ->whereNumber('article')
                ->middleware('permission:content.manage')
                ->name('update');
            Route::patch('/{article}/publish', [ArticleController::class, 'publish'])
                ->whereNumber('article')
                ->middleware('permission:content.manage')
                ->name('publish');
            Route::patch('/{article}/unpublish', [ArticleController::class, 'unpublish'])
                ->whereNumber('article')
                ->middleware('permission:content.manage')
                ->name('unpublish');
            Route::delete('/{article}', [ArticleController::class, 'destroy'])
                ->whereNumber('article')
                ->middleware('permission:content.manage')
                ->name('destroy');
        });

        Route::prefix('halaman')->name('pages.')->group(function () {
            Route::get('/', [PageController::class, 'index'])
                ->middleware('permission:content.view')
                ->name('index');
            Route::get('/tambah', [PageController::class, 'create'])
                ->middleware('permission:content.manage')
                ->name('create');
            Route::post('/', [PageController::class, 'store'])
                ->middleware('permission:content.manage')
                ->name('store');
            Route::match(['post', 'put', 'patch'], '/preview', [PageController::class, 'previewDraft'])
                ->middleware('permission:content.manage')
                ->name('preview.draft');
            Route::get('/{page}/edit', [PageController::class, 'edit'])
                ->whereNumber('page')
                ->middleware('permission:content.manage')
                ->name('edit');
            Route::get('/{page}/preview', [PageController::class, 'preview'])
                ->whereNumber('page')
                ->middleware('permission:content.view')
                ->name('preview');
            Route::match(['put', 'patch'], '/{page}', [PageController::class, 'update'])
                ->whereNumber('page')
                ->middleware('permission:content.manage')
                ->name('update');
            Route::patch('/{page}/toggle', [PageController::class, 'toggle'])
                ->whereNumber('page')
                ->middleware('permission:content.manage')
                ->name('toggle');
            Route::delete('/{page}', [PageController::class, 'destroy'])
                ->whereNumber('page')
                ->middleware('permission:content.manage')
                ->name('destroy');
        });

        Route::prefix('tentang-kami')->name('about.')->group(function () {
            Route::get('/', [AboutPageController::class, 'edit'])
                ->middleware('permission:content.view')
                ->name('edit');
            Route::match(['put', 'patch'], '/', [AboutPageController::class, 'update'])
                ->middleware('permission:content.manage')
                ->name('update');
            Route::get('/preview', [AboutPageController::class, 'preview'])
                ->middleware('permission:content.view')
                ->name('preview');
            Route::match(['post', 'put', 'patch'], '/preview', [AboutPageController::class, 'previewDraft'])
                ->middleware('permission:content.manage')
                ->name('preview.draft');
        });

        Route::prefix('faq')->name('faqs.')->group(function () {
            Route::get('/', [FaqController::class, 'index'])
                ->middleware('permission:content.view')
                ->name('index');
            Route::get('/tambah', [FaqController::class, 'create'])
                ->middleware('permission:content.manage')
                ->name('create');
            Route::post('/', [FaqController::class, 'store'])
                ->middleware('permission:content.manage')
                ->name('store');
            Route::get('/{faq}/edit', [FaqController::class, 'edit'])
                ->whereNumber('faq')
                ->middleware('permission:content.manage')
                ->name('edit');
            Route::match(['put', 'patch'], '/{faq}', [FaqController::class, 'update'])
                ->whereNumber('faq')
                ->middleware('permission:content.manage')
                ->name('update');
            Route::patch('/{faq}/toggle', [FaqController::class, 'toggle'])
                ->whereNumber('faq')
                ->middleware('permission:content.manage')
                ->name('toggle');
            Route::delete('/{faq}', [FaqController::class, 'destroy'])
                ->whereNumber('faq')
                ->middleware('permission:content.manage')
                ->name('destroy');
        });

        Route::prefix('kontak')->name('contact.')->group(function () {
            Route::get('/', [ContactPageController::class, 'edit'])
                ->middleware('permission:content.view')
                ->name('edit');
            Route::match(['put', 'patch'], '/', [ContactPageController::class, 'update'])
                ->middleware('permission:content.manage')
                ->name('update');
        });

        Route::prefix('pesanan')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])
                ->middleware('permission:orders.view')
                ->name('index');
            Route::get('/{order}', [OrderController::class, 'show'])
                ->whereNumber('order')
                ->middleware('permission:orders.view')
                ->name('show');
            Route::post('/{order}/proses', [OrderController::class, 'markProcessing'])
                ->whereNumber('order')
                ->middleware('permission:orders.update_status')
                ->name('processing');
            Route::post('/{order}/kirim', [OrderController::class, 'markShipped'])
                ->whereNumber('order')
                ->middleware('permission:shipments.update')
                ->name('shipped');
            Route::post('/{order}/tracking', [OrderController::class, 'addTrackingEvent'])
                ->whereNumber('order')
                ->middleware('permission:shipments.update')
                ->name('tracking.store');
            Route::post('/{order}/sampai', [OrderController::class, 'markDelivered'])
                ->whereNumber('order')
                ->middleware('permission:shipments.update')
                ->name('delivered');
            Route::post('/{order}/selesai', [OrderController::class, 'markCompleted'])
                ->whereNumber('order')
                ->middleware('permission:orders.update_status')
                ->name('completed');
            Route::post('/{order}/batal', [OrderController::class, 'cancel'])
                ->whereNumber('order')
                ->middleware('permission:orders.cancel')
                ->name('cancel');
        });
    });
});
