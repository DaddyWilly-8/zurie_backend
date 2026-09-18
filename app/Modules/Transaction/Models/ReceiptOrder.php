<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['receipt_id', 'order_id', 'amount_applied'])]
class ReceiptOrder extends Model
{
    protected $table = 'receipt_order';

    protected function casts(): array
    {
        return ['amount_applied' => 'double'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    // Deliberately no relation into Order — cross-module reference, no FK
    // (see the migration's docblock), consistent with Order.customer_id's
    // own established pattern.
}
