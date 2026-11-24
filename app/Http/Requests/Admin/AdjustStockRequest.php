<?php

namespace App\Http\Requests\Admin;

use App\Services\Inventory\InventoryAdjustmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('inventory.adjust');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_variant_id' => $this->filled('product_variant_id') ? (int) $this->input('product_variant_id') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', Rule::in(array_keys(InventoryAdjustmentService::REASONS))],
            'quantity_change' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'notes' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan penyesuaian stok wajib dipilih.',
            'reason.in' => 'Alasan penyesuaian stok tidak valid.',
            'quantity_change.required' => 'Jumlah penyesuaian stok wajib diisi.',
            'quantity_change.not_in' => 'Jumlah penyesuaian stok tidak boleh 0.',
            'notes.required' => 'Catatan keterangan penyesuaian stok wajib diisi.',
            'notes.min' => 'Catatan keterangan penyesuaian minimal 3 karakter.',
        ];
    }
}
