<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('products.update');
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'Pilih minimal satu gambar produk untuk diunggah.',
            'images.*.image' => 'File yang diunggah harus berupa gambar.',
            'images.*.mimes' => 'Format gambar produk harus JPG, JPEG, PNG, atau WEBP.',
            'images.*.max' => 'Ukuran setiap gambar maksimal 2 MB.',
        ];
    }
}
