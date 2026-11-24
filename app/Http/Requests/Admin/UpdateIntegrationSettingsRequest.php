<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationSettingsRequest extends FormRequest
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
            'rajaongkir_is_active' => $this->boolean('rajaongkir_is_active'),
            'clear_rajaongkir_db_credentials' => $this->boolean('clear_rajaongkir_db_credentials'),
            'midtrans_merchant_id' => $this->filled('midtrans_merchant_id') ? trim((string) $this->input('midtrans_merchant_id')) : '',
            'midtrans_client_key' => $this->filled('midtrans_client_key') ? trim((string) $this->input('midtrans_client_key')) : '',
            'midtrans_server_key' => $this->filled('midtrans_server_key') ? trim((string) $this->input('midtrans_server_key')) : '',
            'rajaongkir_base_url' => $this->filled('rajaongkir_base_url') ? rtrim(trim((string) $this->input('rajaongkir_base_url')), '/') : '',
            'rajaongkir_origin_id' => $this->filled('rajaongkir_origin_id') ? trim((string) $this->input('rajaongkir_origin_id')) : '',
            'rajaongkir_api_key' => $this->filled('rajaongkir_api_key') ? trim((string) $this->input('rajaongkir_api_key')) : '',
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
            'rajaongkir_is_active' => ['required', 'boolean'],
            'rajaongkir_base_url' => [
                'nullable',
                'url',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '') {
                        $scheme = strtolower((string) parse_url((string) $value, PHP_URL_SCHEME));
                        if (! in_array($scheme, ['http', 'https'], true)) {
                            $fail('URL base RajaOngkir harus menggunakan protokol http atau https.');
                        }
                    }
                },
            ],
            'rajaongkir_origin_id' => ['nullable', 'string', 'regex:/^(?!0+$)[0-9]{1,30}$/'],
            'rajaongkir_timeout' => ['required', 'integer', 'min:2', 'max:30'],
            'rajaongkir_api_key' => ['nullable', 'string', 'max:255'],
            'clear_rajaongkir_db_credentials' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'rajaongkir_base_url.url' => 'Format URL API RajaOngkir tidak valid.',
            'rajaongkir_origin_id.regex' => 'Origin ID RajaOngkir harus berupa angka positif.',
            'rajaongkir_timeout.min' => 'Timeout RajaOngkir minimal 2 detik.',
            'rajaongkir_timeout.max' => 'Timeout RajaOngkir maksimal 30 detik.',
        ];
    }
}
