<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['receipt_number', 'transaction_date', 'reference', 'narration', 'debit_ledger_id', 'total_amount'])]
class Receipt extends Model
{
    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'total_amount' => 'double'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    /** N-N — the Orders this receipt settles AR against. No FK; order_id is a cross-module reference (see the migration's docblock). */
    public function sales(): HasMany
    {
        return $this->hasMany(ReceiptOrder::class);
    }
}
