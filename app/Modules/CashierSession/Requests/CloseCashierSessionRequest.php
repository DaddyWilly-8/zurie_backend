<?php

namespace App\Modules\CashierSession\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashierSessionRequest extends FormRequest
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
            'closingBalance' => ['required', 'numeric', 'min:0'],
        ];
    }
}
