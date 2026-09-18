<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_id', 'debit_ledger_id', 'amount', 'journal_entry_id'])]
class PaymentItem extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'double'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
