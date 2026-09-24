<?php

namespace Tests\Feature\Coupon;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Services\CouponService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A usage-limited coupon must never be redeemed past its max_uses. The
 * checkout path locks the coupon row so concurrent orders can't all read
 * the same used_count (a load test redeemed a 5-use coupon 13 times before
 * validate() took the lock). This proves the count is enforced and that
 * validate(forUpdate:true) issues a locking read while preview does not.
 */
class CouponRedemptionTest extends TestCase
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
        app(InventoryService::class)->provisionForProduct($this->productId);
        Inventory::where('product_id', $this->productId)->update(['quantity' => 100, 'stock_status' => 'IN_STOCK']);

        Coupon::create(['code' => 'FIVE', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'max_uses' => 3, 'used_count' => 0]);
    }

    private function checkoutWithCoupon(): int
    {
        return $this->postJson('/api/v1/orders', [
            'customerName' => 'Buyer', 'customerPhone' => '0700000001',
            'items' => [['productId' => $this->productId, 'quantity' => 1]],
            'couponCode' => 'FIVE',
        ])->status();
    }

    public function test_coupon_stops_applying_once_max_uses_is_reached(): void
    {
        $this->assertSame(201, $this->checkoutWithCoupon());
        $this->assertSame(201, $this->checkoutWithCoupon());
        $this->assertSame(201, $this->checkoutWithCoupon());

        $this->assertSame(3, (int) Coupon::where('code', 'FIVE')->value('used_count'));

        // 4th attempt: the code is now spent, so checkout rejects it.
        $this->assertSame(422, $this->checkoutWithCoupon());
        $this->assertSame(3, (int) Coupon::where('code', 'FIVE')->value('used_count'));
        $this->assertSame(3, DB::table('orders')->whereNotNull('coupon_id')->count());
    }

    public function test_checkout_validation_uses_a_locking_read(): void
    {
        DB::enableQueryLog();
        DB::transaction(function () {
            app(CouponService::class)->validate('FIVE', 1000, forUpdate: true);
        });
        $ran = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
        $this->assertStringContainsString('for update', strtolower($ran));
    }

    public function test_preview_does_not_lock(): void
    {
        DB::enableQueryLog();
        app(CouponService::class)->validate('FIVE', 1000);
        $ran = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
        $this->assertStringNotContainsString('for update', strtolower($ran));
    }
}
