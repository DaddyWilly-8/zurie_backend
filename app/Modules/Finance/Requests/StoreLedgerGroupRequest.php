<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLedgerGroupRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:ledger_groups,code'],
            'nature' => ['required', 'string', 'in:asset,liability,income,expense,equity'],
            'parentId' => ['nullable', 'integer', 'exists:ledger_groups,id'],
        ];
    }
}
