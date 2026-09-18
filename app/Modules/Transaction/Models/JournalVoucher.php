<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['voucher_number', 'transaction_date', 'reference', 'narration', 'total_amount'])]
class JournalVoucher extends Model
{
    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'total_amount' => 'double'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(JournalVoucherItem::class);
    }
}
