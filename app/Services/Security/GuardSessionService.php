<?php

namespace App\Services\Security;

use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GuardSessionService
{
    /**
     * Mengeluarkan satu guard secara terisolasi tanpa menghapus data session
     * milik guard lain maupun guest cart (`storefront_guest_cart`).
     */
    public static function logout(Request $request, string $guard): void
    {
        Auth::guard($guard)->logout();

        if ($request->hasSession()) {
            $session = $request->session();
            $session->forget('password_hash_'.$guard);

            $intended = (string) $session->get('url.intended', '');
            if ($intended !== '') {
                $path = (string) (parse_url($intended, PHP_URL_PATH) ?? '');
                if ($guard === 'admin' && ($path === '/admin' || str_starts_with($path, '/admin/'))) {
                    $session->forget('url.intended');
                } elseif ($guard === 'customer' && ($path === '/akun' || str_starts_with($path, '/akun/'))) {
                    $session->forget('url.intended');
                }
            }

            // Hancurkan session ID lama dan rotasi session ID + CSRF token secara aman
            // sambil mempertahankan atribut session yang masih sah (mis. storefront_guest_cart).
            $session->migrate(true);
            $session->regenerateToken();
        }

        if ($guard === 'customer') {
            StorefrontLayoutService::forgetRequestCartCount();
        }
    }
}
