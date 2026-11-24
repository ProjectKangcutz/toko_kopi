<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use App\Services\Storefront\StorefrontContentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('content.manage');
    }

    protected function prepareForValidation(): void
    {
        /** @var Page|null $routePage */
        $routePage = $this->route('page');
        $isBuiltIn = $routePage instanceof Page && $routePage->isBuiltInPolicy();

        $rawSlug = trim((string) $this->input('slug', ''));

        $parseBool = static function ($val, bool $default = false): bool {
            if ($val === null) {
                return $default;
            }
            if (is_bool($val)) {
                return $val;
            }
            $lower = strtolower(trim((string) $val));
            if (in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
            if (in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            return (bool) $val;
        };

        $this->merge([
            'slug' => $isBuiltIn
                ? $routePage->slug
                : ($rawSlug !== '' ? strtolower($rawSlug) : null),
            'is_published' => $parseBool($this->input('is_published'), true),
            'show_in_footer' => $isBuiltIn ? true : $parseBool($this->input('show_in_footer'), false),
            'remove_banner_image' => $parseBool($this->input('remove_banner_image'), false),
            'sort_order' => $this->filled('sort_order') ? $this->input('sort_order') : ($routePage?->sort_order ?? 10),
        ]);
    }

    public function rules(): array
    {
        /** @var Page|int|string|null $routePage */
        $routePage = $this->route('page');
        $pageId = $routePage instanceof Page ? $routePage->id : (is_numeric($routePage) ? (int) $routePage : null);
        $isBuiltIn = $routePage instanceof Page && $routePage->isBuiltInPolicy();

        $slugRules = $isBuiltIn
            ? ['nullable', 'string']
            : [
                'nullable',
                'string',
                'max:190',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(StorefrontContentService::RESERVED_SLUGS),
                Rule::unique('pages', 'slug')->ignore($pageId),
            ];

        return [
            'title' => ['required', 'string', 'max:200'],
            'slug' => $slugRules,
            'content' => ['required', 'string', 'max:100000'],
            'banner_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_banner_image' => ['required', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'is_published' => ['required', 'boolean'],
            'show_in_footer' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul halaman wajib diisi.',
            'slug.regex' => 'Slug URL hanya boleh berisi huruf kecil, angka, dan tanda hubung (-).',
            'slug.not_in' => 'Slug URL tersebut dicadangkan oleh sistem dan tidak dapat digunakan untuk halaman custom.',
            'slug.unique' => 'Slug URL sudah digunakan oleh halaman lain.',
            'content.required' => 'Isi halaman wajib diisi.',
            'banner_image.image' => 'Gambar banner harus berupa file gambar yang valid.',
            'banner_image.mimes' => 'Format gambar banner harus JPG, JPEG, PNG, atau WEBP.',
            'banner_image.max' => 'Ukuran gambar banner maksimal 2 MB.',
            'sort_order.integer' => 'Urutan tampil harus berupa angka bulat.',
        ];
    }
}
