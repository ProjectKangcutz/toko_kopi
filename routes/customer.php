<?php

use App\Http\Controllers\Customer\AddressController;
use App\Http\Controllers\Customer\AuthController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Customer\PaymentController;
use App\Http\Controllers\Customer\RegionLookupController;
use App\Http\Controllers\Customer\ShippingQuoteController;
use Illuminate\Support\Facades\Route;

Route::prefix('akun')->name('customer.')->group(function () {
    Route::middleware('guest:customer')->group(function () {
        Route::get('/masuk', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/masuk', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login.submit');

        Route::get('/daftar', [AuthController::class, 'showRegisterForm'])->name('register');
        Route::post('/daftar', [AuthController::class, 'register'])
            ->middleware('throttle:login')
            ->name('register.submit');
    });

    Route::middleware('auth.customer')->group(function () {
        Route::post('/keluar', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::prefix('alamat')->name('addresses.')->group(function () {
            Route::get('/', [AddressController::class, 'index'])->name('index');
            Route::get('/tambah', [AddressController::class, 'create'])->name('create');
            Route::post('/', [AddressController::class, 'store'])->name('store');
            Route::get('/{address}/edit', [AddressController::class, 'edit'])
                ->whereNumber('address')
                ->name('edit');
            Route::match(['put', 'patch'], '/{address}', [AddressController::class, 'update'])
                ->whereNumber('address')
                ->name('update');
            Route::patch('/{address}/utama', [AddressController::class, 'setPrimary'])
                ->whereNumber('address')
                ->name('set-primary');
            Route::delete('/{address}', [AddressController::class, 'destroy'])
                ->whereNumber('address')
                ->name('destroy');
        });

        Route::prefix('wilayah')->name('regions.')->middleware('throttle:region-lookup')->group(function () {
            Route::get('/cari', [RegionLookupController::class, 'search'])->name('search');
            Route::get('/provinsi', [RegionLookupController::class, 'provinces'])->name('provinces');
            Route::get('/provinsi/{provinceId}/kota', [RegionLookupController::class, 'cities'])->name('cities');
            Route::get('/kota/{cityId}/kecamatan', [RegionLookupController::class, 'districts'])->name('districts');
            Route::get('/kecamatan/{districtId}/kelurahan', [RegionLookupController::class, 'subdistricts'])->name('subdistricts');
        });

        Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
        Route::post('/checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:checkout')
            ->name('checkout.store');

        Route::get('/pesanan', [OrderController::class, 'index'])->name('orders.index');

        Route::get('/pesanan/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')
            ->name('orders.show');

        Route::post('/pesanan/{order}/batal', [OrderController::class, 'cancel'])
            ->whereNumber('order')
            ->name('orders.cancel');

        Route::post('/pesanan/{order}/selesai', [OrderController::class, 'complete'])
            ->whereNumber('order')
            ->name('orders.complete');

        Route::post('/pesanan/{order}/bayar', [PaymentController::class, 'initiate'])
            ->whereNumber('order')
            ->middleware('throttle:payment-initiate')
            ->name('orders.payment.initiate');

        Route::post('/pesanan/{order}/cek-pembayaran', [PaymentController::class, 'checkStatus'])
            ->whereNumber('order')
            ->middleware('throttle:payment-initiate')
            ->name('orders.payment.status');

        Route::post('/pengiriman/quote', [ShippingQuoteController::class, 'quote'])
            ->middleware('throttle:shipping-quote')
            ->name('shipping.quote');
    });
});
