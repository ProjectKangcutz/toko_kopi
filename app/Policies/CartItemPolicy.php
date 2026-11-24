<?php

namespace App\Policies;

use App\Models\CartItem;
use App\Models\Customer;

class CartItemPolicy
{
    public function view(Customer $customer, CartItem $cartItem): bool
    {
        return $this->owns($customer, $cartItem);
    }

    public function update(Customer $customer, CartItem $cartItem): bool
    {
        return $this->owns($customer, $cartItem);
    }

    public function delete(Customer $customer, CartItem $cartItem): bool
    {
        return $this->owns($customer, $cartItem);
    }

    protected function owns(Customer $customer, CartItem $cartItem): bool
    {
        $cart = $cartItem->relationLoaded('cart')
            ? $cartItem->cart
            : $cartItem->cart()->first();

        return $cart !== null
            && $cart->customer_id !== null
            && (int) $cart->customer_id === (int) $customer->id;
    }
}
