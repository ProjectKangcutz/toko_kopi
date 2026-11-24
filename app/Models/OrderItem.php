<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'product_image_snapshot',
        'sku_snapshot',
        'regular_price_snapshot',
        'unit_price_snapshot',
        'is_promo_snapshot',
        'cost_price_snapshot',
        'weight_grams_snapshot',
        'quantity',
        'line_total',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => HomepageService::clearCache());
        static::deleted(fn () => HomepageService::clearCache());
    }

    protected function casts(): array
    {
        return [
            'regular_price_snapshot' => 'decimal:2',
            'unit_price_snapshot' => 'decimal:2',
            'is_promo_snapshot' => 'boolean',
            'cost_price_snapshot' => 'decimal:2',
            'weight_grams_snapshot' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
