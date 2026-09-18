<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fund_transfer_id', 'debit_ledger_id', 'amount', 'journal_entry_id'])]
class FundTransferItem extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'double'];
    }

    public function fundTransfer(): BelongsTo
    {
        return $this->belongsTo(FundTransfer::class);
    }
}
