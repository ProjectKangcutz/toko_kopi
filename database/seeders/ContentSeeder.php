<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Menu;
use App\Models\Page;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            ['Tentang Kami', 'tentang-kami', '<p>Kami adalah toko online UMKM yang menghadirkan produk lokal pilihan dengan standar kualitas terbaik dan pengiriman ke seluruh Indonesia.</p>'],
            ['Kontak', 'kontak', '<p>Hubungi tim layanan pelanggan kami melalui formulir kontak, WhatsApp, atau fitur pesan di dashboard pelanggan.</p>', 'contact'],
            ['Kebijakan Privasi', 'kebijakan-privasi', '<p>Data pribadi dan transaksi pelanggan dijaga kerahasiaannya dan hanya digunakan untuk pemrosesan pesanan serta layanan pelanggan.</p>'],
            ['Syarat dan Ketentuan', 'syarat-ketentuan', '<p>Dengan berbelanja di toko kami, Anda menyetujui ketentuan transaksi, pembayaran, dan pengiriman yang berlaku.</p>'],
            ['Kebijakan Pengiriman', 'kebijakan-pengiriman', '<p>Pesanan yang terkonfirmasi lunas sebelum pukul 15.00 WIB akan diproses pada hari kerja yang sama menggunakan kurir pilihan Anda.</p>'],
            ['Kebijakan Pengembalian', 'kebijakan-pengembalian', '<p>Pengajuan pengembalian barang dapat dilakukan maksimal 3x24 jam setelah paket diterima dengan menyertakan video unboxing.</p>'],
        ];

        $policyOrderMap = [
            'kebijakan-privasi' => 1,
            'syarat-ketentuan' => 2,
            'kebijakan-pengiriman' => 3,
            'kebijakan-pengembalian' => 4,
        ];

        foreach ($pages as $page) {
            $slug = $page[1];
            Page::updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $page[0],
                    'content' => $page[2],
                    'template' => $page[3] ?? 'default',
                    'is_published' => true,
                    'show_in_footer' => isset($policyOrderMap[$slug]),
                    'sort_order' => $policyOrderMap[$slug] ?? 0,
                    'seo_title' => $page[0],
                    'seo_description' => strip_tags($page[2]),
                ]
            );
        }

        $headerMenus = [
            ['Beranda', '/', 1],
            ['Produk', '/produk', 2],
            ['Artikel', '/artikel', 3],
            ['Tentang Kami', '/tentang-kami', 4],
            ['FAQ', '/faq', 5],
            ['Kontak', '/kontak', 6],
        ];

        foreach ($headerMenus as [$label, $url, $order]) {
            Menu::updateOrCreate(
                ['location' => 'header', 'url' => $url],
                ['label' => $label, 'sort_order' => $order, 'is_active' => true]
            );
        }

        $policyMenus = [
            ['Kebijakan Privasi', '/kebijakan-privasi', 1],
            ['Syarat & Ketentuan', '/syarat-ketentuan', 2],
            ['Kebijakan Pengiriman', '/kebijakan-pengiriman', 3],
            ['Kebijakan Pengembalian', '/kebijakan-pengembalian', 4],
        ];

        foreach ($policyMenus as [$label, $url, $order]) {
            Menu::updateOrCreate(
                ['location' => 'footer_policy', 'url' => $url],
                ['label' => $label, 'sort_order' => $order, 'is_active' => true]
            );
        }

        $faqs = [
            ['Pemesanan', 'Apakah saya bisa berbelanja tanpa mendaftar akun?', 'Ya, tersedia fitur Guest Checkout. Namun dengan akun pelanggan Anda dapat melacak pesanan dan menghubungi admin dengan lebih mudah.', 1],
            ['Pembayaran', 'Metode pembayaran apa saja yang tersedia?', 'Pembayaran diproses aman melalui Midtrans (Transfer Bank Virtual Account, QRIS, E-Wallet, dan metode aktif lainnya).', 2],
            ['Pengiriman', 'Bagaimana cara menghitung ongkos kirim?', 'Ongkos kirim dihitung otomatis berdasarkan berat produk dan alamat tujuan Anda melalui layanan kurir resmi.', 3],
        ];

        foreach ($faqs as [$category, $question, $answer, $order]) {
            Faq::updateOrCreate(
                ['question' => $question],
                ['category' => $category, 'answer' => $answer, 'sort_order' => $order, 'is_active' => true]
            );
        }
    }
}
