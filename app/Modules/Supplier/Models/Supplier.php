<?php

namespace App\Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'phone', 'email', 'address', 'is_active'])]
class Supplier extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Deliberately no relation to Purchase or to Finance's Ledger — a
    // Supplier's payable ledger is looked up via
    // FinanceService::ledgerFor($supplier), a cross-module Service call,
    // not an Eloquent relationship into another module's table.
}
