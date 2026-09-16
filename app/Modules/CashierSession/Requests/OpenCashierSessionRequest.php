<?php

namespace App\Modules\CashierSession\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashierSessionRequest extends FormRequest
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
            'outletId' => ['required', 'integer', 'exists:sales_outlets,id'],
            'openingBalance' => ['required', 'numeric', 'min:0'],
        ];
    }
}
