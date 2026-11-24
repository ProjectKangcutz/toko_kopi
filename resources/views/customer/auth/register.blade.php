@extends('layouts.auth')

@section('title', 'Daftar Akun Pelanggan')

@section('content')
<div class="mlm-card p-4 p-md-5">
    <div class="text-center mb-4">
        <h1 class="h4 fw-bold mb-1">Buat Akun Pelanggan</h1>
        <p class="small text-mlm-muted mb-0">Belanja lebih cepat dan pantau pengiriman pesanan Anda.</p>
    </div>

    <form method="POST" action="{{ route('customer.register.submit') }}">
        @csrf
        <x-form.input name="name" label="Nama Lengkap" required placeholder="Contoh: Budi Santoso" />
        <x-form.input name="email" label="Alamat Email" type="email" required placeholder="nama@email.com" />
        <x-form.input name="phone" label="Nomor HP / WhatsApp" required placeholder="0812xxxxxxxx" />
        <x-form.input name="password" label="Kata Sandi" type="password" required placeholder="Minimal 8 karakter (huruf & angka)" />
        <x-form.input name="password_confirmation" label="Konfirmasi Kata Sandi" type="password" required placeholder="Ulangi kata sandi" />

        <button type="submit" class="btn btn-primary w-100 mb-3">Daftar Akun</button>
    </form>

    <div class="text-center small text-mlm-muted">
        Sudah memiliki akun? <a href="{{ route('customer.login') }}" class="text-mlm-primary fw-semibold text-decoration-none">Masuk di sini</a>
    </div>
</div>
@endsection
