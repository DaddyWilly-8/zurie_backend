<?php

namespace Tests\Feature\Order;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
 * Admin > Settings > Tax: the VAT rate and "prices include VAT" choice are
 * set by an admin, override the env/config defaults, and drive checkout.
 */
class TaxSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);
        config(['zurie.default_vat_percentage' => 18.0, 'zurie.prices_include_vat' => true]);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret123']);
        $this->admin->roles()->sync([Role::where('name', 'admin')->value('id')]);
    }

    public function test_defaults_come_from_config_until_saved(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/settings/tax')
            ->assertOk()
            ->assertJsonPath('data.vatPercentage', 18)
            ->assertJsonPath('data.pricesIncludeVat', true);
    }

    public function test_admin_saves_tax_settings_and_they_are_public_and_logged(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/admin/settings/tax', ['vatPercentage' => 10, 'pricesIncludeVat' => false])
            ->assertOk()
            ->assertJsonPath('data.vatPercentage', 10)
            ->assertJsonPath('data.pricesIncludeVat', false);

        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.tax.vatPercentage', 10)
            ->assertJsonPath('data.tax.pricesIncludeVat', false);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'settings', 'description' => 'Tax settings created', 'causer_id' => $this->admin->id]);
    }

    public function test_saved_settings_override_config_at_checkout(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/admin/settings/tax', ['vatPercentage' => 10, 'pricesIncludeVat' => false])->assertOk();

        $categoryId = DB::table('categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Ring', 'slug' => 'ring', 'price' => 45000, 'buying_price' => 20000,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(InventoryService::class)->provisionForProduct($productId);
        Inventory::where('product_id', $productId)->update(['quantity' => 5, 'stock_status' => 'IN_STOCK']);

        // config still says 18% inclusive; the saved 10% on-top must win.
        $this->postJson('/api/v1/orders', [
            'customerName' => 'Buyer', 'customerPhone' => '0711111111',
            'items' => [['productId' => $productId, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.vatAmount', 4500)
            ->assertJsonPath('data.totalAmount', 49500)
            ->assertJsonPath('data.pricesIncludeVat', false);
    }

    public function test_validation_and_permission(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/admin/settings/tax', ['vatPercentage' => 150, 'pricesIncludeVat' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vatPercentage', 'pricesIncludeVat']);

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'secret123']);
        $staff->roles()->sync([Role::where('name', 'staff')->value('id')]);
        $this->actingAs($staff)->putJson('/api/v1/admin/settings/tax', ['vatPercentage' => 0, 'pricesIncludeVat' => true])
            ->assertForbidden();
    }
}
