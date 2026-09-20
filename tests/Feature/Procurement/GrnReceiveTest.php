<?php

namespace Tests\Feature\Procurement;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrnReceiveTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;
    private $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $unitId = DB::table('measurement_units')->insertGetId(['name' => 'Piece', 'symbol' => 'pc', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Widget', 'slug' => 'widget', 'price' => 1000,
            'buying_price' => 500, 'vat_exempted' => true, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $supplier = app(SupplierService::class)->create(['name' => 'Acme Supplies']);
        $this->purchaseOrder = app(PurchaseOrderService::class)->create([
            'stakeholderId' => $supplier->id,
            'items' => [['productId' => $this->productId, 'measurementUnitId' => $unitId, 'quantity' => 10, 'rate' => 500, 'vatPercentage' => 0, 'conversionFactor' => 1]],
        ]);
    }

    private function receive(float $quantity)
    {
        return app(GrnService::class)->create([
            'purchaseOrderId' => $this->purchaseOrder->id,
            'lines' => [['purchaseOrderItemId' => $this->purchaseOrder->items->first()->id, 'quantityReceived' => $quantity]],
        ]);
    }

    private function stock(): int
    {
        return (int) Inventory::where('product_id', $this->productId)->sum('quantity');
    }

    public function test_receiving_adds_stock_and_posts_inventory_against_supplier(): void
    {
        $this->receive(4);

        $finance = app(FinanceService::class);
        $this->assertSame(4, $this->stock());
        $this->assertSame(2000.0, (float) $finance->systemLedger('INV-ASSET')->current_balance);
        $this->assertSame([], $finance->reconcileLedgers());
        $this->assertSame('partially_received', $this->purchaseOrder->fresh()->status);
    }

    public function test_cannot_receive_more_than_ordered(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->receive(11);
    }

    public function test_unreceive_restores_stock_ledger_and_status(): void
    {
        $grn = $this->receive(10);
        $this->assertSame('fully_received', $this->purchaseOrder->fresh()->status);

        app(GrnService::class)->delete($grn->fresh());

        $finance = app(FinanceService::class);
        $this->assertSame(0, $this->stock());
        $this->assertSame(0.0, (float) $finance->systemLedger('INV-ASSET')->current_balance);
        $this->assertSame([], $finance->reconcileLedgers());
        $this->assertSame('pending', $this->purchaseOrder->fresh()->status);
    }

    public function test_unreceive_is_blocked_once_the_stock_has_been_sold(): void
    {
        $grn = $this->receive(5);
        Inventory::where('product_id', $this->productId)->update(['quantity' => 2]); // 3 already sold

        $this->expectException(InsufficientStockException::class);

        app(GrnService::class)->delete($grn->fresh());
    }
}
