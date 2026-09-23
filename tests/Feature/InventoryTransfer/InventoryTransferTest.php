<?php

namespace Tests\Feature\InventoryTransfer;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\InventoryTransfer\Services\InventoryTransferService;
use App\Modules\Outlet\Services\OutletService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTransferTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;
    private int $sourceOutletId;
    private int $destinationOutletId;

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

        $outlets = app(OutletService::class);
        $this->sourceOutletId = $outlets->defaultOnlineOutlet()->id;
        $this->destinationOutletId = $outlets->create(['name' => 'Second Outlet', 'type' => 'physical'])->id;

        $inventory = app(InventoryService::class);
        $inventory->provisionForProduct($this->productId, $this->sourceOutletId);
        Inventory::where('product_id', $this->productId)->where('sales_outlet_id', $this->sourceOutletId)
            ->update(['quantity' => 20, 'stock_status' => 'IN_STOCK']);
    }

    private function stockAt(int $outletId): int
    {
        return (int) (Inventory::where('product_id', $this->productId)->where('sales_outlet_id', $outletId)->value('quantity') ?? 0);
    }

    public function test_internal_transfer_moves_stock_between_outlets_with_no_ledger_effect(): void
    {
        $finance = app(FinanceService::class);

        app(InventoryTransferService::class)->create([
            'type' => 'internal',
            'sourceOutletId' => $this->sourceOutletId,
            'destinationOutletId' => $this->destinationOutletId,
            'items' => [['productId' => $this->productId, 'quantity' => 5]],
        ]);

        $this->assertSame(15, $this->stockAt($this->sourceOutletId));
        $this->assertSame(5, $this->stockAt($this->destinationOutletId));
        $this->assertSame([], $finance->reconcileLedgers());
        $this->assertSame(0.0, (float) $finance->systemLedger('INV-WRITEOFF')->current_balance);
    }

    public function test_external_transfer_writes_off_stock_at_buying_price(): void
    {
        $finance = app(FinanceService::class);

        app(InventoryTransferService::class)->create([
            'type' => 'external',
            'sourceOutletId' => $this->sourceOutletId,
            'items' => [['productId' => $this->productId, 'quantity' => 3]],
        ]);

        $this->assertSame(17, $this->stockAt($this->sourceOutletId));
        $this->assertSame(1200.0, (float) $finance->systemLedger('INV-WRITEOFF')->fresh()->current_balance); // 3 * 400
        $this->assertSame(-1200.0, (float) $finance->systemLedger('INV-ASSET')->fresh()->current_balance);
        $this->assertSame([], $finance->reconcileLedgers());
    }

    public function test_cost_center_change_moves_no_stock_and_posts_no_ledger_entry(): void
    {
        $costCenterId = DB::table('cost_centers')->insertGetId(['name' => 'Other CC', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $finance = app(FinanceService::class);

        app(InventoryTransferService::class)->create([
            'type' => 'cost_center_change',
            'sourceOutletId' => $this->sourceOutletId,
            'sourceCostCenterId' => $costCenterId,
            'destinationCostCenterId' => $costCenterId,
            'items' => [['productId' => $this->productId, 'quantity' => 5]],
        ]);

        $this->assertSame(20, $this->stockAt($this->sourceOutletId));
        $this->assertSame([], $finance->reconcileLedgers());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_internal_transfer_without_a_destination_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(InventoryTransferService::class)->create([
            'type' => 'internal',
            'sourceOutletId' => $this->sourceOutletId,
            'items' => [['productId' => $this->productId, 'quantity' => 1]],
        ]);
    }

    public function test_transferring_more_than_available_stock_is_rejected(): void
    {
        $this->expectException(\App\Modules\Inventory\Exceptions\InsufficientStockException::class);

        app(InventoryTransferService::class)->create([
            'type' => 'external',
            'sourceOutletId' => $this->sourceOutletId,
            'items' => [['productId' => $this->productId, 'quantity' => 999]],
        ]);
    }
}
