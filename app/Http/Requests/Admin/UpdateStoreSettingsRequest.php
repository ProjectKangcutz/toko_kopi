<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('settings.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'maintenance_mode' => $this->boolean('maintenance_mode'),
            'guest_checkout_enabled' => $this->boolean('guest_checkout_enabled'),
            'customer_registration_enabled' => $this->boolean('customer_registration_enabled'),
            'auto_cancel_expired' => $this->boolean('auto_cancel_expired'),
            'stock_reservation_enabled' => $this->boolean('stock_reservation_enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:150'],
            'store_tagline' => ['nullable', 'string', 'max:255'],
            'store_description' => ['nullable', 'string', 'max:1000'],
            'store_address' => ['nullable', 'string', 'max:500'],
            'store_phone' => ['nullable', 'string', 'max:40'],
            'store_email' => ['nullable', 'email', 'max:150'],
            'store_whatsapp' => ['nullable', 'string', 'max:40'],
            'store_hours' => ['nullable', 'string', 'max:120'],
            'warehouse_address' => ['nullable', 'string', 'max:500'],
            'origin_city_name' => ['nullable', 'string', 'max:150'],
            'min_weight_grams' => ['required', 'integer', 'min:1', 'max:100000'],
            'weight_rounding_grams' => ['required', 'integer', 'min:1', 'max:100000'],
            'min_order_amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'tax_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'store_status' => ['required', Rule::in(['open', 'closed'])],
            'maintenance_mode' => ['required', 'boolean'],
            'guest_checkout_enabled' => ['required', 'boolean'],
            'customer_registration_enabled' => ['required', 'boolean'],
            'payment_expiry_minutes' => ['required', 'integer', 'min:15', 'max:10080'],
            'auto_cancel_expired' => ['required', 'boolean'],
            'stock_reservation_enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_name.required' => 'Nama toko wajib diisi.',
            'payment_expiry_minutes.min' => 'Batas waktu pembayaran minimal 15 menit.',
            'payment_expiry_minutes.max' => 'Batas waktu pembayaran maksimal 7 hari (10.080 menit).',
        ];
    }
}
