<?php

namespace App\Http\Requests\Storefront;

use App\Models\Customer;
use App\Services\Storefront\CartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = Auth::guard('customer')->user();

        if ($customer instanceof Customer && $customer->status !== 'active') {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('product_variant_id') === '') {
            $this->merge(['product_variant_id' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_ITEM_QUANTITY],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Produk wajib dipilih.',
            'product_id.integer' => 'ID produk tidak valid.',
            'product_id.min' => 'ID produk tidak valid.',
            'product_variant_id.integer' => 'Varian produk tidak valid.',
            'product_variant_id.min' => 'Varian produk tidak valid.',
            'quantity.required' => 'Kuantitas pembelian wajib diisi.',
            'quantity.integer' => 'Kuantitas harus berupa angka bulat.',
            'quantity.min' => 'Kuantitas minimal 1 unit.',
            'quantity.max' => 'Kuantitas melebihi batas maksimal pembelian per item.',
        ];
    }
}
