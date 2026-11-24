<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        if (! $admin) {
            return false;
        }

        $product = $this->route('product');

        return $product instanceof Product
            ? $admin->hasPermission('products.update')
            : $admin->hasPermission('products.create');
    }

    protected function prepareForValidation(): void
    {
        $variants = collect($this->input('variants', []))
            ->filter(fn ($row) => is_array($row) && (trim((string) ($row['sku'] ?? '')) !== '' || trim((string) ($row['name'] ?? '')) !== ''))
            ->map(function (array $row): array {
                return [
                    'sku' => strtoupper(trim((string) ($row['sku'] ?? ''))),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'cost_price' => $row['cost_price'] ?? 0,
                    'price' => $row['price'] ?? null,
                    'promo_price' => isset($row['promo_price']) && $row['promo_price'] !== '' ? $row['promo_price'] : null,
                    'weight_grams' => $row['weight_grams'] ?? $this->input('weight_grams', 100),
                    'initial_stock' => $row['initial_stock'] ?? 0,
                    'min_stock' => $row['min_stock'] ?? 5,
                    'is_active' => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ];
            })
            ->values()
            ->all();

        $this->merge([
            'sku' => strtoupper(trim((string) $this->input('sku', ''))),
            'has_variants' => $this->boolean('has_variants'),
            'is_active' => $this->boolean('is_active'),
            'is_featured' => $this->boolean('is_featured'),
            'promo_price' => $this->filled('promo_price') ? $this->input('promo_price') : null,
            'promo_start_at' => $this->filled('promo_start_at') ? $this->input('promo_start_at') : null,
            'promo_end_at' => $this->filled('promo_end_at') ? $this->input('promo_end_at') : null,
            'cost_price' => $this->filled('cost_price') ? $this->input('cost_price') : 0,
            'variants' => $variants,
        ]);
    }

    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');
        $ignoreId = $product?->id;
        $isCreate = $product === null;

        $rules = [
            'sku' => [
                'required',
                'string',
                'max:80',
                Rule::unique('products', 'sku')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:220',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($ignoreId),
            ],
            'primary_category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:20000'],
            'specifications' => ['nullable', 'string', 'max:10000'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'price' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'promo_price' => ['nullable', 'numeric', 'min:1', 'lt:price'],
            'promo_start_at' => ['nullable', 'date'],
            'promo_end_at' => ['nullable', 'date', 'after_or_equal:promo_start_at'],
            'weight_grams' => ['required', 'integer', 'min:1', 'max:1000000'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'has_variants' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
        ];

        if ($isCreate) {
            $rules['initial_stock'] = ['nullable', 'integer', 'min:0', 'max:100000'];
            $rules['images'] = ['nullable', 'array', 'max:10'];
            $rules['images.*'] = ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
            $rules['variants'] = ['nullable', 'array', 'max:50'];
            $rules['variants.*.sku'] = ['required', 'string', 'max:80', 'distinct:strict', Rule::unique('product_variants', 'sku')];
            $rules['variants.*.name'] = ['required', 'string', 'max:150'];
            $rules['variants.*.cost_price'] = ['nullable', 'numeric', 'min:0', 'max:999999999'];
            $rules['variants.*.price'] = ['required', 'numeric', 'min:1', 'max:999999999'];
            $rules['variants.*.promo_price'] = ['nullable', 'numeric', 'min:1'];
            $rules['variants.*.weight_grams'] = ['required', 'integer', 'min:1', 'max:1000000'];
            $rules['variants.*.initial_stock'] = ['required', 'integer', 'min:0', 'max:100000'];
            $rules['variants.*.min_stock'] = ['required', 'integer', 'min:0', 'max:100000'];
            $rules['variants.*.is_active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->route('product') !== null) {
                    return;
                }

                $variants = $this->input('variants', []);
                foreach ($variants as $index => $variant) {
                    if (
                        isset($variant['promo_price'], $variant['price'])
                        && $variant['promo_price'] !== null
                        && (float) $variant['promo_price'] >= (float) $variant['price']
                    ) {
                        $validator->errors()->add(
                            "variants.{$index}.promo_price",
                            'Harga promo varian harus lebih kecil dari harga normal varian.'
                        );
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required' => 'SKU produk wajib diisi.',
            'sku.unique' => 'SKU produk sudah digunakan.',
            'name.required' => 'Nama produk wajib diisi.',
            'slug.regex' => 'Slug produk hanya boleh berisi huruf kecil, angka, dan tanda hubung (-).',
            'slug.unique' => 'Slug produk sudah digunakan.',
            'primary_category_id.required' => 'Kategori utama wajib dipilih.',
            'description.required' => 'Deskripsi produk wajib diisi.',
            'price.required' => 'Harga jual wajib diisi.',
            'price.min' => 'Harga jual minimal Rp 1.',
            'promo_price.lt' => 'Harga promo harus lebih kecil dari harga normal.',
            'promo_end_at.after_or_equal' => 'Tanggal berakhir promo tidak boleh lebih awal dari tanggal mulai.',
            'weight_grams.required' => 'Berat produk (gram) wajib diisi.',
            'weight_grams.min' => 'Berat produk minimal 1 gram.',
            'images.*.mimes' => 'Format gambar produk harus JPG, JPEG, PNG, atau WEBP.',
            'images.*.max' => 'Ukuran setiap gambar produk maksimal 2 MB.',
            'variants.*.sku.unique' => 'SKU varian sudah digunakan oleh varian lain.',
            'variants.*.sku.distinct' => 'SKU antar varian dalam satu produk tidak boleh sama.',
        ];
    }
}
