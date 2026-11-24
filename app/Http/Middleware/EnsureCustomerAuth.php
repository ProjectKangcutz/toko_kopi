<?php

namespace App\Http\Middleware;

use App\Services\Security\GuardSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('customer')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Silakan masuk sebagai pelanggan terlebih dahulu.',
                ], 401);
            }

            return redirect()->guest(route('customer.login'));
        }

        $customer = Auth::guard('customer')->user();
        if ($customer->status !== 'active') {
            GuardSessionService::logout($request, 'customer');

            $message = 'Akun pelanggan Anda sedang dinonaktifkan. Silakan hubungi admin toko.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                ], 403);
            }

            return redirect()->route('customer.login')
                ->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
