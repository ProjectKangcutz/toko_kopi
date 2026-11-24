<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'slug',
        'short_description',
        'description',
        'specifications',
        'cost_price',
        'price',
        'promo_price',
        'promo_start_at',
        'promo_end_at',
        'weight_grams',
        'length_cm',
        'width_cm',
        'height_cm',
        'stock',
        'reserved_stock',
        'min_stock',
        'has_variants',
        'is_featured',
        'is_active',
        'sold_count',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'canonical_url',
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
            'promo_start_at' => 'datetime',
            'promo_end_at' => 'datetime',
            'weight_grams' => 'integer',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'stock' => 'integer',
            'reserved_stock' => 'integer',
            'min_stock' => 'integer',
            'has_variants' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'sold_count' => 'integer',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActiveAndSellable(Builder $query): Builder
    {
        return $query
            ->where('products.is_active', true)
            ->where(function (Builder $q) {
                $q->where(function (Builder $simple) {
                    $simple->where('products.has_variants', false)
                        ->whereColumn('products.stock', '>', 'products.reserved_stock');
                })->orWhere(function (Builder $varianted) {
                    $varianted->where('products.has_variants', true)
                        ->whereHas('variants', function (Builder $vq) {
                            $vq->where('product_variants.is_active', true)
                                ->whereColumn('product_variants.stock', '>', 'product_variants.reserved_stock');
                        });
                });
            });
    }

    public function scopeCurrentlyOnPromo(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $now = $at ?? now();

        return $query
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('products.promo_start_at')
                    ->orWhere('products.promo_start_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('products.promo_end_at')
                    ->orWhere('products.promo_end_at', '>=', $now);
            })
            ->where(function (Builder $q) {
                $q->where(function (Builder $simple) {
                    $simple->where('products.has_variants', false)
                        ->whereNotNull('products.promo_price')
                        ->where('products.promo_price', '>', 0)
                        ->whereColumn('products.promo_price', '<', 'products.price');
                })->orWhere(function (Builder $varianted) {
                    $varianted->where('products.has_variants', true)
                        ->whereHas('variants', function (Builder $vq) {
                            $vq->where('product_variants.is_active', true)
                                ->whereColumn('product_variants.stock', '>', 'product_variants.reserved_stock')
                                ->whereNotNull('product_variants.promo_price')
                                ->where('product_variants.promo_price', '>', 0)
                                ->whereColumn('product_variants.promo_price', '<', 'product_variants.price');
                        });
                });
            });
    }

    public function availableStock(): int
    {
        if ($this->has_variants) {
            $variants = $this->relationLoaded('variants')
                ? $this->variants
                : $this->variants()->where('is_active', true)->get();

            return (int) $variants
                ->where('is_active', true)
                ->sum(fn (ProductVariant $variant) => $variant->availableStock());
        }

        return max(0, (int) $this->stock - (int) $this->reserved_stock);
    }

    public function isPromoPeriodActive(?CarbonInterface $at = null): bool
    {
        $now = $at ?? now();
        $afterStart = ! $this->promo_start_at || $now->greaterThanOrEqualTo($this->promo_start_at);
        $beforeEnd = ! $this->promo_end_at || $now->lessThanOrEqualTo($this->promo_end_at);

        return $afterStart && $beforeEnd;
    }

    public function hasActivePromo(?CarbonInterface $at = null): bool
    {
        return $this->promo_price !== null
            && (float) $this->promo_price > 0
            && (float) $this->promo_price < (float) $this->price
            && $this->isPromoPeriodActive($at);
    }

    public function effectivePrice(?CarbonInterface $at = null): float
    {
        if ($this->hasActivePromo($at)) {
            return (float) $this->promo_price;
        }

        return (float) $this->price;
    }

    public function displayPricing(?CarbonInterface $at = null): array
    {
        $now = $at ?? now();

        if ($this->has_variants) {
            $variants = ($this->relationLoaded('variants')
                ? $this->variants
                : $this->variants()->where('is_active', true)->get())
                ->where('is_active', true);

            $sellableVariants = $variants->filter(fn (ProductVariant $variant) => $variant->availableStock() > 0);
            $pool = $sellableVariants->isNotEmpty() ? $sellableVariants : $variants;

            if ($pool->isNotEmpty()) {
                $lowestVariant = $pool->sortBy(fn (ProductVariant $variant) => $variant->effectivePrice($this, $now))->first();
                $effectivePrices = $pool->map(fn (ProductVariant $variant) => $variant->effectivePrice($this, $now))->unique();

                $effectivePrice = $lowestVariant->effectivePrice($this, $now);
                $regularPrice = (float) $lowestVariant->price;

                return [
                    'effective_price' => $effectivePrice,
                    'regular_price' => $regularPrice,
                    'is_promo' => $lowestVariant->hasActivePromo($this, $now),
                    'starts_from' => $effectivePrices->count() > 1,
                ];
            }
        }

        $effectivePrice = $this->effectivePrice($now);
        $regularPrice = (float) $this->price;

        return [
            'effective_price' => $effectivePrice,
            'regular_price' => $regularPrice,
            'is_promo' => $this->hasActivePromo($now),
            'starts_from' => false,
        ];
    }
}
