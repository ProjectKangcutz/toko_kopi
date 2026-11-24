<?php

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->status === 'active';
    }

    protected function prepareForValidation(): void
    {
        $rawKey = $this->input('idempotency_key') ?? $this->header('Idempotency-Key');

        $this->merge([
            'courier_code' => is_string($this->input('courier_code'))
                ? strtolower(trim($this->input('courier_code')))
                : $this->input('courier_code'),
            'service_code' => is_string($this->input('service_code'))
                ? strtoupper(trim($this->input('service_code')))
                : $this->input('service_code'),
            'idempotency_key' => is_string($rawKey) ? trim($rawKey) : $rawKey,
            'customer_notes' => is_string($this->input('customer_notes'))
                ? trim($this->input('customer_notes'))
                : $this->input('customer_notes'),
        ]);
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
            'courier_code' => [
                'required',
                'string',
                'max:30',
            ],
            'service_code' => [
                'required',
                'string',
                'max:40',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'min:16',
                'max:64',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],
            'customer_notes' => [
                'nullable',
                'string',
                'max:500',
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
            'address_id.integer' => 'ID alamat pengiriman tidak valid.',
            'address_id.min' => 'ID alamat pengiriman tidak valid.',
            'address_id.exists' => 'Alamat pengiriman tidak ditemukan atau sudah dihapus.',
            'courier_code.required' => 'Kurir pengiriman wajib dipilih.',
            'courier_code.max' => 'Kode kurir pengiriman terlalu panjang.',
            'service_code.required' => 'Layanan pengiriman wajib dipilih.',
            'service_code.max' => 'Kode layanan pengiriman terlalu panjang.',
            'idempotency_key.required' => 'Kunci transaksi tidak ditemukan. Silakan muat ulang halaman checkout.',
            'idempotency_key.min' => 'Format kunci transaksi tidak valid.',
            'idempotency_key.max' => 'Format kunci transaksi terlalu panjang.',
            'idempotency_key.regex' => 'Format kunci transaksi mengandung karakter yang tidak diizinkan.',
            'customer_notes.max' => 'Catatan pesanan maksimal 500 karakter.',
        ];
    }
}
