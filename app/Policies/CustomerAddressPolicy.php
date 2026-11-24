<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\CustomerAddress;

class CustomerAddressPolicy
{
    public function viewAny(Customer $customer): bool
    {
        return $customer->status !== 'suspended';
    }

    public function view(Customer $customer, CustomerAddress $address): bool
    {
        return $customer->status !== 'suspended'
            && (int) $address->customer_id === (int) $customer->id;
    }

    public function create(Customer $customer): bool
    {
        return $customer->status !== 'suspended';
    }

    public function update(Customer $customer, CustomerAddress $address): bool
    {
        return $customer->status !== 'suspended'
            && (int) $address->customer_id === (int) $customer->id;
    }

    public function delete(Customer $customer, CustomerAddress $address): bool
    {
        return $customer->status !== 'suspended'
            && (int) $address->customer_id === (int) $customer->id;
    }

    public function setPrimary(Customer $customer, CustomerAddress $address): bool
    {
        return $customer->status !== 'suspended'
            && (int) $address->customer_id === (int) $customer->id
            && ! $address->trashed();
    }
}
