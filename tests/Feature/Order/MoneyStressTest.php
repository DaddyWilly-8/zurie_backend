<?php

namespace Tests\Feature\Order;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Models\Order;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deliberately adversarial money scenarios beyond VatModesTest's normal
 * cases — odd per-line prices that don't divide cleanly, large amounts,
 * and a 100%-off coupon. Every scenario's only real assertion is
 * reconcileLedgers() === [] (the books are internally consistent) and
 * balanceSheet()->isBalanced, since those two together are what actually
 * proves no money was created or destroyed by rounding.
 */
class MoneyStressTest extends TestCase
{
    use RefreshDatabase;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);
        config(['zurie.default_vat_percentage' => 18.0]);
        $this->categoryId = DB::table('categories')->insertGetId(['name' => 'Misc', 'slug' => 'misc', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(float $price, float $cost = 333.33): int
    {
        $id = DB::table('products')->insertGetId([
            'category_id' => $this->categoryId, 'name' => 'P'.uniqid(), 'slug' => 'p'.uniqid(),
            'price' => $price, 'buying_price' => $cost, 'vat_exempted' => false, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($id);
        Inventory::where('product_id', $id)->update(['quantity' => 500, 'stock_status' => 'IN_STOCK']);

        return $id;
    }

    private function coupon(string $code, string $type, float $value): void
    {
        DB::table('coupons')->insert([
            'code' => $code, 'type' => $type, 'value' => $value,
            'is_active' => true, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertBooksBalanced(): void
    {
        $this->assertSame([], app(FinanceService::class)->reconcileLedgers());
        $this->assertTrue(app(FinanceService::class)->balanceSheet()['isBalanced']);
    }

    public function test_many_odd_priced_lines_still_balance_to_the_cent(): void
    {
        // Prices chosen specifically to produce repeating/non-terminating
        // fractions under an 18% VAT split (e.g. 333.33 x 18% = 59.9994),
        // across enough lines that per-line rounding errors would visibly
        // compound if vatPerLine()'s remainder handling were wrong.
        $prices = [333.33, 1099.99, 7.77, 45000.01, 999.95, 12345.67, 0.99, 10001.11];
        $lines = array_map(fn ($p) => [$this->product($p), random_int(1, 5)], $prices);

        $payload = [
            'customerName' => 'Stress Buyer',
            'customerPhone' => '0'.random_int(700000000, 799999999),
            'items' => array_map(fn ($l) => ['productId' => $l[0], 'quantity' => $l[1]], $lines),
        ];

        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $order = Order::where('order_number', $response->json('data.orderNumber'))->firstOrFail();

        // total_amount must equal the sum of what was actually posted to AR.
        $this->assertSame(
            round((float) $order->total_amount, 2),
            round((float) app(FinanceService::class)->systemLedger('AR')->current_balance, 2),
        );
        $this->assertBooksBalanced();
    }

    public function test_hundred_percent_off_coupon_leaves_a_zero_debt_but_full_cogs_and_discount(): void
    {
        config(['zurie.prices_include_vat' => false]);
        $product = $this->product(20000, cost: 12000);
        $this->coupon('FREE100', 'percentage', 100);

        $response = $this->postJson('/api/v1/orders', [
            'customerName' => 'Freebie', 'customerPhone' => '0'.random_int(700000000, 799999999),
            'items' => [['productId' => $product, 'quantity' => 1]],
            'couponCode' => 'FREE100',
        ])->assertCreated();

        $order = Order::where('order_number', $response->json('data.orderNumber'))->firstOrFail();

        // VAT computed on the fully-discounted (zero) taxable amount, so
        // owes nothing — but COGS/Inventory still move at full cost, since
        // the goods genuinely left stock regardless of price charged.
        $this->assertSame(0.0, (float) $order->vat_amount);
        $this->assertSame(0.0, (float) $order->total_amount);
        $this->assertSame(20000.0, (float) $order->discount_amount);
        $this->assertSame(12000.0, (float) app(FinanceService::class)->systemLedger('COGS')->current_balance);
        $this->assertBooksBalanced();
    }

    public function test_very_large_order_amount_does_not_overflow_or_drift(): void
    {
        // 999,999,999.99 x 3 — well past int32 range, exercises Money's
        // integer-cents arithmetic at the DECIMAL(14,2) column's ceiling.
        config(['zurie.prices_include_vat' => false]);
        $product = $this->product(999999999.99, cost: 500000000.00);

        $response = $this->postJson('/api/v1/orders', [
            'customerName' => 'Whale', 'customerPhone' => '0'.random_int(700000000, 799999999),
            'items' => [['productId' => $product, 'quantity' => 3]],
        ])->assertCreated();

        $order = Order::where('order_number', $response->json('data.orderNumber'))->firstOrFail();

        // Computed via bcmath, not float, so the expectation itself isn't
        // subject to the same drift this test exists to catch in the app.
        $expectedTotal = bcadd('2999999999.97', bcmul('2999999999.97', '0.18', 10), 2);
        $this->assertSame($expectedTotal, number_format((float) $order->total_amount, 2, '.', ''));
        $this->assertBooksBalanced();
    }

    public function test_price_adjustment_rounding_is_exact_and_keeps_books_balanced(): void
    {
        config(['zurie.prices_include_vat' => true]);
        $product = $this->product(10000);

        $response = $this->postJson('/api/v1/orders', [
            'customerName' => 'Haggler', 'customerPhone' => '0'.random_int(700000000, 799999999),
            'items' => [['productId' => $product, 'quantity' => 3]],
        ])->assertCreated();
        $order = Order::where('order_number', $response->json('data.orderNumber'))->firstOrFail();

        $service = app(\App\Modules\Order\Services\OrderService::class);
        // A deliberately awkward new total (not a round number) to stress
        // the VAT-ratio split in adjustPrice().
        $adjusted = $service->adjustPrice($order, 27777.77, 'Odd negotiated price');

        $this->assertSame(27777.77, (float) $adjusted->total_amount);
        $this->assertBooksBalanced();
    }
}
