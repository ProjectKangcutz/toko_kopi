@extends('layouts.auth')

@section('title', 'Masuk Pelanggan')

@section('content')
<div class="mlm-card p-4 p-md-5">
    <div class="text-center mb-4">
        <a href="{{ route('storefront.home') }}" class="text-decoration-none d-inline-flex align-items-center gap-2 mb-2">
            <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center" style="width:42px;height:42px;">
                <i class="bi bi-shop fs-5"></i>
            </span>
        </a>
        <h1 class="h4 fw-bold mb-1">Masuk Akun Pelanggan</h1>
        <p class="small text-mlm-muted mb-0">Kelola pesanan, alamat pengiriman, dan hubungi admin toko.</p>
    </div>

    <form method="POST" action="{{ route('customer.login.submit') }}">
        @csrf
        <x-form.input name="email" label="Alamat Email" type="email" required placeholder="nama@email.com" />
        <x-form.input name="password" label="Kata Sandi" type="password" required placeholder="Masukkan kata sandi" />

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="rememberCustomer">
            <label class="form-check-label small" for="rememberCustomer">Ingat sesi saya</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">Masuk</button>
    </form>

    <div class="text-center small text-mlm-muted">
        Belum punya akun? <a href="{{ route('customer.register') }}" class="text-mlm-primary fw-semibold text-decoration-none">Daftar Sekarang</a>
    </div>
</div>
@endsection
