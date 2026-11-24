<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'cost_price',
        'price',
        'promo_price',
        'weight_grams',
        'stock',
        'reserved_stock',
        'min_stock',
        'image_path',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => HomepageService::clearCache());
        static::deleted(fn () => HomepageService::clearCache());
        static::restored(fn () => HomepageService::clearCache());
    }

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'weight_grams' => 'integer',
            'stock' => 'integer',
            'reserved_stock' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_attributes',
            'product_variant_id',
            'product_attribute_value_id'
        )->withTimestamps();
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_variant_id');
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'product_variant_id');
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class, 'product_variant_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_variant_id');
    }

    public function availableStock(): int
    {
        return max(0, (int) $this->stock - (int) $this->reserved_stock);
    }

    public function hasActivePromo(?Product $parentProduct = null, ?CarbonInterface $at = null): bool
    {
        if ($this->promo_price === null || (float) $this->promo_price <= 0 || (float) $this->promo_price >= (float) $this->price) {
            return false;
        }

        $product = $parentProduct ?? ($this->relationLoaded('product') ? $this->product : $this->product()->first());

        return $product ? $product->isPromoPeriodActive($at) : true;
    }

    public function effectivePrice(?Product $parentProduct = null, ?CarbonInterface $at = null): float
    {
        if ($this->hasActivePromo($parentProduct, $at)) {
            return (float) $this->promo_price;
        }

        return (float) $this->price;
    }
}
