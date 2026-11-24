<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('products.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => strtoupper(trim((string) $this->input('sku', ''))),
            'is_active' => $this->boolean('is_active'),
            'promo_price' => $this->filled('promo_price') ? $this->input('promo_price') : null,
            'cost_price' => $this->filled('cost_price') ? $this->input('cost_price') : null,
        ]);
    }

    public function rules(): array
    {
        /** @var ProductVariant|null $variant */
        $variant = $this->route('variant');
        $ignoreId = $variant?->id;

        $rules = [
            'sku' => [
                'required',
                'string',
                'max:80',
                Rule::unique('product_variants', 'sku')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'price' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'promo_price' => ['nullable', 'numeric', 'min:1', 'lt:price'],
            'weight_grams' => ['required', 'integer', 'min:1', 'max:1000000'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($variant === null) {
            $rules['initial_stock'] = ['required', 'integer', 'min:0', 'max:100000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'SKU varian sudah digunakan.',
            'promo_price.lt' => 'Harga promo varian harus lebih kecil dari harga normal.',
        ];
    }
}
