<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLedgerRequest extends FormRequest
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
            'ledgerGroupId' => ['required', 'integer', 'exists:ledger_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:ledgers,code'],
            'openingBalance' => ['nullable', 'numeric'],
            'isContra' => ['nullable', 'boolean'],
        ];
    }
}
