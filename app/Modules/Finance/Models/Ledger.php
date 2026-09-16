<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ledger_group_id',
    'name',
    'code',
    'opening_balance',
    'current_balance',
    'reference_type',
    'reference_id',
    'is_system',
    'is_contra',
])]
class Ledger extends Model
{
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_system' => 'boolean',
            'is_contra' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'ledger_group_id');
    }
}
