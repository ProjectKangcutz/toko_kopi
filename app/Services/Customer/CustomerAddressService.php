<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CustomerAddressService
{
    /**
     * Whitelist atribut alamat dari request agar customer_id dan kolom sistem
     * tidak pernah dapat disuntikkan melalui input pengguna.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ATTRIBUTES = [
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
    ];

    /**
     * @return Collection<int, CustomerAddress>
     */
    public function listAddresses(Customer $customer): Collection
    {
        return $customer->addresses()
            ->active()
            ->orderedForCustomer()
            ->get();
    }

    public function create(Customer $customer, array $data): CustomerAddress
    {
        $attributes = Arr::only($data, self::ALLOWED_ATTRIBUTES);
        $requestedPrimary = (bool) ($data['is_primary'] ?? false);

        return DB::transaction(function () use ($customer, $attributes, $requestedPrimary): CustomerAddress {
            $lockedCustomer = $this->lockCustomer($customer);
            $hasExistingActive = $lockedCustomer->addresses()->active()->exists();
            $shouldBePrimary = ! $hasExistingActive || $requestedPrimary;

            if ($shouldBePrimary) {
                $lockedCustomer->addresses()
                    ->active()
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            /** @var CustomerAddress $address */
            $address = $lockedCustomer->addresses()->create(array_merge($attributes, [
                'is_primary' => $shouldBePrimary,
            ]));

            return $address->fresh();
        });
    }

    public function update(Customer $customer, CustomerAddress $address, array $data): CustomerAddress
    {
        $attributes = Arr::only($data, self::ALLOWED_ATTRIBUTES);

        return DB::transaction(function () use ($customer, $address, $attributes, $data): CustomerAddress {
            $lockedCustomer = $this->lockCustomer($customer);
            $lockedAddress = $this->lockOwnedAddress($lockedCustomer, $address);

            $wantsPrimary = array_key_exists('is_primary', $data)
                ? (bool) $data['is_primary']
                : (bool) $lockedAddress->is_primary;

            if ($wantsPrimary) {
                $lockedCustomer->addresses()
                    ->active()
                    ->where('id', '!=', $lockedAddress->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);

                $finalPrimary = true;
            } else {
                $hasOtherPrimary = $lockedCustomer->addresses()
                    ->active()
                    ->where('id', '!=', $lockedAddress->id)
                    ->where('is_primary', true)
                    ->exists();

                // Pertahankan alamat utama bila belum ada alamat utama pengganti lain.
                $finalPrimary = ! $hasOtherPrimary;
            }

            $lockedAddress->update(array_merge($attributes, [
                'is_primary' => $finalPrimary,
            ]));

            return $lockedAddress->fresh();
        });
    }

    public function setPrimary(Customer $customer, CustomerAddress $address): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $address): CustomerAddress {
            $lockedCustomer = $this->lockCustomer($customer);
            $lockedAddress = $this->lockOwnedAddress($lockedCustomer, $address);

            $lockedCustomer->addresses()
                ->active()
                ->where('id', '!=', $lockedAddress->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            if (! $lockedAddress->is_primary) {
                $lockedAddress->update(['is_primary' => true]);
            }

            return $lockedAddress->fresh();
        });
    }

    /**
     * Menghapus alamat (SoftDeletes) dan mempromosikan alamat aktif terbaru
     * apabila alamat utama dihapus. Mengembalikan alamat pengganti bila ada.
     */
    public function delete(Customer $customer, CustomerAddress $address): ?CustomerAddress
    {
        return DB::transaction(function () use ($customer, $address): ?CustomerAddress {
            $lockedCustomer = $this->lockCustomer($customer);
            $lockedAddress = $this->lockOwnedAddress($lockedCustomer, $address);

            $wasPrimary = (bool) $lockedAddress->is_primary;

            if ($wasPrimary) {
                $lockedAddress->update(['is_primary' => false]);
            }

            $lockedAddress->delete();

            if (! $wasPrimary) {
                return null;
            }

            $replacement = $lockedCustomer->addresses()
                ->active()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $replacement) {
                return null;
            }

            $replacement->update(['is_primary' => true]);

            return $replacement->fresh();
        });
    }

    private function lockCustomer(Customer $customer): Customer
    {
        return Customer::query()
            ->whereKey($customer->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockOwnedAddress(Customer $lockedCustomer, CustomerAddress $address): CustomerAddress
    {
        return $lockedCustomer->addresses()
            ->active()
            ->whereKey($address->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
