<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image_path',
        'featured_image_alt',
        'author_id',
        'status',
        'published_at',
        'seo_title',
        'seo_description',
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
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $now = $at ?? now();

        return $query
            ->where('status', 'published')
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', $now);
            });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
