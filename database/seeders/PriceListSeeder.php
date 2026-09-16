<?php

namespace Database\Seeders;

use App\Modules\PriceList\Models\PriceList;
use Illuminate\Database\Seeder;

/**
 * The implicit "Default" list — Product.price/sale_price IS this list's
 * price, so it has no items rows at all. Exists purely so the admin price
 * list screen has something to show for "everyone not on an override" —
 * PriceListService::resolvePrice() never actually queries this row, it
 * falls through to the caller-supplied default instead. See
 * Zurie_V2_Architecture_Design (2).md §33.
 */
class PriceListSeeder extends Seeder
{
    public function run(): void
    {
        PriceList::firstOrCreate(
            ['name' => 'Default'],
            ['is_default' => true, 'is_active' => true]
        );
    }
}
