<?php

namespace Tests\Feature\Security;

use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Registering with a phone number that was used for guest checkouts links
 * the new account to that customer record, but must not hand over the
 * guest's earlier orders (names, contact details, items) to whoever knows
 * the phone number. Earlier orders show only when they were placed with
 * the same email as the account.
 */
class GuestHistoryClaimTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        // Same-site storefront requests, so the session (and login) sticks.
        $this->withHeader('Origin', 'http://localhost');
        $this->seed([PermissionSeeder::class, RoleSeeder::class, CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $this->productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Ring', 'slug' => 'ring', 'price' => 1000, 'buying_price' => 500,
            'vat_exempted' => true, 'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($this->productId);
        Inventory::where('product_id', $this->productId)->update(['quantity' => 10, 'stock_status' => 'IN_STOCK']);
    }

    private function guestCheckout(string $email): string
    {
        return $this->postJson('/api/v1/orders', [
            'customerName' => 'Neema Guest', 'customerPhone' => '0712345678', 'customerEmail' => $email,
            'items' => [['productId' => $this->productId, 'quantity' => 1]],
        ])->assertCreated()->json('data.orderNumber');
    }

    private function register(string $email): void
    {
        $this->travel(10)->minutes();
        $this->postJson('/api/v1/customer/auth/register', [
            'name' => 'Someone', 'email' => $email, 'phone' => '0712345678',
            'password' => 'Secret#2026', 'password_confirmation' => 'Secret#2026', 'passwordConfirmation' => 'Secret#2026',
        ])->assertSuccessful();
    }

    public function test_registering_with_someone_elses_phone_does_not_reveal_their_orders(): void
    {
        $this->guestCheckout('neema@example.com');

        $this->register('someone-else@example.com');

        $this->getJson('/api/v1/account/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_real_owner_sees_their_guest_history_after_registering(): void
    {
        $number = $this->guestCheckout('Neema@Example.com');

        $this->register('neema@example.com');

        $this->getJson('/api/v1/account/orders')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.orderNumber', $number);
    }

    public function test_orders_placed_after_registering_always_show(): void
    {
        $this->guestCheckout('neema@example.com');
        $this->register('someone-else@example.com');

        $number = $this->postJson('/api/v1/orders', [
            'customerName' => 'Someone', 'customerPhone' => '0799999999',
            'items' => [['productId' => $this->productId, 'quantity' => 1]],
        ])->assertCreated()->json('data.orderNumber');

        $this->getJson('/api/v1/account/orders')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.orderNumber', $number);
    }
}
