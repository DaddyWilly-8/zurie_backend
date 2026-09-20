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

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Widget', 'slug' => 'widget',
            'price' => 1000, 'buying_price' => 600, 'vat_exempted' => true, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inventory = app(InventoryService::class);
        $inventory->provisionForProduct($this->productId);
        Inventory::where('product_id', $this->productId)->update(['quantity' => 10, 'stock_status' => 'IN_STOCK']);
    }

    private function payload(int $quantity = 2): array
    {
        return [
            'customerName' => 'Test Buyer',
            'customerPhone' => '0700000001',
            'items' => [['productId' => $this->productId, 'quantity' => $quantity]],
        ];
    }

    private function stock(): int
    {
        return (int) Inventory::where('product_id', $this->productId)->sum('quantity');
    }

    public function test_checkout_decrements_stock_and_posts_balanced_ledger(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(2))->assertCreated();

        $this->assertSame(8, $this->stock());
        $finance = app(FinanceService::class);
        $this->assertSame(2000.0, (float) $finance->systemLedger('SALES')->current_balance);
        $this->assertSame(1200.0, (float) $finance->systemLedger('COGS')->current_balance);
        $this->assertSame([], $finance->reconcileLedgers());
    }

    public function test_insufficient_stock_is_rejected_and_rolls_everything_back(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(11))->assertStatus(422);

        $this->assertSame(10, $this->stock());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_same_idempotency_key_creates_only_one_order(): void
    {
        $headers = ['Idempotency-Key' => 'checkout-abc-123456'];

        $first = $this->postJson('/api/v1/orders', $this->payload(2), $headers)->assertCreated();
        $second = $this->postJson('/api/v1/orders', $this->payload(2), $headers)->assertCreated();

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.orderNumber'), $second->json('data.orderNumber'));
        $this->assertSame(1, Order::count());
        $this->assertSame(8, $this->stock());
    }

    public function test_reusing_a_key_with_a_different_body_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'checkout-xyz-987654'];

        $this->postJson('/api/v1/orders', $this->payload(1), $headers)->assertCreated();
        $this->postJson('/api/v1/orders', $this->payload(3), $headers)->assertStatus(422);

        $this->assertSame(1, Order::count());
    }

    public function test_failed_request_releases_the_key_so_a_corrected_retry_works(): void
    {
        $headers = ['Idempotency-Key' => 'checkout-retry-55555'];

        $this->postJson('/api/v1/orders', $this->payload(99), $headers)->assertStatus(422);
        $this->postJson('/api/v1/orders', $this->payload(1), $headers)->assertCreated();

        $this->assertSame(1, Order::count());
    }

    public function test_requests_without_a_key_are_unaffected(): void
    {
        $this->postJson('/api/v1/orders', $this->payload(1))->assertCreated();
        $this->postJson('/api/v1/orders', $this->payload(1))->assertCreated();

        $this->assertSame(2, Order::count());
    }
}
