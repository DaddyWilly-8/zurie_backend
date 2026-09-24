<?php

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'name',
    'name_plural',
    'code',
    'symbol',
    'symbol_native',
    'decimal_digits',
    'is_base',
    'created_by',
    'is_active',
])]
class Currency extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'decimal_digits' => 'integer',
        ];
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(CurrencyExchangeRate::class);
    }

    /**
     * Audit trail (Admin > Activity): who created/changed/deleted this
     * and which fields changed.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('currency')
            ->logOnly(['name', 'name_plural', 'code', 'symbol', 'symbol_native', 'decimal_digits', 'is_base', 'created_by', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Currency '{$this->code}' {$event}");
    }
}
