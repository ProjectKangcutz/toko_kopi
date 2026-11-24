<?php

use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureActiveCustomerForCart;
use App\Http\Middleware\EnsureAdminAuth;
use App\Http\Middleware\EnsureCustomerAuth;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/customer.php'));

            Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'auth.customer' => EnsureCustomerAuth::class,
            'customer.active' => EnsureActiveCustomerForCart::class,
            'auth.admin' => EnsureAdminAuth::class,
            'permission' => CheckPermission::class,
            'maintenance.store' => CheckMaintenanceMode::class,
        ]);

        // Webhook Midtrans tidak menggunakan CSRF cookie browser;
        // keamanan diverifikasi melalui SHA-512 signature_key & idempotency.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/midtrans',
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.login');
            }

            if ($request->is('akun') || $request->is('akun/*')) {
                return route('customer.login');
            }

            return route('customer.login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.dashboard');
            }

            if ($request->is('akun') || $request->is('akun/*')) {
                return route('customer.dashboard');
            }

            return route('storefront.home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReportDuplicates();
    })
    ->create();
