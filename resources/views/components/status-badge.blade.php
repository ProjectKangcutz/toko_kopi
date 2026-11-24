@props(['status', 'type' => 'order'])

@php
    $map = [
        'paid' => ['bg-success-subtle text-success', 'Lunas'],
        'settlement' => ['bg-success-subtle text-success', 'Lunas'],
        'completed' => ['bg-success-subtle text-success', 'Selesai'],
        'delivered' => ['bg-success-subtle text-success', 'Terkirim'],
        'processing' => ['bg-info-subtle text-info-emphasis', 'Diproses'],
        'packing' => ['bg-info-subtle text-info-emphasis', 'Dikemas'],
        'shipped' => ['bg-primary-subtle text-primary', 'Dikirim'],
        'in_transit' => ['bg-primary-subtle text-primary', 'Dalam Perjalanan'],
        'pending' => ['bg-warning-subtle text-warning-emphasis', 'Menunggu'],
        'awaiting_payment' => ['bg-warning-subtle text-warning-emphasis', 'Menunggu Pembayaran'],
        'unpaid' => ['bg-warning-subtle text-warning-emphasis', 'Belum Dibayar'],
        'unfulfilled' => ['bg-secondary-subtle text-secondary-emphasis', 'Belum Dikirim'],
        'cancelled' => ['bg-danger-subtle text-danger', 'Dibatalkan'],
        'failed' => ['bg-danger-subtle text-danger', 'Gagal'],
        'expired' => ['bg-danger-subtle text-danger', 'Kedaluwarsa'],
        'deny' => ['bg-danger-subtle text-danger', 'Ditolak'],
        'refunded' => ['bg-dark-subtle text-dark', 'Refund'],
    ];
    [$classes, $label] = $map[$status] ?? ['bg-secondary-subtle text-secondary-emphasis', ucfirst(str_replace('_', ' ', (string) $status))];
@endphp

<span {{ $attributes->merge(['class' => "badge rounded-pill fw-semibold px-3 py-2 {$classes}"]) }}>
    {{ $label }}
</span>
