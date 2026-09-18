<?php

namespace App\Modules\Currency\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExchangeRateRequest extends FormRequest
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
            'rateToBaseCurrency' => ['required', 'numeric', 'gt:0'],
            'rateDatetime' => ['nullable', 'date'],
        ];
    }
}
