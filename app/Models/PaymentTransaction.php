<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'payment_id',
        'transaction_id',
        'event_type',
        'status',
        'gross_amount',
        'payment_type',
        'signature_verified',
        'raw_payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'signature_verified' => 'boolean',
            'raw_payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
