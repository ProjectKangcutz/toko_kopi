<?php

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ShippingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->status === 'active';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'address_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('customer_addresses', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address_id.required' => 'Alamat pengiriman wajib dipilih.',
            'address_id.integer' => 'ID alamat pengiriman harus berupa angka bulat yang valid.',
            'address_id.min' => 'ID alamat pengiriman tidak valid.',
            'address_id.exists' => 'Alamat pengiriman tidak ditemukan atau sudah dihapus.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'address_id' => 'alamat pengiriman',
        ];
    }
}
