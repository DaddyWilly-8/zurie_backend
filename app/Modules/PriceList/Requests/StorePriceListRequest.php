<?php

namespace App\Modules\PriceList\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePriceListRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'outletId' => ['nullable', 'integer', 'exists:sales_outlets,id'],
            'customerId' => ['nullable', 'integer', 'exists:customers,id'],
            'validFrom' => ['nullable', 'date'],
            'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'],
        ];
    }
}
