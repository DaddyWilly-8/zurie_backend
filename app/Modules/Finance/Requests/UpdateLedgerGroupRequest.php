<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLedgerGroupRequest extends FormRequest
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
        $groupId = $this->route('ledgerGroup')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:ledger_groups,code,' . $groupId],
            'nature' => ['sometimes', 'string', 'in:asset,liability,income,expense,equity'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:ledger_groups,id'],
        ];
    }
}
