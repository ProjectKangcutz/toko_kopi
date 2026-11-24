<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMidtransSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('settings.integrations');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'midtrans_is_active' => $this->boolean('midtrans_is_active'),
            'midtrans_is_production' => $this->boolean('midtrans_is_production'),
            'clear_midtrans_db_credentials' => $this->boolean('clear_midtrans_db_credentials'),
            'midtrans_merchant_id' => $this->filled('midtrans_merchant_id') ? trim((string) $this->input('midtrans_merchant_id')) : '',
            'midtrans_client_key' => $this->filled('midtrans_client_key') ? trim((string) $this->input('midtrans_client_key')) : '',
            'midtrans_server_key' => $this->filled('midtrans_server_key') ? trim((string) $this->input('midtrans_server_key')) : '',
        ]);
    }

    public function rules(): array
    {
        return [
            'midtrans_is_active' => ['required', 'boolean'],
            'midtrans_is_production' => ['required', 'boolean'],
            'midtrans_merchant_id' => ['nullable', 'string', 'max:120'],
            'midtrans_client_key' => ['nullable', 'string', 'max:255'],
            'midtrans_server_key' => ['nullable', 'string', 'max:255'],
            'clear_midtrans_db_credentials' => ['required', 'boolean'],
        ];
    }
}
