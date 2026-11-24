<?php

namespace App\Models;

use App\Services\Storefront\StorefrontContentService;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $fillable = [
        'category',
        'question',
        'answer',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => StorefrontContentService::clearCache());
        static::deleted(fn () => StorefrontContentService::clearCache());
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
