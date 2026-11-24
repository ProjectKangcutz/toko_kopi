<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Security\AuditLogService;
use App\Services\Security\GuardSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLoginForm(): View
    {
        return view('admin.auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $throttleKey = 'admin-login:'.Str::lower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Terlalu banyak percobaan login admin. Coba lagi dalam {$seconds} detik."]);
        }

        if (! Auth::guard('admin')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Email atau kata sandi admin tidak valid.']);
        }

        $admin = Auth::guard('admin')->user();
        if (! $admin->is_active) {
            Auth::guard('admin')->logout();

            return back()->withErrors(['email' => 'Akun administrator ini sedang dinonaktifkan.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $admin->update(['last_login_at' => now()]);

        AuditLogService::record('user', $admin->id, 'admin.login', 'Admin login ke dashboard.', $admin, [], $request);

        $intended = (string) $request->session()->get('url.intended', '');
        if ($intended !== '') {
            $intendedPath = (string) (parse_url($intended, PHP_URL_PATH) ?? '');
            if ($intendedPath !== '/admin' && ! str_starts_with($intendedPath, '/admin/')) {
                $request->session()->forget('url.intended');
            }
        }

        return redirect()->intended(route('admin.dashboard'))
            ->with('success', 'Berhasil masuk ke Admin Dashboard.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $adminId = Auth::guard('admin')->id();
        GuardSessionService::logout($request, 'admin');

        if ($adminId) {
            AuditLogService::record('user', $adminId, 'admin.logout', 'Admin keluar dari dashboard.', null, [], $request);
        }

        return redirect()->route('admin.login')
            ->with('success', 'Anda telah keluar dari Admin Dashboard.');
    }
}
