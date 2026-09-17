<?php

namespace App\Modules\PriceList\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePriceListRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'outletId' => ['sometimes', 'nullable', 'integer', 'exists:sales_outlets,id'],
            'customerId' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
            'validFrom' => ['sometimes', 'nullable', 'date'],
            'validTo' => ['sometimes', 'nullable', 'date', 'after_or_equal:validFrom'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
