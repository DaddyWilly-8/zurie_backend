<?php

namespace App\Modules\Faq\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFaqRequest extends FormRequest
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
            'question' => ['sometimes', 'string', 'max:500'],
            'answer' => ['sometimes', 'string'],
            'sortOrder' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'visible' => ['sometimes', 'boolean'],
        ];
    }
}
