<?php

namespace App\Services\Order;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\OrderTrackingEvent;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentHistory;
use App\Models\User;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Security\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class OrderLifecycleService
{
    public function __construct(
        protected InventoryReservationService $inventoryReservationService,
    ) {}

    /**
     * Membatalkan pesanan secara atomik dan idempoten oleh Customer atau Admin.
     */
    public function cancelOrder(Order $order, Customer|User $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor instanceof Customer) {
                if ($lockedOrder->customer_id === null || (int) $lockedOrder->customer_id !== (int) $actor->id) {
                    throw new AuthorizationException('Anda tidak memiliki akses untuk membatalkan pesanan ini.');
                }
            } else {
                if (! $actor->hasPermission('orders.cancel')) {
                    throw new AuthorizationException('Anda tidak memiliki izin mengelola pembatalan pesanan.');
                }
            }

            // Idempoten: jika pesanan sudah berstatus cancelled, pastikan tidak ada reservasi aktif tersisa tanpa efek samping ganda.
            if ($lockedOrder->order_status === 'cancelled') {
                $this->inventoryReservationService->releaseForOrder(
                    $lockedOrder,
                    'released',
                    'Pelepasan stok otomatis karena pesanan dibatalkan.',
                    $actor instanceof User ? $actor->id : null
                );

                return $lockedOrder->fresh(['shipment', 'statusHistories']);
            }

            if (
                in_array($lockedOrder->order_status, ['paid', 'processing', 'shipped', 'delivered', 'completed', 'refunded'], true)
                || in_array($lockedOrder->payment_status, ['paid', 'refunded', 'partially_refunded'], true)
                || $lockedOrder->paid_at !== null
            ) {
                throw new RuntimeException('Pesanan yang sudah dibayar, sedang diproses, atau selesai tidak dapat dibatalkan melalui jalur pembatalan biasa.');
            }

            /** @var Collection<int, Payment> $payments */
            $payments = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->get();

            $hasSuccessfulPayment = $payments->contains(function (Payment $payment): bool {
                $fraudStatus = strtolower(trim((string) $payment->fraud_status));

                return in_array($payment->status, ['settlement', 'refund', 'partial_refund'], true)
                    || ($payment->status === 'capture' && $fraudStatus === 'accept');
            });

            if ($hasSuccessfulPayment) {
                throw new RuntimeException('Pesanan sudah memiliki transaksi pembayaran yang berhasil dan tidak dapat dibatalkan melalui jalur pembatalan biasa.');
            }

            if ($actor instanceof Customer && ! in_array($lockedOrder->order_status, ['pending', 'awaiting_payment'], true)) {
                throw new RuntimeException('Pesanan yang sedang diproses tidak dapat dibatalkan secara mandiri.');
            }

            $now = now();
            $fromOrderStatus = $lockedOrder->order_status;
            $fromPaymentStatus = $lockedOrder->payment_status;

            $lockedOrder->order_status = 'cancelled';
            if (in_array($lockedOrder->payment_status, ['unpaid', 'pending'], true)) {
                $lockedOrder->payment_status = 'failed';
            }
            $lockedOrder->cancelled_at = $now;
            $lockedOrder->cancellation_reason = Str::limit(
                trim($reason ?: ($actor instanceof Customer ? 'Dibatalkan oleh pelanggan.' : 'Dibatalkan oleh admin toko.')),
                255,
                ''
            );
            $lockedOrder->save();

            $this->recordStatusHistory(
                $lockedOrder,
                'order',
                $fromOrderStatus,
                'cancelled',
                $lockedOrder->cancellation_reason,
                $actor,
                $now
            );

            if ($fromPaymentStatus !== $lockedOrder->payment_status) {
                $this->recordStatusHistory(
                    $lockedOrder,
                    'payment',
                    $fromPaymentStatus,
                    $lockedOrder->payment_status,
                    'Status pembayaran ditandai gagal karena pesanan dibatalkan.',
                    $actor,
                    $now
                );
            }

            // Lepaskan reservasi stok aktif secara idempoten.
            $this->inventoryReservationService->releaseForOrder(
                $lockedOrder,
                'released',
                'Pelepasan stok otomatis karena pesanan dibatalkan.',
                $actor instanceof User ? $actor->id : null
            );

            AuditLogService::record(
                $actor instanceof User ? 'user' : 'customer',
                $actor->id,
                'order.cancelled',
                "Pesanan {$lockedOrder->order_number} dibatalkan.",
                $lockedOrder,
                [
                    'order_id' => $lockedOrder->id,
                    'order_number' => $lockedOrder->order_number,
                    'actor_type' => $actor instanceof User ? 'admin' : 'customer',
                    'reason' => $lockedOrder->cancellation_reason,
                ]
            );

            return $lockedOrder->fresh(['shipment', 'statusHistories']);
        }, 3);
    }

    /**
     * Memproses pesanan dari paid menjadi processing (Fulfillment).
     */
    public function markProcessing(Order $order, User $admin, ?string $internalNotes = null): Order
    {
        return DB::transaction(function () use ($order, $admin, $internalNotes): Order {
            if (! $admin->hasPermission('orders.update_status')) {
                throw new AuthorizationException('Anda tidak memiliki izin memproses pesanan.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->order_status !== 'paid') {
                throw new RuntimeException("Pesanan dengan status {$lockedOrder->order_status} tidak dapat diproses ke status processing.");
            }

            if ($lockedOrder->payment_status !== 'paid') {
                throw new RuntimeException('Pesanan belum lunas dan belum dapat diproses (processing).');
            }

            $now = now();
            $fromStatus = $lockedOrder->order_status;
            $lockedOrder->order_status = 'processing';
            if ($internalNotes !== null && trim($internalNotes) !== '') {
                $lockedOrder->admin_internal_notes = trim($internalNotes);
            }
            $lockedOrder->save();

            $this->recordStatusHistory(
                $lockedOrder,
                'order',
                $fromStatus,
                'processing',
                'Pesanan dikonfirmasi dan mulai diproses oleh admin.',
                $admin,
                $now
            );

            AuditLogService::record(
                'user',
                $admin->id,
                'order.processing',
                "Pesanan {$lockedOrder->order_number} mulai diproses (processing).",
                $lockedOrder
            );

            return $lockedOrder->fresh(['shipment', 'statusHistories']);
        }, 3);
    }

    /**
     * Memperbarui informasi pengiriman dan mengubah status menjadi shipped.
     */
    public function markShipped(
        Order $order,
        User $admin,
        string $trackingNumber,
        ?string $courierName = null,
        ?string $serviceName = null,
        ?string $notes = null
    ): Order {
        $cleanTracking = Str::limit(trim($trackingNumber), 100, '');
        if ($cleanTracking === '') {
            throw new InvalidArgumentException('Nomor resi pengiriman wajib diisi.');
        }

        return DB::transaction(function () use ($order, $admin, $cleanTracking, $courierName, $serviceName, $notes): Order {
            if (! $admin->hasPermission('shipments.update') && ! $admin->hasPermission('orders.update_status')) {
                throw new AuthorizationException('Anda tidak memiliki izin memperbarui pengiriman pesanan.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->order_status !== 'processing') {
                throw new RuntimeException("Pesanan berstatus {$lockedOrder->order_status} tidak dapat dikirim (harus berstatus processing).");
            }

            if ($lockedOrder->payment_status !== 'paid') {
                throw new RuntimeException('Pesanan belum lunas dan tidak dapat dikirim.');
            }

            /** @var ?Shipment $shipment */
            $shipment = Shipment::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            if (! $shipment) {
                throw new RuntimeException('Data shipment untuk pesanan ini tidak ditemukan.');
            }

            $now = now();
            $fromShipmentStatus = $shipment->status;
            $fromOrderStatus = $lockedOrder->order_status;

            $shipment->tracking_number = $cleanTracking;
            if ($courierName !== null && trim($courierName) !== '') {
                $shipment->courier_name = Str::limit(trim($courierName), 100, '');
            }
            if ($serviceName !== null && trim($serviceName) !== '') {
                $shipment->service_name = Str::limit(trim($serviceName), 120, '');
            }
            $shipment->status = 'shipped';
            $shipment->shipped_at = $now;
            $shipment->updated_by = $admin->id;
            $shipment->save();

            $shippingNote = trim((string) ($notes ?: "Nomor resi {$cleanTracking} dimasukkan dan pesanan dikirim."));

            ShipmentHistory::create([
                'shipment_id' => $shipment->id,
                'from_status' => $fromShipmentStatus,
                'to_status' => 'shipped',
                'tracking_number' => $cleanTracking,
                'notes' => $shippingNote,
                'updated_by' => $admin->id,
                'created_at' => $now,
            ]);

            OrderTrackingEvent::create([
                'shipment_id' => $shipment->id,
                'event_time' => $now,
                'location' => null,
                'description' => "Pesanan diserahkan ke kurir {$shipment->courier_name} ({$shipment->service_name}) dengan nomor resi {$cleanTracking}.",
                'source' => 'admin_manual',
                'created_at' => $now,
            ]);

            $lockedOrder->order_status = 'shipped';
            $lockedOrder->shipment_status = 'shipped';
            $lockedOrder->save();

            $this->recordStatusHistory(
                $lockedOrder,
                'order',
                $fromOrderStatus,
                'shipped',
                "Pesanan dikirim dengan nomor resi {$cleanTracking}.",
                $admin,
                $now
            );

            $this->recordStatusHistory(
                $lockedOrder,
                'shipment',
                $fromShipmentStatus,
                'shipped',
                "Shipment dikirim (resi: {$cleanTracking}).",
                $admin,
                $now
            );

            AuditLogService::record(
                'user',
                $admin->id,
                'order.shipped',
                "Pesanan {$lockedOrder->order_number} dikirim dengan resi {$cleanTracking}.",
                $lockedOrder,
                [
                    'order_id' => $lockedOrder->id,
                    'tracking_number' => $cleanTracking,
                    'courier_name' => $shipment->courier_name,
                    'service_name' => $shipment->service_name,
                ]
            );

            return $lockedOrder->fresh(['shipment.histories', 'shipment.trackingEvents', 'statusHistories']);
        }, 3);
    }

    /**
     * Mencatat pembaruan tracking manual oleh admin saat API kurir eksternal belum tersedia.
     */
    public function addManualTrackingEvent(
        Order $order,
        User $admin,
        string $description,
        ?string $location = null
    ): OrderTrackingEvent {
        $cleanDescription = trim($description);
        if ($cleanDescription === '') {
            throw new InvalidArgumentException('Keterangan aktivitas tracking wajib diisi.');
        }

        return DB::transaction(function () use ($order, $admin, $cleanDescription, $location): OrderTrackingEvent {
            if (! $admin->hasPermission('shipments.update') && ! $admin->hasPermission('orders.update_status')) {
                throw new AuthorizationException('Anda tidak memiliki izin mencatat aktivitas pengiriman.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedOrder->order_status, ['shipped', 'delivered'], true)) {
                throw new RuntimeException('Aktivitas tracking manual hanya dapat dicatat untuk pesanan yang sedang dikirim atau telah sampai.');
            }

            /** @var ?Shipment $shipment */
            $shipment = Shipment::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            if (! $shipment) {
                throw new RuntimeException('Data shipment untuk pesanan ini tidak ditemukan.');
            }

            $now = now();
            $event = OrderTrackingEvent::create([
                'shipment_id' => $shipment->id,
                'event_time' => $now,
                'location' => $location !== null && trim($location) !== '' ? Str::limit(trim($location), 255, '') : null,
                'description' => $cleanDescription,
                'source' => 'admin_manual',
                'created_at' => $now,
            ]);

            $shipment->updated_by = $admin->id;
            $shipment->save();

            AuditLogService::record(
                'user',
                $admin->id,
                'shipment.tracking_added',
                "Catatan tracking manual ditambahkan pada pesanan {$lockedOrder->order_number}.",
                $lockedOrder,
                [
                    'shipment_id' => $shipment->id,
                    'location' => $event->location,
                    'description' => $event->description,
                ]
            );

            return $event;
        }, 3);
    }

    /**
     * Menandai pesanan telah sampai (delivered).
     */
    public function markDelivered(Order $order, User $admin, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order, $admin, $notes): Order {
            if (! $admin->hasPermission('shipments.update') && ! $admin->hasPermission('orders.update_status')) {
                throw new AuthorizationException('Anda tidak memiliki izin memperbarui status pengiriman.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->order_status !== 'shipped') {
                throw new RuntimeException('Pesanan harus berstatus shipped sebelum dapat ditandai delivered.');
            }

            /** @var ?Shipment $shipment */
            $shipment = Shipment::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            $now = now();
            $fromShipmentStatus = $shipment?->status ?? 'shipped';
            $fromOrderStatus = $lockedOrder->order_status;
            $deliveryNotes = trim($notes ?: 'Paket telah diterima oleh penerima.');

            if ($shipment) {
                $shipment->status = 'delivered';
                $shipment->delivered_at = $now;
                $shipment->updated_by = $admin->id;
                $shipment->save();

                ShipmentHistory::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $fromShipmentStatus,
                    'to_status' => 'delivered',
                    'tracking_number' => $shipment->tracking_number,
                    'notes' => $deliveryNotes,
                    'updated_by' => $admin->id,
                    'created_at' => $now,
                ]);

                OrderTrackingEvent::create([
                    'shipment_id' => $shipment->id,
                    'event_time' => $now,
                    'location' => null,
                    'description' => $deliveryNotes,
                    'source' => 'admin_manual',
                    'created_at' => $now,
                ]);
            }

            $lockedOrder->order_status = 'delivered';
            $lockedOrder->shipment_status = 'delivered';
            $lockedOrder->save();

            $this->recordStatusHistory(
                $lockedOrder,
                'order',
                $fromOrderStatus,
                'delivered',
                'Pesanan dikonfirmasi telah sampai (delivered).',
                $admin,
                $now
            );

            if ($shipment) {
                $this->recordStatusHistory(
                    $lockedOrder,
                    'shipment',
                    $fromShipmentStatus,
                    'delivered',
                    'Shipment delivered.',
                    $admin,
                    $now
                );
            }

            AuditLogService::record(
                'user',
                $admin->id,
                'order.delivered',
                "Pesanan {$lockedOrder->order_number} dikonfirmasi telah sampai.",
                $lockedOrder
            );

            return $lockedOrder->fresh(['shipment.histories', 'shipment.trackingEvents', 'statusHistories']);
        }, 3);
    }

    /**
     * Menandai pesanan selesai (completed).
     */
    public function markCompleted(Order $order, Customer|User $actor, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $notes): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor instanceof User) {
                if (! $actor->hasPermission('orders.update_status')) {
                    throw new AuthorizationException('Anda tidak memiliki izin menyelesaikan pesanan.');
                }

                if ($lockedOrder->order_status !== 'delivered') {
                    throw new RuntimeException('Admin hanya dapat menyelesaikan pesanan yang sudah berstatus delivered.');
                }
            } else {
                if ($lockedOrder->customer_id === null || (int) $lockedOrder->customer_id !== (int) $actor->id) {
                    throw new AuthorizationException('Anda tidak memiliki akses ke pesanan ini.');
                }

                if (! in_array($lockedOrder->order_status, ['shipped', 'delivered'], true)) {
                    throw new RuntimeException('Pesanan harus sudah dikirim atau diterima sebelum dapat diselesaikan.');
                }
            }

            /** @var ?Shipment $shipment */
            $shipment = Shipment::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();

            $now = now();
            $fromOrderStatus = $lockedOrder->order_status;

            if ($shipment && $shipment->status !== 'delivered') {
                $fromShipmentStatus = $shipment->status;
                $shipment->status = 'delivered';
                $shipment->delivered_at = $shipment->delivered_at ?? $now;
                if ($actor instanceof User) {
                    $shipment->updated_by = $actor->id;
                }
                $shipment->save();

                ShipmentHistory::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $fromShipmentStatus,
                    'to_status' => 'delivered',
                    'tracking_number' => $shipment->tracking_number,
                    'notes' => 'Pesanan dikonfirmasi telah diterima oleh pelanggan.',
                    'updated_by' => $actor instanceof User ? $actor->id : null,
                    'created_at' => $now,
                ]);

                $this->recordStatusHistory(
                    $lockedOrder,
                    'shipment',
                    $fromShipmentStatus,
                    'delivered',
                    'Shipment diterima saat pesanan dikonfirmasi selesai.',
                    $actor,
                    $now
                );
            }

            $lockedOrder->order_status = 'completed';
            $lockedOrder->completed_at = $now;
            $lockedOrder->shipment_status = 'delivered';
            $lockedOrder->save();

            $this->recordStatusHistory(
                $lockedOrder,
                'order',
                $fromOrderStatus,
                'completed',
                trim($notes ?: ($actor instanceof User ? 'Pesanan diselesaikan oleh admin.' : 'Pesanan dikonfirmasi selesai oleh pelanggan.')),
                $actor,
                $now
            );

            AuditLogService::record(
                $actor instanceof User ? 'user' : 'customer',
                $actor->id,
                'order.completed',
                "Pesanan {$lockedOrder->order_number} selesai (completed).",
                $lockedOrder
            );

            return $lockedOrder->fresh(['shipment.histories', 'shipment.trackingEvents', 'statusHistories']);
        }, 3);
    }

    protected function recordStatusHistory(
        Order $order,
        string $statusType,
        ?string $fromStatus,
        string $toStatus,
        string $notes,
        Customer|User $actor,
        ?CarbonInterface $timestamp = null
    ): void {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status_type' => $statusType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'actor_type' => $actor instanceof User ? 'admin' : 'customer',
            'actor_id' => $actor->id,
            'created_at' => $timestamp ?? now(),
        ]);
    }
}
