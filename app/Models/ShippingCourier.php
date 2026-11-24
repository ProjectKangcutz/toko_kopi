<?php

namespace App\Models;

use App\Services\Shipping\ShippingRateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingCourier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'logo_path',
        'is_active',
        'sort_order',
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
            'sort_order' => 'integer',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(ShippingService::class, 'courier_id');
    }
}
