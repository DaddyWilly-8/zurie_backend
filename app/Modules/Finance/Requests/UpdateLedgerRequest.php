<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLedgerRequest extends FormRequest
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
        $ledgerId = $this->route('ledger')?->id;

        return [
            'ledgerGroupId' => ['sometimes', 'integer', 'exists:ledger_groups,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:ledgers,code,' . $ledgerId],
        ];
    }
}
