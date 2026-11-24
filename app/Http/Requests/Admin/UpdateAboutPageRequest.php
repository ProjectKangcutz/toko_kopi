<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAboutPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('content.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_published' => $this->boolean('is_published'),
            'remove_banner_image' => $this->boolean('remove_banner_image'),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'banner_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_banner_image' => ['required', 'boolean'],
            'vision' => ['nullable', 'string', 'max:2000'],
            'mission' => ['nullable', 'string', 'max:4000'],
            'business_profile' => ['nullable', 'string', 'max:4000'],
            'is_published' => ['required', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul halaman Tentang Kami wajib diisi.',
            'content.required' => 'Konten utama Tentang Kami wajib diisi.',
            'banner_image.image' => 'Banner halaman harus berupa file gambar yang valid.',
            'banner_image.mimes' => 'Format gambar banner harus JPG, JPEG, PNG, atau WEBP.',
            'banner_image.max' => 'Ukuran gambar banner maksimal 2 MB.',
        ];
    }
}
