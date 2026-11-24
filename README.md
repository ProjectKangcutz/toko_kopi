# Pasar Lokal Nusantara — Online Shop UMKM Profesional (Laravel 13)

Aplikasi e-commerce UMKM single-store berbasis **Laravel 13**, **PHP 8.3+**, **MySQL 8**, **Blade**, **Bootstrap 5**, dan **Alpine.js** dengan konsep desain **Modern Local Market**.

## Spesifikasi Arsitektur (Phase 1 & 2)

- **Backend:** Laravel 13 (Modular Services, Actions, Policies, RBAC Middleware)
- **Database:** MySQL 8 (`InnoDB`, `utf8mb4_unicode_ci`) — 53 tabel relasional (termasuk snapshot transaksi order, reservasi stok, webhook logs, dan audit logs)
- **Autentikasi Terisolasi:** Guard `customer` (`/akun`) terpisah dari guard `admin` (`/admin`)
- **Integrasi Pembayaran:** Midtrans Snap & Webhook (verifikasi signature SHA-512 + idempotency; kredensial disimpan di `.env` / `settings` terenkripsi)
- **Integrasi Ongkos Kirim:** RajaOngkir API V2 (server-side calculation & logging; API key hanya di backend)
- **Frontend:** Blade Templates + Bootstrap 5.3 + Alpine.js 3.14 + `public/assets/css/modern-local-market.css`

> **Catatan tentang Vite & `package.json`:**
> Pada Phase 2, seluruh layout Blade memuat CSS/JS *Modern Local Market* secara langsung dari `public/assets/` serta Bootstrap 5 & Alpine.js via CDN sehingga aplikasi Laravel berjalan tanpa wajib menjalankan `vite build`. File `package.json` dan `vite.config.ts` di root saat ini merupakan bawaan workspace AI Studio.

---

## Persyaratan Server Lokal

- PHP >= 8.3 (ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`)
- Composer >= 2.7
- MySQL >= 8.0

---

## Instalasi & Pengujian Lokal

```bash
# 1. Install dependensi PHP
composer install

# 2. Salin environment dan generate APP_KEY
cp .env.example .env
php artisan key:generate

# 3. Buat database MySQL 8:
#    - umkm_online_shop (untuk aplikasi)
#    - umkm_shop_test   (untuk php artisan test / phpunit.xml)
#    Lalu sesuaikan DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD di .env

# 4. Jalankan migration dan seeder
php artisan migrate --seed
php artisan storage:link

# 5. Jalankan automated test suite
php artisan test

# 6. Jalankan server pengembangan
php artisan serve
```

---

## Akun Seed Awal (Khusus Local / Development)

Pada environment `local`/`testing`, `UserSeeder` membuat akun contoh berikut (atau menggunakan `SEED_OWNER_PASSWORD`, `SEED_ADMIN_PASSWORD`, `SEED_CUSTOMER_PASSWORD` dari `.env`):

| Role | URL Login | Email | Password Default (Local) |
| :--- | :--- | :--- | :--- |
| Owner / Super Admin | `/admin/login` | `owner@pasarlokal.test` | `Password!123` |
| Admin Toko | `/admin/login` | `admin@pasarlokal.test` | `Password!123` |
| Pelanggan Contoh | `/akun/masuk` | `pelanggan@pasarlokal.test` | `Password!123` |

**Keamanan:** Pada environment `production`, `UserSeeder` **tidak akan** membuat akun berpassword default tanpa variabel password eksplisit di `.env`. Segera ubah seluruh password bawaan setelah login pertama.

---

## Dokumentasi Operasional & Produksi (Phase 9)

- **[Panduan Deployment Staging & Production (`docs/DEPLOYMENT.md`)](docs/DEPLOYMENT.md):** Persyaratan server, konfigurasi `.env` produksi, migrasi aman tanpa reset (`migrate --force`), caching, Cron Scheduler (`inventory:release-expired`), Queue Worker, backup/restore MySQL, dan smoke test pasca-deploy.
- **[Checklist Pengujian Midtrans Sandbox (`docs/MIDTRANS_SANDBOX_CHECKLIST.md`)](docs/MIDTRANS_SANDBOX_CHECKLIST.md):** Panduan lengkap verifikasi end-to-end Midtrans Snap, Virtual Account, QRIS, Kartu Kredit 3DS, retry pembayaran, webhook idempotency, dan rekonsiliasi stok.
# toko_kopi
