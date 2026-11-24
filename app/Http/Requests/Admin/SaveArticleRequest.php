<?php

namespace App\Http\Requests\Admin;

use App\Models\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('content.manage');
    }

    protected function prepareForValidation(): void
    {
        $rawSlug = trim((string) $this->input('slug', ''));

        $this->merge([
            'slug' => $rawSlug !== '' ? strtolower($rawSlug) : null,
            'remove_featured_image' => $this->boolean('remove_featured_image'),
        ]);
    }

    public function rules(): array
    {
        /** @var Article|int|string|null $routeArticle */
        $routeArticle = $this->route('article');
        $articleId = $routeArticle instanceof Article ? $routeArticle->id : (is_numeric($routeArticle) ? (int) $routeArticle : null);

        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:190',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('articles', 'slug')->ignore($articleId),
            ],
            'excerpt' => ['nullable', 'string', 'max:600'],
            'content' => ['required', 'string', 'max:100000'],
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_featured_image' => ['required', 'boolean'],
            'featured_image_alt' => ['nullable', 'string', 'max:200'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul artikel wajib diisi.',
            'slug.regex' => 'Slug hanya boleh berisi huruf kecil, angka, dan tanda hubung (-).',
            'slug.unique' => 'Slug artikel sudah digunakan. Silakan gunakan slug lain.',
            'content.required' => 'Isi artikel wajib diisi.',
            'featured_image.image' => 'Gambar utama harus berupa file gambar yang valid.',
            'featured_image.mimes' => 'Format gambar utama harus JPG, JPEG, PNG, atau WEBP.',
            'featured_image.max' => 'Ukuran gambar utama maksimal 2 MB.',
            'canonical_url.url' => 'URL kanonikal harus menggunakan format http:// atau https:// yang valid.',
        ];
    }
}
