<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    protected $fillable = [
        'order_id',
        'courier_code',
        'courier_name',
        'service_code',
        'service_name',
        'etd',
        'shipping_cost',
        'weight_grams',
        'tracking_number',
        'status',
        'shipped_at',
        'delivered_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'shipping_cost' => 'decimal:2',
            'weight_grams' => 'integer',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ShipmentHistory::class)->orderByDesc('created_at');
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(OrderTrackingEvent::class)->orderByDesc('event_time');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
