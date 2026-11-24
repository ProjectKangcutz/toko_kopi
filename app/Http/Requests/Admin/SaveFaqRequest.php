<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin !== null && $admin->hasPermission('content.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $this->filled('sort_order') ? $this->input('sort_order') : 0,
        ]);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:80'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:10000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Kategori FAQ wajib diisi.',
            'question.required' => 'Pertanyaan FAQ wajib diisi.',
            'answer.required' => 'Jawaban FAQ wajib diisi.',
            'sort_order.integer' => 'Urutan tampil harus berupa angka bulat.',
        ];
    }
}
