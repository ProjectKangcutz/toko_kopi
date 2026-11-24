<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\Security\GuardSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCustomerForCart
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if ($customer instanceof Customer && $customer->status !== 'active') {
            GuardSessionService::logout($request, 'customer');

            $message = 'Akun pelanggan Anda sedang dinonaktifkan. Silakan hubungi admin toko.';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            return redirect()->route('customer.login')
                ->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
