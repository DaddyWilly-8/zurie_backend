<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_id', 'credit_ledger_id', 'amount', 'journal_entry_id'])]
class ReceiptItem extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'double'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
