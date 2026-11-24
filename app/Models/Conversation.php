<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'ticket_number',
        'customer_id',
        'order_id',
        'subject',
        'status',
        'unread_by_customer',
        'unread_by_admin',
        'last_message_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'unread_by_customer' => 'integer',
            'unread_by_admin' => 'integer',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ConversationAssignment::class);
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(ConversationAssignment::class)->whereNull('unassigned_at')->latestOfMany('assigned_at');
    }
}
