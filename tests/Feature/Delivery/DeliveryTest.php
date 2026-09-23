<?php

namespace Tests\Feature\Delivery;

use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Widget', 'slug' => 'widget', 'price' => 1000,
            'buying_price' => 500, 'vat_exempted' => true, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(InventoryService::class)->provisionForProduct($productId);
        Inventory::where('product_id', $productId)->update(['quantity' => 10, 'stock_status' => 'IN_STOCK']);

        $this->order = app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0700',
            'items' => [['productId' => $productId, 'quantity' => 5]],
        ]);
    }

    public function test_dispatching_within_the_ordered_quantity_succeeds_with_no_stock_or_ledger_effect(): void
    {
        $finance = app(FinanceService::class);
        $stockBefore = (int) Inventory::sum('quantity');

        app(DeliveryService::class)->create([
            'orderNumber' => $this->order->order_number,
            'lines' => [['orderItemId' => $this->order->items->first()->id, 'quantityDispatched' => 3]],
        ]);

        $this->assertSame($stockBefore, (int) Inventory::sum('quantity'));
        $this->assertSame([], $finance->reconcileLedgers());
        $this->assertSame('new', $this->order->fresh()->status);
    }

    public function test_cannot_dispatch_more_than_was_ordered(): void
    {
        $this->expectException(ValidationException::class);

        app(DeliveryService::class)->create([
            'orderNumber' => $this->order->order_number,
            'lines' => [['orderItemId' => $this->order->items->first()->id, 'quantityDispatched' => 6]],
        ]);
    }

    public function test_a_second_dispatch_is_capped_by_what_remains(): void
    {
        $itemId = $this->order->items->first()->id;
        $service = app(DeliveryService::class);

        $service->create(['orderNumber' => $this->order->order_number, 'lines' => [['orderItemId' => $itemId, 'quantityDispatched' => 4]]]);

        $undispatched = $service->undispatchedItemsForOrder($this->order->order_number);
        $this->assertSame(1.0, (float) $undispatched[0]['remainingQuantity']);

        $this->expectException(ValidationException::class);
        $service->create(['orderNumber' => $this->order->order_number, 'lines' => [['orderItemId' => $itemId, 'quantityDispatched' => 2]]]);
    }
}
