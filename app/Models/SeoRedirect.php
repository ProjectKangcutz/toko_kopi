<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoRedirect extends Model
{
    protected $fillable = [
        'source_path',
        'target_path',
        'status_code',
        'hit_count',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hit_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
