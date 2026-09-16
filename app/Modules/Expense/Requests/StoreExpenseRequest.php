<?php

namespace App\Modules\Expense\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
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
            'category' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paymentMethod' => ['nullable', 'in:cash,bank'],
            'description' => ['nullable', 'string', 'max:255'],
            'costCenterId' => ['nullable', 'integer', 'exists:cost_centers,id'],
        ];
    }
}
