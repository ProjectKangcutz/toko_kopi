<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRateLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider',
        'origin_id',
        'destination_id',
        'weight_grams',
        'courier_codes',
        'http_status',
        'is_success',
        'error_message',
        'request_payload',
        'response_payload',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'weight_grams' => 'integer',
            'http_status' => 'integer',
            'is_success' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
