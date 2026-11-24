# Checklist Pengujian Midtrans Sandbox End-to-End

Dokumen ini memandu tim QA / Developer saat menguji integrasi **Midtrans Snap & Webhook** di mode **Sandbox** pada staging atau lokal (dengan tunnel HTTPS) menggunakan metode pembayaran dan kredensial uji resmi Midtrans Sandbox.

> **Penting:** Jangan pernah menanamkan *Server Key* atau *Client Key* asli di repository, kode frontend, atau dokumen ini. Masukkan kredensial Sandbox hanya ke `.env` server atau tabel `settings` terenkripsi.

---

## 1. Persiapan Konfigurasi Sandbox

1. Pastikan akun **Midtrans Merchant Portal** berada di mode **Sandbox**.
2. Catat **Merchant ID**, **Client Key (`SB-Mid-client-...`)**, dan **Server Key (`SB-Mid-server-...`)** dari menu *Settings → Access Keys*.
3. Konfigurasi kredensial Sandbox di `.env` (lalu jalankan `php artisan optimize:clear`) atau pada tabel `settings` (`midtrans`):
   - `MIDTRANS_IS_ACTIVE=true` (otomatis aktif jika `MIDTRANS_CLIENT_KEY` & `MIDTRANS_SERVER_KEY` sudah diisi di `.env` dan DB `settings` belum menyimpan key khusus)
   - `MIDTRANS_IS_PRODUCTION=false` (`midtrans.is_production = 0`)
   - `MIDTRANS_MERCHANT_ID=G...`
   - `MIDTRANS_CLIENT_KEY=SB-Mid-client-...`
   - `MIDTRANS_SERVER_KEY=SB-Mid-server-...`
   - `MIDTRANS_IS_3DS=true`
   - `MIDTRANS_IS_SANITIZED=true`
4. Atur **Notification URL (Webhook)** di Midtrans Sandbox Portal (*Settings → Configuration → Payment Notification URL*):
   - Staging/Domain HTTPS: `https://<domain-staging>/api/webhooks/midtrans`
   - Lokal Laragon (via `ngrok` / `cloudflared`): `https://<subdomain-tunnel>/api/webhooks/midtrans`
5. Pastikan rute `POST /api/webhooks/midtrans` dapat diakses dari internet tanpa halangan login/CSRF dan mengembalikan `HTTP 403` saat dipanggil tanpa signature valid.

---

## 2. Pengujian Automated Verification (PHPUnit + Http::fake)

Sebelum pengujian manual Sandbox live, jalankan seluruh pengujian integrasi Midtrans di Laragon menggunakan database test (`umkm_shop_test`):

```bash
php artisan test tests/Feature/CustomerMidtransPaymentTest.php
php artisan test tests/Feature/MidtransWebhookVerificationAndLoggingTest.php
php artisan test tests/Feature/MidtransPaymentStateSynchronizationTest.php
php artisan test tests/Feature/MidtransInventoryCommitAndReleaseTest.php
php artisan test tests/Feature/Phase9EndToEndAndSecurityTest.php
```

---

## 3. Skenario Uji Manual Midtrans Sandbox

Gunakan halaman resmi **Midtrans Sandbox Payment Simulator** (`https://simulator.sandbox.midtrans.com/`) sesuai metode pembayaran pada Snap popup:

