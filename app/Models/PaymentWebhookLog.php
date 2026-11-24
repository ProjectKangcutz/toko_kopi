<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookLog extends Model
{
    protected $fillable = [
        'idempotency_key',
        'gateway',
        'midtrans_order_id',
        'transaction_id',
        'transaction_status',
        'status_code',
        'signature_valid',
        'processed',
        'processing_error',
        'headers',
        'payload',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'processed' => 'boolean',
            'headers' => 'array',
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'midtrans_order_id', 'midtrans_order_id');
    }
}
