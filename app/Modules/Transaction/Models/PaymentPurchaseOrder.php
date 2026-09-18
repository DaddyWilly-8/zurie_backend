<?php

namespace App\Modules\Transaction\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_id', 'purchase_order_id', 'amount_applied'])]
class PaymentPurchaseOrder extends Model
{
    protected $table = 'payment_purchase_order';

    protected function casts(): array
    {
        return ['amount_applied' => 'double'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // Deliberately no relation into PurchaseOrder — cross-module
    // reference, no FK, same reasoning as ReceiptOrder's own docblock.
}
