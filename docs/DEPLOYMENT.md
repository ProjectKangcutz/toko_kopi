# Panduan Deployment Staging & Production — Pasar Lokal Nusantara

Dokumen ini merupakan panduan operasional untuk menyiapkan, men-deploy, dan memverifikasi aplikasi **Pasar Lokal Nusantara** (Laravel 13) di server staging maupun produksi tanpa mereset data transaksi atau mengekspos kredensial rahasia.

---

## 1. Persyaratan Server & Ekstensi

- **PHP:** `>= 8.3` (rekomendasi PHP 8.3/8.4 FPM)
  - Ekstensi wajib: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `curl`
- **Database:** MySQL `>= 8.0` (engine `InnoDB`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`)
- **Composer:** `>= 2.7`
- **Web Server:** Nginx atau Apache (document root **wajib** mengarah ke direktori `/public`, bukan root proyek)
- **SSL / HTTPS:** Sertifikat TLS aktif (Let's Encrypt / reverse proxy TLS) wajib untuk produksi, HSTS, `SESSION_SECURE_COOKIE=true`, dan webhook Midtrans.
- **Process Manager:** Cron daemon (untuk Laravel Scheduler) + Supervisor/systemd (jika `QUEUE_CONNECTION=database`).

---

## 2. Konfigurasi Variabel Environment (`.env`)

Salin `.env.example` menjadi `.env` langsung di server, lalu isi nilainya dengan kredensial server masing-masing (**jangan pernah commit `.env` ke Git**).

> **Penting untuk Development Lokal:** Tetap gunakan `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost` (atau URL Laragon lokal), dan `SESSION_SECURE_COOKIE=false` saat mengembangkan secara lokal. Konfigurasi di bawah ini ditujukan khusus untuk server **Staging / Production HTTPS**.

```ini
APP_NAME="Pasar Lokal Nusantara"
APP_ENV=production
# Generate dengan: php artisan key:generate
APP_KEY=
# WAJIB false di staging/production
APP_DEBUG=false
APP_URL=https://domain-toko-anda.example
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
APP_FALLBACK_LOCALE=en

# Reverse proxy (isi IP proxy/Nginx atau "*" hanya jika berada di balik load balancer/Cloudflare tepercaya)
TRUSTED_PROXIES=127.0.0.1

# Database MySQL 8
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=umkm_online_shop
DB_USERNAME=umkm_app_user
DB_PASSWORD=<isi-password-kuat-di-server>

# Session & Keamanan Cookie (SESSION_SECURE_COOKIE wajib true pada domain HTTPS)
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=true

# Cache & Queue (QUEUE_CONNECTION=sync hanya jika worker tidak dijalankan)
CACHE_STORE=database
QUEUE_CONNECTION=database

# Logging (daily log rotation 14 hari)
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

# Storage
FILESYSTEM_DISK=public

# Midtrans (MIDTRANS_IS_PRODUCTION=false untuk staging Sandbox, true untuk production live)
MIDTRANS_IS_ACTIVE=true
MIDTRANS_IS_PRODUCTION=true
MIDTRANS_MERCHANT_ID=<isi-merchant-id>
MIDTRANS_CLIENT_KEY=<isi-client-key>
MIDTRANS_SERVER_KEY=<isi-server-key>
MIDTRANS_IS_SANITIZED=true
MIDTRANS_IS_3DS=true

# RajaOngkir V2 / Komerce Production
RAJAONGKIR_BASE_URL=https://rajaongkir.komerce.id/api/v1
RAJAONGKIR_API_KEY=<isi-api-key>
RAJAONGKIR_ORIGIN_ID=<isi-id-kecamatan-kelurahan-asal-toko>
RAJAONGKIR_TIMEOUT=10
```

> **Catatan Keamanan Seeder:**
> Pada `APP_ENV=production`, `UserSeeder` tidak membuat akun berpassword bawaan kecuali `SEED_OWNER_PASSWORD` / `SEED_ADMIN_PASSWORD` / `SEED_CUSTOMER_PASSWORD` diisi eksplisit. Setelah deploy awal, hapus variabel `SEED_*` dari `.env` dan rotasi password akun admin.

### 2.1 Checklist Kesiapan Integrasi Production (Midtrans & RajaOngkir V2)

Perlu diperhatikan bahwa kredensial yang disimpan melalui **Admin Dashboard (`/admin/pengaturan`)** disimpan terenkripsi di tabel `settings` dan **meng-override** nilai `.env`:

1. **Midtrans Production:**
   - Pastikan akun Midtrans Merchant Portal berada di mode **Production** dan metode pembayaran yang dibutuhkan sudah aktif.
   - Isi **Merchant ID**, **Client Key**, dan **Server Key** Production (bukan prefix `SB-`) di `.env` atau tab **Integrasi Midtrans** (`/admin/pengaturan?tab=midtrans`), lalu aktifkan **Aktifkan Midtrans** dan **Gunakan Mode Production**.
   - Atur **Payment Notification URL** di Midtrans Dashboard Production (*Settings → Configuration*):
     `https://<domain-production>/api/webhooks/midtrans`
   - Atur **Finish / Unfinish / Error Redirect URL** (jika diaktifkan di portal Midtrans) ke `https://<domain-production>/akun/pesanan`.
   - Klik tombol **Cek Integrasi Midtrans** di `/admin/pengaturan?tab=midtrans` hingga status `connected` dengan mode `Production`.
2. **RajaOngkir Komerce V2 Production:**
   - Gunakan `RAJAONGKIR_BASE_URL=https://rajaongkir.komerce.id/api/v1` (bukan host Collaborator Sandbox).
   - Isi **API Key** Production dan **Origin ID** numerik (ID kecamatan/kelurahan asal pengiriman toko) di `.env` atau tab **Integrasi RajaOngkir** (`/admin/pengaturan?tab=rajaongkir`).
   - Aktifkan toggle **Aktifkan Cek Ongkir Live RajaOngkir** agar checkout memakai tarif live (bukan tarif cadangan lokal).
   - Klik tombol **Cek Integrasi RajaOngkir** di `/admin/pengaturan?tab=rajaongkir` hingga status `connected` dan `Origin ID` terbaca valid.

---

## 3. Langkah Deploy Aman (Tanpa Reset Database)

> **PERINGATAN:** **JANGAN PERNAH** menjalankan `php artisan migrate:fresh`, `php artisan migrate:refresh`, atau `db:wipe` pada server staging yang menyimpan data uji penting ataupun server produksi.

```bash
# 1. Masuk ke mode maintenance sementara selama proses update
php artisan down --retry=60

# 2. Install dependensi PHP produksi (tanpa dev dependencies)
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Jalankan migrasi secara aman (incremental, tanpa menghapus data)
php artisan migrate --force

# 4. Pastikan seeder master (permissions/roles/settings/kurir) hanya dijalankan jika baru setup awal
#    (Seeder memakai updateOrCreate sehingga aman, namun hindari menjalankan seeder demo di produksi)
# php artisan db:seed --class=RolePermissionSeeder --force
# php artisan db:seed --class=SettingSeeder --force
# php artisan db:seed --class=ShippingCourierSeeder --force

# 5. Buat symlink storage publik (jika belum ada)
php artisan storage:link

# 6. Bersihkan dan bangun cache konfigurasi, route, view, dan event
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. Restart queue worker agar memakai kode & config terbaru
php artisan queue:restart

# 8. Keluar dari mode maintenance
php artisan up
```

---

## 4. Permission Direktori & Hardening Web Server

Pastikan kepemilikan direktori (contoh user web server `www-data`):

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Contoh Konfigurasi Nginx Server Block

```nginx
server {
    listen 443 ssl http2;
    server_name domain-toko-anda.example;

    root /var/www/pasarlokal/public;
    index index.php;

    client_max_body_size 10M;

    # Blokir file tersembunyi (.env, .git, .htaccess)
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }
}
```

---

## 5. Cron Scheduler (Pelepasan Reservasi Stok Kedaluwarsa)

Aplikasi menjadwalkan `inventory:release-expired --limit=100` setiap **10 menit** di `routes/console.php` untuk melepaskan reservasi stok aktif yang melewati `payment_due_at` secara otomatis.

Tambahkan entri berikut pada `crontab -e` milik user aplikasi/web server:

```cron
* * * * * cd /var/www/pasarlokal && php artisan schedule:run >> /dev/null 2>&1
```

Untuk verifikasi manual di server:

```bash
php artisan schedule:list
php artisan inventory:release-expired --limit=100
```

---

## 6. Queue Worker (Jika `QUEUE_CONNECTION=database`)

Buat konfigurasi Supervisor di `/etc/supervisor/conf.d/pasarlokal-worker.conf`:

```ini
[program:pasarlokal-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/pasarlokal/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/pasarlokal/storage/logs/worker.log
stopwaitsecs=3600
```

Aktifkan dengan:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start pasarlokal-worker:*
```

---

## 7. Backup & Restore Database MySQL 8

### Backup Harian (Transaksional & Konsisten)

```bash
mysqldump --single-transaction --routines --triggers -u <db_user> -p <db_name> \
  | gzip > /var/backups/pasarlokal/db_$(date +%Y%m%d_%H%M%S).sql.gz
```

### Restore Database

```bash
gunzip -c /var/backups/pasarlokal/db_YYYYMMDD_HHMMSS.sql.gz \
  | mysql -u <db_user> -p <db_name>
```

> Simpan backup di lokasi terpisah dari web root (`public/`) dan uji restore ke database staging secara berkala.

---

## 8. Smoke Test Pasca-Deploy

1. **Health Endpoint:** `GET https://<domain>/api/health` mengembalikan HTTP `200` `{"status":"ok",...}`.
2. **Storefront & Keamanan Header:** `GET /`, `/produk`, `/keranjang` mengembalikan HTTP `200`, header `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Strict-Transport-Security` (di HTTPS), dan tidak ada error asset/console.
3. **Isolasi Guard & Indexing:** `/admin/login` dan `/akun/masuk` mengembalikan `X-Robots-Tag: noindex, nofollow` dan `Cache-Control: no-store...`.
4. **RajaOngkir Ongkir Quote:** Uji pencarian wilayah (`/akun/wilayah/cari`) dan cek ongkir pada `/akun/checkout` dengan alamat valid.
5. **Midtrans Integration:** Uji inisiasi pembayaran Snap pada order uji (Sandbox di staging) dan verifikasi endpoint webhook `POST /api/webhooks/midtrans` menolak signature palsu (`403`) dan menerima notifikasi bertandatangan valid (`200`).
6. **Scheduler & Logs:** Jalankan `php artisan inventory:release-expired` tanpa error, dan periksa `storage/logs/laravel.log`, `payment.log`, dan `security.log`.
