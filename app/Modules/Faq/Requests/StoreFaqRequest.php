<?php

namespace App\Modules\Faq\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
            'visible' => ['nullable', 'boolean'],
        ];
    }
}
