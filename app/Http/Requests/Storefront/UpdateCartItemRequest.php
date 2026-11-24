<?php

namespace App\Http\Requests\Storefront;

use App\Models\CartItem;
use App\Models\Customer;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            return true;
        }

        if ($customer->status !== 'active') {
            return false;
        }

        $routeItem = $this->route('item') ?? $this->route('cartItem');

        if ($routeItem instanceof CartItem) {
            return $customer->can('update', $routeItem);
        }

        if (is_numeric($routeItem)) {
            $cartItem = CartItem::query()->with('cart')->find((int) $routeItem);
            if ($cartItem !== null) {
                return $customer->can('update', $cartItem);
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_ITEM_QUANTITY],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'Kuantitas pembelian wajib diisi.',
            'quantity.integer' => 'Kuantitas harus berupa angka bulat.',
            'quantity.min' => 'Kuantitas minimal 1 unit.',
            'quantity.max' => 'Kuantitas melebihi batas maksimal pembelian per item.',
        ];
    }
}
