@extends('layouts.auth')

@section('title', 'Login Administrator')

@section('content')
<div class="mlm-card p-4 p-md-5">
    <div class="text-center mb-4">
        <span class="rounded-3 bg-mlm-secondary text-mlm-primary d-inline-flex align-items-center justify-content-center mb-2" style="width:44px;height:44px;">
            <i class="bi bi-shield-lock fs-4"></i>
        </span>
        <h1 class="h4 fw-bold mb-1">Portal Administrator</h1>
        <p class="small text-mlm-muted mb-0">Akses khusus pengelola toko UMKM terotorisasi.</p>
    </div>

    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf
        <x-form.input name="email" label="Email Administrator" type="email" required placeholder="admin@pasarlokal.test" />
        <x-form.input name="password" label="Kata Sandi" type="password" required placeholder="Masukkan kata sandi" />

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="rememberAdmin">
            <label class="form-check-label small" for="rememberAdmin">Pertahankan sesi login</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">Masuk Dashboard Admin</button>
    </form>
</div>
@endsection
