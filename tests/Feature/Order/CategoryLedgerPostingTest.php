<?php

namespace Tests\Feature\Order;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Finance\Services\LedgerGroupService;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Services\CategoryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the per-category Income/Expense ledger feature: a category can
 * override where its sales post revenue/COGS to, independently for each
 * field, falling back to the global SALES/COGS system ledgers when unset.
 */
class CategoryLedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);
        $this->finance = app(FinanceService::class);
    }

    private function makeProduct(string $categoryName, float $price, float $buyingPrice, ?int $incomeLedgerId = null, ?int $expenseLedgerId = null): array
    {
        $category = app(CategoryService::class)->create([
            'name' => $categoryName,
            'slug' => strtolower($categoryName).'-'.uniqid(),
            'incomeLedgerId' => $incomeLedgerId,
            'expenseLedgerId' => $expenseLedgerId,
        ]);

        $productId = DB::table('products')->insertGetId([
            'category_id' => $category->id, 'name' => $categoryName.' Product', 'slug' => uniqid(),
            'price' => $price, 'buying_price' => $buyingPrice, 'vat_exempted' => true, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(InventoryService::class)->provisionForProduct($productId);
        Inventory::where('product_id', $productId)->update(['quantity' => 100, 'stock_status' => 'IN_STOCK']);

        return ['category' => $category, 'productId' => $productId];
    }

    private function makeLedger(string $name, string $nature = 'income'): int
    {
        $group = app(LedgerGroupService::class)->create(['name' => $name.' Group', 'code' => strtoupper(substr(md5($name.uniqid()), 0, 8)), 'nature' => $nature]);

        return app(LedgerService::class)->create(['ledgerGroupId' => $group->id, 'name' => $name, 'code' => strtoupper(substr(md5($name.'ldg'.uniqid()), 0, 8))])->id;
    }

    public function test_a_category_with_no_custom_ledgers_posts_to_the_global_sales_and_cogs_ledgers(): void
    {
        ['productId' => $productId] = $this->makeProduct('Plain', 1000, 400);

        app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0700',
            'items' => [['productId' => $productId, 'quantity' => 2]],
        ]);

        $this->assertSame(2000.0, (float) $this->finance->systemLedger('SALES')->fresh()->current_balance);
        $this->assertSame(800.0, (float) $this->finance->systemLedger('COGS')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_a_category_with_custom_income_and_expense_ledgers_posts_there_instead(): void
    {
        $incomeLedgerId = $this->makeLedger('Handbag Sales');
        $expenseLedgerId = $this->makeLedger('Handbag COGS', 'expense');
        ['productId' => $productId] = $this->makeProduct('Handbags', 5000, 2000, $incomeLedgerId, $expenseLedgerId);

        app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0701',
            'items' => [['productId' => $productId, 'quantity' => 1]],
        ]);

        $this->assertSame(5000.0, (float) $this->finance->findLedger($incomeLedgerId)->fresh()->current_balance);
        $this->assertSame(2000.0, (float) $this->finance->findLedger($expenseLedgerId)->fresh()->current_balance);
        // The global ledgers must stay untouched — this sale never posted there.
        $this->assertSame(0.0, (float) $this->finance->systemLedger('SALES')->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->systemLedger('COGS')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_income_and_expense_ledgers_fall_back_independently(): void
    {
        $incomeLedgerId = $this->makeLedger('Only Income Set');
        // expenseLedgerId deliberately left null.
        ['productId' => $productId] = $this->makeProduct('HalfCustom', 1000, 300, $incomeLedgerId, null);

        app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0702',
            'items' => [['productId' => $productId, 'quantity' => 1]],
        ]);

        $this->assertSame(1000.0, (float) $this->finance->findLedger($incomeLedgerId)->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->systemLedger('SALES')->fresh()->current_balance);
        // Expense wasn't overridden, so it must land on the global COGS ledger.
        $this->assertSame(300.0, (float) $this->finance->systemLedger('COGS')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_an_order_spanning_two_categories_splits_correctly_between_their_ledgers(): void
    {
        $bagsIncome = $this->makeLedger('Bags Income');
        $bagsExpense = $this->makeLedger('Bags Expense', 'expense');
        ['productId' => $bagProductId] = $this->makeProduct('Bags', 3000, 1000, $bagsIncome, $bagsExpense);
        ['productId' => $plainProductId] = $this->makeProduct('Plain2', 500, 200);

        app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0703',
            'items' => [
                ['productId' => $bagProductId, 'quantity' => 1],
                ['productId' => $plainProductId, 'quantity' => 2],
            ],
        ]);

        $this->assertSame(3000.0, (float) $this->finance->findLedger($bagsIncome)->fresh()->current_balance);
        $this->assertSame(1000.0, (float) $this->finance->findLedger($bagsExpense)->fresh()->current_balance);
        $this->assertSame(1000.0, (float) $this->finance->systemLedger('SALES')->fresh()->current_balance); // 500*2
        $this->assertSame(400.0, (float) $this->finance->systemLedger('COGS')->fresh()->current_balance); // 200*2
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_cancelling_an_order_reverses_the_exact_category_ledgers_it_posted_to(): void
    {
        $incomeLedgerId = $this->makeLedger('Cancel Income');
        $expenseLedgerId = $this->makeLedger('Cancel Expense', 'expense');
        ['productId' => $productId] = $this->makeProduct('Cancelled', 2000, 700, $incomeLedgerId, $expenseLedgerId);

        $order = app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0704',
            'items' => [['productId' => $productId, 'quantity' => 1]],
        ]);

        $this->assertSame(2000.0, (float) $this->finance->findLedger($incomeLedgerId)->fresh()->current_balance);

        app(OrderService::class)->cancel($order->fresh());

        $this->assertSame(0.0, (float) $this->finance->findLedger($incomeLedgerId)->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->findLedger($expenseLedgerId)->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_deleting_the_categorys_ledger_after_the_sale_falls_back_cleanly_on_cancel(): void
    {
        $incomeLedgerId = $this->makeLedger('Doomed Income');
        ['productId' => $productId] = $this->makeProduct('Doomed', 1000, 300, $incomeLedgerId, null);

        $order = app(OrderService::class)->checkout([
            'customerName' => 'Buyer', 'customerPhone' => '0705',
            'items' => [['productId' => $productId, 'quantity' => 1]],
        ]);

        // The category's own ledger id column gets nulled out (nullOnDelete)
        // once the ledger itself is deleted — simulate that directly, since
        // LedgerService::delete() would refuse to delete one with posted
        // activity. What matters here is cancel() not blowing up when the
        // category's ledger reference no longer resolves.
        DB::table('categories')->where('income_ledger_id', $incomeLedgerId)->update(['income_ledger_id' => null]);

        app(OrderService::class)->cancel($order->fresh());

        // Falls back to the global SALES ledger for the reversal — net
        // effect: SALES ends up negative by the sale amount, since the
        // original sale posted to the now-gone custom ledger, not SALES.
        // The point of this test is that reconcile still comes back clean
        // (nothing was left unbalanced), not that any one ledger nets zero.
        $this->assertSame([], $this->finance->reconcileLedgers());
    }
}
