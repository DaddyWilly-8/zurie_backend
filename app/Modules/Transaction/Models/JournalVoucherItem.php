<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['journal_voucher_id', 'debit_ledger_id', 'credit_ledger_id', 'amount', 'journal_entry_id'])]
class JournalVoucherItem extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'double'];
    }

    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }
}
