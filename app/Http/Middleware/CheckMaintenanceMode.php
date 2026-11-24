<?php

namespace App\Http\Middleware;

use App\Services\Setting\StoreSettingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin') || $request->is('admin/*') || $request->is('api/webhooks/*') || $request->is('up')) {
            return $next($request);
        }

        try {
            $maintenance = app(StoreSettingService::class)->get('store', 'maintenance_mode', false);
            if ($maintenance && ! Auth::guard('admin')->check()) {
                return response()->view('errors.503', [], 503);
            }
        } catch (Throwable) {
            // Abaikan saat tabel settings belum dimigrasi.
        }

        return $next($request);
    }
}
