<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Customer\RegisterRequest;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Security\AuditLogService;
use App\Services\Security\GuardSessionService;
use App\Services\Storefront\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(
        protected CartService $cartService,
    ) {
    }

    public function showLoginForm(): View
    {
        return view('customer.auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $throttleKey = 'customer-login:'.Str::lower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik."]);
        }

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (! Auth::guard('customer')->attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Email atau kata sandi tidak sesuai.']);
        }

        $customer = Auth::guard('customer')->user();
        if ($customer->status !== 'active') {
            Auth::guard('customer')->logout();

            return back()->withErrors(['email' => 'Akun pelanggan Anda sedang dinonaktifkan.']);
        }

        RateLimiter::clear($throttleKey);
        $guestCartSnapshot = $this->cartService->readGuestSessionItems();
        $request->session()->regenerate();
        $customer->update(['last_login_at' => now()]);

        $mergeResult = $this->cartService->mergeGuestCartToCustomer($customer, $guestCartSnapshot);
        if (! empty($mergeResult['warnings'])) {
            $request->session()->flash('warning', implode(' ', $mergeResult['warnings']));
        }

        AuditLogService::record('customer', $customer->id, 'customer.login', 'Pelanggan berhasil login.', $customer, [], $request);

        $intended = (string) $request->session()->get('url.intended', '');
        if ($intended !== '') {
            $intendedPath = (string) (parse_url($intended, PHP_URL_PATH) ?? '');
            if ($intendedPath === '/admin' || str_starts_with($intendedPath, '/admin/')) {
                $request->session()->forget('url.intended');
            }
        }

        return redirect()->intended(route('customer.dashboard'))
            ->with('success', 'Selamat datang kembali, '.$customer->name.'!');
    }

    public function showRegisterForm(): View
    {
        return view('customer.auth.register');
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $customer = DB::transaction(function () use ($request) {
            $customer = Customer::create([
                'name' => $request->input('name'),
                'email' => Str::lower($request->input('email')),
                'phone' => $request->input('phone'),
                'password' => $request->input('password'),
                'status' => 'active',
            ]);

            CustomerProfile::create([
                'customer_id' => $customer->id,
                'preferred_locale' => 'id',
            ]);

            return $customer;
        });

        $guestCartSnapshot = $this->cartService->readGuestSessionItems();
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        $mergeResult = $this->cartService->mergeGuestCartToCustomer($customer, $guestCartSnapshot);
        if (! empty($mergeResult['warnings'])) {
            $request->session()->flash('warning', implode(' ', $mergeResult['warnings']));
        }

        AuditLogService::record('customer', $customer->id, 'customer.register', 'Pelanggan baru mendaftar.', $customer, [], $request);

        return redirect()->route('customer.dashboard')
            ->with('success', 'Akun berhasil dibuat. Selamat berbelanja!');
    }

    public function logout(Request $request): RedirectResponse
    {
        $customerId = Auth::guard('customer')->id();
        GuardSessionService::logout($request, 'customer');

        if ($customerId) {
            AuditLogService::record('customer', $customerId, 'customer.logout', 'Pelanggan logout.', null, [], $request);
        }

        return redirect()->route('storefront.home')
            ->with('success', 'Anda telah keluar dari akun pelanggan.');
    }
}
