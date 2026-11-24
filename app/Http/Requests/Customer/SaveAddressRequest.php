<?php

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class SaveAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->status === 'active';
    }

    protected function prepareForValidation(): void
    {
        $nullableFields = [
            'district_id',
            'district_name',
            'subdistrict_id',
            'subdistrict_name',
        ];

        $normalized = [
            'label' => $this->trimString($this->input('label')),
            'recipient_name' => $this->trimString($this->input('recipient_name')),
            'recipient_phone' => $this->normalizePhone($this->input('recipient_phone')),
            'address_line' => $this->trimString($this->input('address_line')),
            'province_id' => $this->trimString($this->input('province_id')),
            'province_name' => $this->trimString($this->input('province_name')),
            'city_id' => $this->trimString($this->input('city_id')),
            'city_name' => $this->trimString($this->input('city_name')),
            'postal_code' => $this->normalizePostalCode($this->input('postal_code')),
            'is_primary' => $this->boolean('is_primary'),
        ];

        foreach ($nullableFields as $field) {
            $value = $this->trimString($this->input($field));
            $normalized[$field] = $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['required', 'string', 'regex:/^(?:0[0-9]{8,15}|62[0-9]{7,14})$/'],
            'address_line' => ['required', 'string', 'max:2000'],
            'province_id' => ['required', 'string', 'max:30'],
            'province_name' => ['required', 'string', 'max:100'],
            'city_id' => ['required', 'string', 'max:30'],
            'city_name' => ['required', 'string', 'max:120'],
            'district_id' => ['nullable', 'string', 'max:30'],
            'district_name' => ['nullable', 'string', 'max:120'],
            'subdistrict_id' => ['nullable', 'string', 'max:30'],
            'subdistrict_name' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Label alamat wajib diisi (misalnya Rumah atau Kantor).',
            'label.max' => 'Label alamat maksimal 60 karakter.',
            'recipient_name.required' => 'Nama penerima wajib diisi.',
            'recipient_name.max' => 'Nama penerima maksimal 255 karakter.',
            'recipient_phone.required' => 'Nomor telepon penerima wajib diisi.',
            'recipient_phone.regex' => 'Nomor telepon harus berupa nomor Indonesia valid (awalan 0 atau 62, total 9–16 digit).',
            'address_line.required' => 'Alamat lengkap wajib diisi.',
            'province_id.required' => 'Provinsi wajib dipilih.',
            'province_name.required' => 'Nama provinsi wajib diisi.',
            'city_id.required' => 'Kota/kabupaten wajib dipilih.',
            'city_name.required' => 'Nama kota/kabupaten wajib diisi.',
            'postal_code.required' => 'Kode pos wajib diisi.',
            'postal_code.regex' => 'Kode pos harus terdiri dari tepat 5 digit angka.',
        ];
    }

    private function trimString(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function normalizePhone(mixed $value): string
    {
        $raw = $this->trimString($value);
        $cleaned = preg_replace('/[\s\-\(\)\.]+/', '', $raw) ?? '';

        if (str_starts_with($cleaned, '+')) {
            $cleaned = substr($cleaned, 1);
        }

        return $cleaned;
    }

    private function normalizePostalCode(mixed $value): string
    {
        $raw = $this->trimString($value);

        return preg_replace('/[\s\-]+/', '', $raw) ?? '';
    }
}
