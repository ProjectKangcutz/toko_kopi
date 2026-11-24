<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactPageRequest extends FormRequest
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
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:20000'],
            'is_published' => ['required', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'store_address' => ['nullable', 'string', 'max:500'],
            'store_whatsapp' => ['nullable', 'string', 'max:40'],
            'store_phone' => ['nullable', 'string', 'max:40'],
            'store_email' => ['nullable', 'email', 'max:150'],
            'store_hours' => ['nullable', 'string', 'max:120'],
            'social_instagram' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'social_facebook' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'social_tiktok' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'social_youtube' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'contact_additional_info' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul halaman Kontak wajib diisi.',
            'store_email.email' => 'Format email layanan pelanggan tidak valid.',
            'social_instagram.url' => 'Tautan Instagram harus diawali http:// atau https://.',
            'social_facebook.url' => 'Tautan Facebook harus diawali http:// atau https://.',
            'social_tiktok.url' => 'Tautan TikTok harus diawali http:// atau https://.',
            'social_youtube.url' => 'Tautan YouTube harus diawali http:// atau https://.',
        ];
    }
}
