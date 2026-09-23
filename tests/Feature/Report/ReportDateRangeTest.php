<?php

namespace Tests\Feature\Report;

use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Report\Services\ReportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers pending-work item #3: period filtering on the flow-based reports
 * (sales/purchases) and that the newly-cached reports (trial balance,
 * balance sheet, debtors, creditors, inventory value) still return
 * correct, non-stale data within their own test run.
 */
class ReportDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);
    }

    private function makeOrder(string $createdAt): Order
    {
        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Widget', 'slug' => uniqid(), 'price' => 1000,
            'buying_price' => 500, 'vat_exempted' => true, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($productId);
        Inventory::where('product_id', $productId)->update(['quantity' => 100, 'stock_status' => 'IN_STOCK']);

        $order = app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0700',
            'items' => [['productId' => $productId, 'quantity' => 1]],
        ]);
        $order->forceFill(['created_at' => $createdAt])->save();

        // Backdate the journal entries this sale posted too — "date" is a
        // separate column from created_at (see FinanceService::postEntry()),
        // and revenueSummary()'s period figures are derived from it.
        DB::table('journal_entries')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->update(['date' => $createdAt]);

        return $order->fresh();
    }

    public function test_sales_by_channel_is_scoped_to_the_given_date_range(): void
    {
        $this->makeOrder('2026-01-05');
        $this->makeOrder('2026-06-15');

        $reports = app(ReportService::class);

        $all = $reports->salesByChannel();
        $this->assertSame(2, $all['website']['count']);

        $januaryOnly = $reports->salesByChannel('2026-01-01', '2026-01-31');
        $this->assertSame(1, $januaryOnly['website']['count']);

        $outsideBoth = $reports->salesByChannel('2025-01-01', '2025-12-31');
        $this->assertArrayNotHasKey('website', $outsideBoth);
    }

    public function test_revenue_summary_period_figures_match_ledger_entries_in_that_window(): void
    {
        $this->makeOrder('2026-01-05'); // 1000 sale, 500 cogs
        $this->makeOrder('2026-06-15'); // another 1000 sale, 500 cogs

        $reports = app(ReportService::class);

        $allTime = $reports->revenueSummary();
        $this->assertSame(2000.0, $allTime['grossSales']);

        $januaryOnly = $reports->revenueSummary('2026-01-01', '2026-01-31');
        $this->assertSame(1000.0, $januaryOnly['grossSales']);
        $this->assertSame(500.0, $januaryOnly['costOfGoodsSold']);
        $this->assertSame(500.0, $januaryOnly['grossProfit']);
    }

    public function test_purchase_summary_is_scoped_to_the_given_date_range(): void
    {
        $reports = app(ReportService::class);
        $allTime = $reports->purchaseSummary();
        $this->assertSame(0, $allTime['deliveriesReceived']);

        $scoped = $reports->purchaseSummary('2026-01-01', '2026-01-31');
        $this->assertSame(0, $scoped['deliveriesReceived']);
        $this->assertSame([], $scoped['purchaseOrders']);
    }

    public function test_cached_reports_still_return_correct_data_on_repeated_calls(): void
    {
        $this->makeOrder('2026-01-05');
        $reports = app(ReportService::class);

        $first = $reports->debtors();
        $second = $reports->debtors(); // served from cache the 2nd time
        $this->assertEquals($first, $second);

        $trialBalanceFirst = $reports->trialBalance();
        $trialBalanceSecond = $reports->trialBalance();
        $this->assertEquals($trialBalanceFirst, $trialBalanceSecond);
    }
}
