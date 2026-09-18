<?php

namespace App\Modules\ProformaInvoice\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'proforma_number',
    'proforma_date',
    'expiry_date',
    'sales_outlet_id',
    'stakeholder_id',
    'currency_id',
    'exchange_rate',
    'total_amount',
    'is_active',
    'notes',
])]
class ProformaInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'proforma_date' => 'date',
            'expiry_date' => 'date',
            'exchange_rate' => 'double',
            'total_amount' => 'double',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProformaInvoiceItem::class);
    }

    // Deliberately no relation/conversion method to Order — ProsERP itself
    // never auto-converts a proforma into a real sale (see the reference
    // doc); staff create an ordinary Order/POS sale separately if the
    // customer accepts. No stock effect, no ledger posting anywhere in
    // this module.
}
