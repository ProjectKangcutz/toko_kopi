<?php

namespace App\Http\Middleware;

use App\Services\Security\GuardSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            return redirect()->guest(route('admin.login'));
        }

        $admin = Auth::guard('admin')->user();
        if (! $admin->is_active) {
            GuardSessionService::logout($request, 'admin');

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'Akun administrator Anda tidak aktif.']);
        }

        return $next($request);
    }
}
