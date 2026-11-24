<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        if (! $admin) {
            return false;
        }

        $category = $this->route('category');

        return $category instanceof Category
            ? $admin->hasPermission('categories.update')
            : $admin->hasPermission('categories.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'remove_image' => $this->boolean('remove_image'),
            'parent_id' => $this->filled('parent_id') ? (int) $this->input('parent_id') : null,
            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,
        ]);
    }

    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');
        $ignoreId = $category?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($ignoreId),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
                function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId): void {
                    if ($ignoreId !== null && (int) $value === (int) $ignoreId) {
                        $fail('Kategori tidak dapat menjadi induk bagi dirinya sendiri.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:99999'],
            'is_active' => ['required', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'slug.regex' => 'Slug hanya boleh berisi huruf kecil, angka, dan tanda hubung (-).',
            'slug.unique' => 'Slug kategori sudah digunakan.',
            'image.image' => 'File gambar kategori tidak valid.',
            'image.mimes' => 'Format gambar kategori harus JPG, JPEG, PNG, atau WEBP.',
            'image.max' => 'Ukuran gambar kategori maksimal 2 MB.',
        ];
    }
}
