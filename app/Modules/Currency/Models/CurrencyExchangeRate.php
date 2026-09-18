<?php

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['currency_id', 'rate_datetime', 'rate_to_base_currency'])]
class CurrencyExchangeRate extends Model
{
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
}
