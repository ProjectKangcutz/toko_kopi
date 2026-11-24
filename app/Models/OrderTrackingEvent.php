<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTrackingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shipment_id',
        'event_time',
        'location',
        'description',
        'source',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
