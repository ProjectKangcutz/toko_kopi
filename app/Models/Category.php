<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image_path',
        'banner_path',
        'sort_order',
        'is_active',
        'seo_title',
        'seo_description',
        'canonical_url',
    ];

    protected static function booted(): void
    {
        $clearCaches = static function (): void {
            Cache::forget(StorefrontLayoutService::NAV_CACHE_KEY);
            HomepageService::clearCache();
        };

        static::saved($clearCaches);
        static::deleted($clearCaches);
        static::restored($clearCaches);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}
