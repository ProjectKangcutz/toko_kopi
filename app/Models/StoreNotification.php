<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreNotification extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'notifications';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'recipient_type',
        'recipient_id',
        'type',
        'title',
        'message',
        'action_url',
        'data',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
