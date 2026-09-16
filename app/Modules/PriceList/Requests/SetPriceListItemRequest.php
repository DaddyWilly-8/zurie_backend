<?php

namespace App\Modules\PriceList\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPriceListItemRequest extends FormRequest
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
            'productId' => ['required', 'integer', 'exists:products,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'salePrice' => ['nullable', 'numeric', 'min:0', 'lte:price'],
        ];
    }
}
