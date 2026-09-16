<?php

namespace App\Modules\CashierSession\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'outlet_id',
    'opened_by',
    'opening_balance',
    'closed_by',
    'closing_balance',
    'expected_closing_balance',
    'variance',
    'status',
    'opened_at',
    'closed_at',
])]
class CashierSession extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'expected_closing_balance' => 'decimal:2',
            'variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cashier_session')
            ->logOnly(['outlet_id', 'status', 'opening_balance', 'closing_balance', 'variance'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => $this->status === 'closed'
                ? "Cashier session #{$this->id} closed (variance {$this->variance})"
                : "Cashier session #{$this->id} opened");
    }
}
