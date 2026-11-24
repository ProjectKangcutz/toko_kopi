<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'payment_gateway',
        'midtrans_order_id',
        'snap_token',
        'snap_redirect_url',
        'payment_type',
        'payment_channel',
        'gross_amount',
        'status',
        'midtrans_transaction_id',
        'fraud_status',
        'paid_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(PaymentWebhookLog::class, 'midtrans_order_id', 'midtrans_order_id')
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }
}
