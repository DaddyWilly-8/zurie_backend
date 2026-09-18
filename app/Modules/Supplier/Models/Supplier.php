<?php

namespace App\Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'phone', 'email', 'address', 'is_active', 'is_supplier_role'])]
class Supplier extends Model
{
    // Phase C (Stakeholder merge, see Zurie_V3_ProsERP_Adaptation_Plan.md)
    // — this table is now `stakeholders`, shared with Customer. See
    // Customer model's docblock for the full reasoning; `is_supplier_role`
    // is this module's half of the same discriminator.
    protected $table = 'stakeholders';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_supplier_role' => 'boolean',
        ];
    }

    // Deliberately no relation to Purchase or to Finance's Ledger — a
    // Supplier's payable ledger is looked up via
    // FinanceService::ledgerFor($supplier), a cross-module Service call,
    // not an Eloquent relationship into another module's table.
}
