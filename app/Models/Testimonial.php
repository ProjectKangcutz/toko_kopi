<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'customer_name',
        'customer_role_or_city',
        'rating',
        'quote',
        'avatar_path',
        'is_featured',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => HomepageService::clearCache());
        static::deleted(fn () => HomepageService::clearCache());
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
