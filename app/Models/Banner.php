<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'placement',
        'image_desktop_path',
        'image_mobile_path',
        'alt_text',
        'cta_label',
        'cta_url',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => HomepageService::clearCache());
        static::deleted(fn () => HomepageService::clearCache());
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActiveWithinWindow(Builder $query, string $placement, ?CarbonInterface $at = null): Builder
    {
        $now = $at ?? now();

        return $query
            ->where('placement', $placement)
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public function isWithinSchedule(?CarbonInterface $at = null): bool
    {
        $now = $at ?? now();
        $afterStart = ! $this->starts_at || $now->greaterThanOrEqualTo($this->starts_at);
        $beforeEnd = ! $this->ends_at || $now->lessThanOrEqualTo($this->ends_at);

        return $afterStart && $beforeEnd;
    }
}
