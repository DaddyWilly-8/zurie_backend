<?php

namespace App\Modules\Currency\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyRequest extends FormRequest
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
        $currency = $this->route('currency');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'namePlural' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'min:3', 'max:5', 'regex:/^[A-Za-z]+$/', Rule::unique('currencies', 'code')->ignore($currency)],
            'symbol' => ['sometimes', 'string', 'max:5'],
            'symbolNative' => ['sometimes', 'string', 'max:5'],
            'decimalDigits' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
