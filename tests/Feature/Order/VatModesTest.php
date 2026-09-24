<?php

namespace Tests\Feature\Order;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderService;
use App\Modules\Report\Services\ReportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prices can include VAT (config zurie.prices_include_vat = true) or have
 * it added on top (false). In both modes: VAT is computed after any coupon
 * discount, exempt products carry none, the order total is exactly what
 * the customer is booked as owing/paying, and cancelling reverses every
 * ledger back to zero — even if the mode changed in between.
 */
class VatModesTest extends TestCase
{
    use RefreshDatabase;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);
        config(['zurie.default_vat_percentage' => 18.0]);
        $this->categoryId = DB::table('categories')->insertGetId(['name' => 'Rings', 'slug' => 'rings', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(float $price, bool $exempt = false, float $cost = 20000): int
    {
        $id = DB::table('products')->insertGetId([
            'category_id' => $this->categoryId, 'name' => 'P'.uniqid(), 'slug' => 'p'.uniqid(),
            'price' => $price, 'buying_price' => $cost, 'vat_exempted' => $exempt, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($id);
        Inventory::where('product_id', $id)->update(['quantity' => 50, 'stock_status' => 'IN_STOCK']);

        return $id;
    }

    private function coupon(float $percent): string
    {
        DB::table('coupons')->insert([
            'code' => 'OFF'.(int) $percent, 'type' => 'percentage', 'value' => $percent,
            'is_active' => true, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'OFF'.(int) $percent;
    }

    /** @param array<int, array{0: int, 1: int}> $lines productId, quantity */
    private function checkout(array $lines, ?string $coupon = null): Order
    {
        $payload = [
            'customerName' => 'Buyer', 'customerPhone' => '07'.random_int(10000000, 99999999),
            'items' => array_map(fn ($l) => ['productId' => $l[0], 'quantity' => $l[1]], $lines),
        ];
        if ($coupon !== null) {
            $payload['couponCode'] = $coupon;
        }
        $number = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('data.orderNumber');

        return Order::where('order_number', $number)->firstOrFail();
    }

    private function balance(string $code): float
    {
        return (float) app(FinanceService::class)->systemLedger($code)->current_balance;
    }

    private function assertBooksBalanced(): void
    {
        $this->assertSame([], app(FinanceService::class)->reconcileLedgers());
    }

    public function test_vat_inclusive_prices_keep_the_total_and_extract_vat(): void
    {
        config(['zurie.prices_include_vat' => true]);
        $order = $this->checkout([[$this->product(45000), 2]]);

        $this->assertSame(90000.0, (float) $order->total_amount);
        $this->assertSame(13728.81, (float) $order->vat_amount); // 90,000 x 18/118
        $this->assertTrue($order->prices_include_vat);
        $this->assertSame(90000.0, $this->balance('AR'), 'customer owes exactly the order total');
        $this->assertSame(76271.19, $this->balance('SALES'));
        $this->assertSame(13728.81, $this->balance('VAT-OUT'));
        $this->assertBooksBalanced();
    }

    public function test_vat_exclusive_prices_add_vat_on_top(): void
    {
        config(['zurie.prices_include_vat' => false]);
        $order = $this->checkout([[$this->product(45000), 2]]);

        $this->assertSame(106200.0, (float) $order->total_amount);
        $this->assertSame(16200.0, (float) $order->vat_amount);
        $this->assertFalse($order->prices_include_vat);
        $this->assertSame(106200.0, $this->balance('AR'));
        $this->assertSame(90000.0, $this->balance('SALES'));
        $this->assertSame(16200.0, $this->balance('VAT-OUT'));
        $this->assertBooksBalanced();
    }

    public function test_vat_is_charged_on_the_discounted_price_in_both_modes(): void
    {
        $product = $this->product(45000);
        $coupon = $this->coupon(10);

        config(['zurie.prices_include_vat' => true]);
        $inclusive = $this->checkout([[$product, 1]], $coupon);
        $this->assertSame(40500.0, (float) $inclusive->total_amount);
        $this->assertSame(6177.97, (float) $inclusive->vat_amount); // 40,500 x 18/118

        config(['zurie.prices_include_vat' => false]);
        $exclusive = $this->checkout([[$product, 1]], $coupon);
        $this->assertSame(7290.0, (float) $exclusive->vat_amount); // 40,500 x 18%
        $this->assertSame(47790.0, (float) $exclusive->total_amount);

        $this->assertSame(40500.0 + 47790.0, $this->balance('AR'));
        $this->assertBooksBalanced();
    }

    public function test_exempt_products_carry_no_vat_in_either_mode(): void
    {
        $exempt = $this->product(45000, exempt: true);

        foreach ([true, false] as $mode) {
            config(['zurie.prices_include_vat' => $mode]);
            $order = $this->checkout([[$exempt, 1]]);
            $this->assertSame(0.0, (float) $order->vat_amount);
            $this->assertSame(45000.0, (float) $order->total_amount);
        }
        $this->assertSame(0.0, $this->balance('VAT-OUT'));
        $this->assertSame(90000.0, $this->balance('SALES'));
        $this->assertBooksBalanced();
    }

    public function test_mixed_cart_shares_the_discount_and_only_taxes_the_taxable_part(): void
    {
        config(['zurie.prices_include_vat' => false]);
        $taxable = $this->product(30000);
        $exempt = $this->product(10000, exempt: true);

        // 40,000 gross, 10% off = 4,000 shared 3,000 / 1,000.
        // VAT only on the taxable 27,000 => 4,860.
        $order = $this->checkout([[$taxable, 1], [$exempt, 1]], $this->coupon(10));

        $this->assertSame(4860.0, (float) $order->vat_amount);
        $this->assertSame(36000.0 + 4860.0, (float) $order->total_amount);
        $this->assertSame([4860.0, 0.0], $order->items()->orderBy('id')->pluck('vat_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertBooksBalanced();
    }

    public function test_cancel_reverses_everything_even_if_the_mode_changed_since(): void
    {
        $product = $this->product(45000);
        $coupon = $this->coupon(10);

        config(['zurie.prices_include_vat' => true]);
        $inclusive = $this->checkout([[$product, 2]], $coupon);
        config(['zurie.prices_include_vat' => false]);
        $exclusive = $this->checkout([[$product, 1]]);

        // Flip the mode again before cancelling: each order must be
        // reversed the way it was sold, not the way config reads now.
        config(['zurie.prices_include_vat' => true]);
        app(OrderService::class)->cancel($exclusive);
        config(['zurie.prices_include_vat' => false]);
        app(OrderService::class)->cancel($inclusive->fresh());

        foreach (['AR', 'SALES', 'SALES-DISC', 'VAT-OUT', 'COGS', 'INV-ASSET'] as $code) {
            $this->assertEqualsWithDelta(0.0, $this->balance($code), 0.001, "{$code} should net to zero after both cancellations");
        }
        $this->assertBooksBalanced();
    }

    public function test_pos_cash_matches_the_sale_total_and_is_not_a_debt(): void
    {
        config(['zurie.prices_include_vat' => true]);
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $cashier = User::create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'password' => 'secret123']);
        $cashier->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $product = $this->product(45000);
        $shop = DB::table('sales_outlets')->insertGetId([
            'name' => 'Shop', 'type' => 'physical', 'cost_center_id' => DB::table('cost_centers')->value('id'),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($product, $shop);
        Inventory::where('product_id', $product)->where('sales_outlet_id', $shop)->update(['quantity' => 5, 'stock_status' => 'IN_STOCK']);

        $response = $this->actingAs($cashier)->postJson('/api/v1/admin/pos/sale', [
            'outletId' => $shop, 'customerName' => 'Walk-in', 'customerPhone' => '0755555555',
            'items' => [['productId' => $product, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(45000.0, (float) $response->json('data.totalAmount'));
        $this->assertSame(45000.0, $this->balance('CASH'), 'the till receives exactly the sale total');

        // A website order stays a debt until a receipt is applied.
        $this->checkout([[$product, 1]]);
        Cache::flush();
        $debtors = collect(app(ReportService::class)->debtors());
        $this->assertCount(1, $debtors);
        $this->assertNotSame('Walk-in', $debtors->first()['name']);
        $this->assertBooksBalanced();
    }

    public function test_super_admin_is_seeded_with_every_permission(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $total = DB::table('permissions')->count();

        $this->assertGreaterThan(0, $total);
        $this->assertSame($total, Role::where('name', 'super_admin')->firstOrFail()->permissions()->count());
        $this->assertSame($total, Role::where('name', 'admin')->firstOrFail()->permissions()->count());
    }
}