| No | Skenario | Metode / Simulator Midtrans | Hasil yang Diharapkan di Aplikasi & Database |
| :--- | :--- | :--- | :--- |
| 1 | **Inisiasi Snap & Reuse Token** | Klik **Bayar Sekarang** pada `/akun` atau `/akun/pesanan/{id}` | Endpoint `POST /akun/pesanan/{id}/bayar` mengembalikan HTTP `201`, Snap popup terbuka memuat `app.sandbox.midtrans.com/snap/snap.js`, `order_status=awaiting_payment`, `payments.status=pending`. Klik ulang sebelum expired me-reuse `snap_token` yang sama (`HTTP 200`). |
| 2 | **Virtual Account (BCA/BNI/BRI/Mandiri)** | Pilih VA di Snap → salin nomor VA → bayar lewat Simulator VA Midtrans (`settlement`) | Webhook `pending` lalu `settlement` masuk dengan `signature_valid=1`, `processed=1`. `order.payment_status=paid`, `order.order_status=paid`, `stock_reservations.status=committed`, stok fisik berkurang & `reserved_stock` kembali `0`. |
| 3 | **QRIS / GoPay Sandbox** | Pilih QRIS/GoPay → selesaikan pembayaran di Simulator QRIS/GoPay Midtrans | Webhook `settlement` menyinkronkan `payment_type=qris`/`gopay`, `order_status=paid`, stok di-commit tepat 1 kali. |
| 4 | **Kartu Kredit 3DS — Accept** | Gunakan nomor kartu tes 3DS resmi Midtrans Sandbox (`accept`), OTP Simulator `112233` | Webhook `capture` dengan `fraud_status=accept` → `order.payment_status=paid`, `order.order_status=paid`, stok di-commit. |
| 5 | **Kartu Kredit 3DS — Challenge / Deny** | Gunakan skenario FDS `challenge` lalu `deny` (atau kartu tes `deny`) di Midtrans Portal/Simulator | Saat `challenge`, order **tetap** `awaiting_payment` & stok masih `reserved` (belum paid). Saat `deny`, `payments.status=deny`, `order.payment_status=failed`, order tetap terbuka untuk retry selama `payment_due_at` belum lewat. |
| 6 | **Pembayaran Kedaluwarsa (`expire`) & Retry (`P2`)** | Trigger `expire` di Simulator Midtrans saat `payment_due_at` order masih aktif | `P1` menjadi `expire`, `order.payment_status=expired`, tombol **Bayar Sekarang** tetap tersedia jika `payment_due_at` belum lewat. Klik bayar lagi membuat attempt baru `...-P2-...` hingga `settlement`. |
| 7 | **Webhook Duplikat & Out-of-Order** | Kirim ulang payload webhook yang sama atau kirim status lama setelah `settlement` | `payment_webhook_logs` menolak duplikasi (`idempotency_key`), HTTP `200`, stok dan status order tidak pernah double-deduct atau turun ke `expired`/`failed`. |
| 8 | **Kegagalan Signature Palsu** | Kirim `POST /api/webhooks/midtrans` dengan `signature_key` salah | HTTP `403`, tercatat di `storage/logs/security.log`, tidak mengubah `orders`, `payments`, maupun stok. |
| 9 | **Browser Callback Isolation** | Tutup popup atau manipulasi response browser saat `onSuccess` | Status order **hanya** berubah setelah webhook server-side terverifikasi, bukan dari redirect atau event JS browser. |
| 10 | **Fulfillment Pasca-Settlement** | Login Admin `/admin/pesanan/{id}` → `Proses` → `Kirim (Resi)` → `Tambah Tracking` → `Sampai` → `Selesaikan` (oleh pelanggan/admin) | Status berjalan urut `paid → processing → shipped → delivered → completed`, seluruh mutasi tercatat di `order_status_histories` dan `shipment_histories`. |

---

## 4. Query / Titik Pemeriksaan Verifikasi

Setelah pengujian manual Sandbox, periksa konsistensi tabel berikut:

- `payment_webhook_logs`: `signature_valid = 1`, `processed = 1`, `processing_error IS NULL`.
- `payments` & `payment_transactions`: `midtrans_order_id` unik per percobaan (`-P1-`, `-P2-`), nominal `gross_amount` sama persis dengan `orders.grand_total`.
- `stock_reservations` & `stock_movements`:
  - Order `paid` → `stock_reservations.status = 'committed'`, `stock_movements.type = 'out'`, tidak ada `reserved_stock` yang menggantung.
  - Order `cancelled` / `expired` → `stock_reservations.status IN ('released', 'expired')`, `stock_movements.type = 'release'`, `reserved_stock` dikembalikan penuh.
- File log server: `storage/logs/payment.log` dan `storage/logs/security.log` bersih dari *Server Key* / *Authorization header* (selalu ter-redaksi `[REDACTED]`).
