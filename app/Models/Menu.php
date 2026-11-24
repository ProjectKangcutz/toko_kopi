<?php

namespace App\Models;

use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Menu extends Model
{
    protected $fillable = [
        'location',
        'parent_id',
        'label',
        'url',
        'target',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(StorefrontLayoutService::NAV_CACHE_KEY));
        static::deleted(fn () => Cache::forget(StorefrontLayoutService::NAV_CACHE_KEY));
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order');
    }
}
