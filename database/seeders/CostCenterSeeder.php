<?php

namespace Database\Seeders;

use App\Modules\Finance\Models\CostCenter;
use Illuminate\Database\Seeder;

/**
 * Single-location businesses (Zuriè today) need nothing beyond this one
 * default — see Zurie_V2_Architecture_Design (2).md §31/§36. Opening a
 * second branch later is one new row, not a schema change.
 */
class CostCenterSeeder extends Seeder
{
    public function run(): void
    {
        CostCenter::firstOrCreate(['name' => 'Head Office'], ['is_active' => true]);
    }
}
