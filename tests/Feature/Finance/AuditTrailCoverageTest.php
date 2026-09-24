<?php

namespace Tests\Feature\Finance;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SalesOutletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every admin-managed record that affects prices, stock locations or
 * money leaves an audit entry naming who did it and what changed.
 */
class AuditTrailCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, CurrencySeeder::class, ChartOfAccountsSeeder::class, CostCenterSeeder::class, SalesOutletSeeder::class]);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret123']);
        $this->admin->roles()->sync([Role::where('name', 'admin')->value('id')]);
    }

    private function assertLogged(string $logName, string $descriptionPart): void
    {
        $entry = DB::table('activity_log')->where('log_name', $logName)->where('description', 'like', "%{$descriptionPart}%")->latest('id')->first();

        $this->assertNotNull($entry, "No '{$logName}' activity containing '{$descriptionPart}'");
        $this->assertSame($this->admin->id, (int) $entry->causer_id);
        $this->assertNotSame('[]', (string) $entry->attribute_changes, 'the changed fields should be recorded');
    }

    public function test_supplier_outlet_unit_currency_price_list_and_target_changes_are_logged(): void
    {
        $api = $this->actingAs($this->admin);

        $supplier = $api->postJson('/api/v1/admin/suppliers', ['name' => 'Gold Co'])->assertCreated()->json('data.id');
        $api->patchJson("/api/v1/admin/suppliers/{$supplier}", ['name' => 'Gold Co Ltd'])->assertOk();
        $this->assertLogged('supplier', "Supplier 'Gold Co Ltd' updated");

        $outlet = $api->postJson('/api/v1/admin/outlets', ['name' => 'Mlimani', 'type' => 'physical'])->assertCreated()->json('data.id');
        $api->patchJson("/api/v1/admin/outlets/{$outlet}", ['name' => 'Mlimani City'])->assertOk();
        $this->assertLogged('outlet', "Outlet 'Mlimani City' updated");

        $api->postJson('/api/v1/admin/measurement-units', ['name' => 'Piece', 'symbol' => 'pc'])->assertCreated();
        $this->assertLogged('measurement_unit', "Measurement unit 'Piece' created");

        $usd = $api->postJson('/api/v1/admin/currencies', ['code' => 'USD', 'name' => 'US Dollar', 'namePlural' => 'US dollars', 'symbol' => '$', 'symbolNative' => '$'])->assertCreated()->json('data.id');
        $this->assertLogged('currency', "Currency 'USD' created");
        $api->postJson("/api/v1/admin/currencies/{$usd}/exchange-rates", ['rateToBaseCurrency' => 2500])->assertSuccessful();
        $this->assertLogged('currency', "Exchange rate for currency #{$usd} created");

        $list = $api->postJson('/api/v1/admin/price-lists', ['name' => 'Wholesale'])->assertCreated()->json('data.id');
        $this->assertLogged('price_list', "Price list 'Wholesale' created");

        $api->postJson('/api/v1/admin/targets', ['period' => now()->format('Y-m'), 'targetAmount' => 1000000])->assertSuccessful();
        $this->assertLogged('target', 'Target #');

        $this->assertNotNull($list);
    }

    public function test_media_upload_is_logged(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $file = UploadedFile::fake()->createWithContent('logo.png', $png);

        $this->actingAs($this->admin)->post('/api/v1/media/upload', ['file' => $file, 'folder' => 'general'], ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertLogged('media', "Media 'logo.png' created");
    }
}
