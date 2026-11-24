<?php

namespace App\Models;

use App\Services\Storefront\HomepageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'guest_token',
        'checkout_idempotency_key',
        'customer_id',
        'customer_name_snapshot',
        'customer_email_snapshot',
        'customer_phone_snapshot',
        'is_guest',
        'order_status',
        'payment_status',
        'shipment_status',
        'subtotal',
        'discount_amount',
        'coupon_code_snapshot',
        'shipping_cost',
        'tax_amount',
        'payment_fee',
        'grand_total',
        'total_weight_grams',
        'customer_notes',
        'admin_internal_notes',
        'payment_due_at',
        'paid_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => HomepageService::clearCache());
        static::deleted(fn () => HomepageService::clearCache());
    }

    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'payment_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_weight_grams' => 'integer',
            'payment_due_at' => 'datetime',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
