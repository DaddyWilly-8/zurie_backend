<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['payment_number', 'transaction_date', 'reference', 'narration', 'credit_ledger_id', 'total_amount'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'total_amount' => 'double'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class);
    }
}
