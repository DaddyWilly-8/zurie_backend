<?php

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['currency_id', 'rate_datetime', 'rate_to_base_currency'])]
class CurrencyExchangeRate extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return [
            'rate_datetime' => 'datetime',
            'rate_to_base_currency' => 'double',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Audit trail (Admin > Activity): who created/changed/deleted this
     * and which fields changed.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('currency')
            ->logOnly(['currency_id', 'rate_datetime', 'rate_to_base_currency'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Exchange rate for currency #{$this->currency_id} {$event}");
    }
}
