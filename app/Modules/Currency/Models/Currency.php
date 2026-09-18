<?php

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
