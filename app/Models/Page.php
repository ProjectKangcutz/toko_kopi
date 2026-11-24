<?php

namespace App\Models;

use App\Services\Storefront\StorefrontContentService;
use App\Services\Storefront\StorefrontLayoutService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Page extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'banner_image_path',
        'template',
        'is_published',
        'show_in_footer',
        'sort_order',
        'seo_title',
        'seo_description',
        'canonical_url',
        'robots_meta',
        'updated_by',
    ];

    protected static function booted(): void
    {
        $flush = static function (self $page): void {
            StorefrontContentService::clearCache();
            StorefrontContentService::clearPageCache($page->slug);

            $originalSlug = $page->getOriginal('slug');
            if (is_string($originalSlug) && $originalSlug !== '' && $originalSlug !== $page->slug) {
                StorefrontContentService::clearPageCache($originalSlug);
            }

            Cache::forget(StorefrontLayoutService::NAV_CACHE_KEY);

            try {
                if (app()->bound('request')) {
                    request()->attributes->remove(StorefrontLayoutService::REQUEST_NAV_DATA_ATTR);
                }
            } catch (\Throwable) {
                // Abaikan pada konteks non-request.
            }
        };

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'show_in_footer' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isBuiltInPolicy(): bool
    {
        return in_array((string) $this->slug, StorefrontContentService::POLICY_SLUGS, true);
    }

    public function isSystemSpecialPage(): bool
    {
        return in_array((string) $this->slug, StorefrontContentService::SYSTEM_SPECIAL_SLUGS, true);
    }

    public function isCustomPage(): bool
    {
        return ! $this->isBuiltInPolicy() && ! $this->isSystemSpecialPage();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
