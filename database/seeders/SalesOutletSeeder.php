<?php

namespace Database\Seeders;

use App\Modules\Finance\Models\CostCenter;
use App\Modules\Outlet\Models\SalesOutlet;
use Illuminate\Database\Seeder;

/**
 * The one outlet every existing website order resolves to in Phase 3 —
 * see OutletService::defaultOnlineOutlet(). Must run after
 * CostCenterSeeder.
 */
class SalesOutletSeeder extends Seeder
{
    public function run(): void
    {
        $headOffice = CostCenter::where('name', 'Head Office')->firstOrFail();

        SalesOutlet::firstOrCreate(
            ['name' => 'Online Store'],
            ['type' => 'online', 'cost_center_id' => $headOffice->id, 'is_active' => true]
        );
    }
}
