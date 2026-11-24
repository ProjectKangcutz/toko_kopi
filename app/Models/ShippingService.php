<?php

namespace App\Models;

use App\Services\Shipping\ShippingRateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingService extends Model
{
    protected $fillable = [
        'courier_id',
        'service_code',
        'service_name',
        'description',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saved(static fn () => ShippingRateService::invalidateCache());
        static::deleted(static fn () => ShippingRateService::invalidateCache());
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(ShippingCourier::class, 'courier_id');
    }
}
