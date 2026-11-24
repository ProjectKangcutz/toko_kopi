<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends Model
{
    protected $fillable = [
        'order_id',
        'recipient_name',
        'recipient_phone',
        'address_line',
        'province_id',
        'province_name',
        'city_id',
        'city_name',
        'district_id',
        'district_name',
        'subdistrict_id',
        'subdistrict_name',
        'postal_code',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
