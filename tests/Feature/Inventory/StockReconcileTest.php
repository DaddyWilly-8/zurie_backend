<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Outlet\Services\OutletService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the new inventory:reconcile safety net (area 5
 * of the ops review — same role as finance:reconcile plays for ledger
 * balances, applied to stock instead). Proves it stays clean after real
 * InventoryService writes, and actually catches drift when a write
 * bypasses the Service (a raw DB update, exactly the class of bug this
 * command exists to catch).
 */
class StockReconcileTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $outletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Widget', 'slug' => 'widget',
            'price' => 1000, 'buying_price' => 400, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->outletId = app(OutletService::class)->defaultOnlineOutlet()->id;
        app(InventoryService::class)->provisionForProduct($this->productId, $this->outletId);
    }

    public function test_reconcile_finds_no_drift_after_normal_service_writes(): void
    {
        $inventory = app(InventoryService::class);
        $inventory->update($this->productId, ['quantity' => 100], $this->outletId);
        $inventory->decrementForOrder($this->productId, 10, outletId: $this->outletId);
        $inventory->restockForOrder($this->productId, 3, outletId: $this->outletId);

        $this->assertSame([], $inventory->reconcileStock());
    }

    public function test_reconcile_catches_drift_from_a_write_that_bypasses_the_service(): void
    {
        $inventory = app(InventoryService::class);
        $inventory->update($this->productId, ['quantity' => 100], $this->outletId);

        // A raw update, the same class of bug this command exists to
        // catch — no matching inventory_movements row for this change.
        DB::table('inventory')
            ->where('product_id', $this->productId)
            ->where('sales_outlet_id', $this->outletId)
            ->update(['quantity' => 250]);

        $drift = $inventory->reconcileStock();

        $this->assertCount(1, $drift);
        $this->assertSame($this->productId, $drift[0]['productId']);
        $this->assertSame(250, $drift[0]['stored']);
        $this->assertSame(100, $drift[0]['expected']);
        $this->assertSame(150, $drift[0]['difference']);
    }
}
