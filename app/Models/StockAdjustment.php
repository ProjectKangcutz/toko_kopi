<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'adjustment_number',
        'product_id',
        'product_variant_id',
        'reason',
        'quantity_change',
        'notes',
        'adjusted_by',
        'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'adjusted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
