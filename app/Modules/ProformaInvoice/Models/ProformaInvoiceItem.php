<?php

namespace App\Modules\ProformaInvoice\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['proforma_invoice_id', 'product_id', 'quantity', 'unit_price', 'line_total'])]
class ProformaInvoiceItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'double',
            'unit_price' => 'double',
            'line_total' => 'double',
        ];
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }
}
