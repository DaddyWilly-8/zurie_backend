<?php

namespace Database\Seeders;

use App\Modules\Currency\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Seeds the one base currency every amount is implicitly denominated in
 * until a real multi-currency need shows up. No exchange_rates row for it
 * — see CurrencyService::addExchangeRate()'s guard, matching the reference
 * doc's rule that rates aren't tracked against the base currency itself.
 */
class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::firstOrCreate(
            ['code' => 'TZS'],
            [
                'name' => 'Tanzanian Shilling',
                'name_plural' => 'Tanzanian Shillings',
                'symbol' => 'TSh',
                'symbol_native' => 'TSh',
                'decimal_digits' => 0,
                'is_base' => true,
                'is_active' => true,
            ]
        );
    }
}
