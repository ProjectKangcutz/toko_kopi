<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerAddress extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id',
        'label',
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
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeOwnedBy(Builder $query, Customer|int $customer): Builder
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        return $query->where('customer_id', $customerId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($this->getQualifiedDeletedAtColumn());
    }

    public function scopeOrderedForCustomer(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function formattedRegion(): string
    {
        $parts = array_filter([
            $this->subdistrict_name,
            $this->district_name,
            $this->city_name,
            $this->province_name,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        $region = implode(', ', $parts);

        return $this->postal_code ? trim($region.' '.$this->postal_code) : $region;
    }
}
