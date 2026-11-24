<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryReservationService;
use Illuminate\Console\Command;

class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'inventory:release-expired {--limit=100 : Jumlah maksimum order yang diproses per batch}';

    protected $description = 'Melepaskan reservasi stok aktif yang batas waktu pembayaran (payment_due_at) nya telah kedaluwarsa.';

    public function handle(InventoryReservationService $reservationService): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->info("Memulai pelepasan reservasi stok kedaluwarsa (limit batch: {$limit})...");

        try {
            $releasedCount = $reservationService->releaseExpiredReservations($limit);
            $this->info("Berhasil melepaskan reservasi stok untuk {$releasedCount} pesanan kedaluwarsa.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal melepaskan reservasi stok kedaluwarsa: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
