<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected $fillable = [
        'customer_id',
        'gender',
        'birth_date',
        'avatar_path',
        'preferred_locale',
        'marketing_opt_in',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'marketing_opt_in' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
