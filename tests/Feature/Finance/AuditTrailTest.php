<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\CostCenter;
use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Models\LedgerGroup;
use App\Modules\Finance\Services\CostCenterService;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Finance\Services\LedgerGroupService;
use App\Modules\Finance\Services\LedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms the audit gap flagged during the pending-work review is closed:
 * previously only Auth events were logged, while every money-structure
 * change (ledger groups, ledgers, cost centers) and the nightly
 * reconcile --fix correction went completely unlogged.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class]);
    }

    public function test_ledger_group_crud_is_logged(): void
    {
        $group = app(LedgerGroupService::class)->create(['name' => 'Test Group', 'code' => 'TSTGRP', 'nature' => 'expense']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'created', 'subject_id' => $group->id, 'subject_type' => LedgerGroup::class]);

        app(LedgerGroupService::class)->update($group, ['name' => 'Renamed Group']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'updated', 'subject_id' => $group->id, 'subject_type' => LedgerGroup::class]);

        app(LedgerGroupService::class)->delete($group->fresh());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'deleted', 'description' => "Ledger group 'Renamed Group' (TSTGRP) deleted"]);
    }

    public function test_ledger_crud_is_logged(): void
    {
        $group = app(LedgerGroupService::class)->create(['name' => 'Group', 'code' => 'TSTGRP2', 'nature' => 'expense']);
        $ledger = app(LedgerService::class)->create(['ledgerGroupId' => $group->id, 'name' => 'Test Ledger', 'code' => 'TSTLDG']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'created', 'subject_id' => $ledger->id, 'subject_type' => Ledger::class]);

        app(LedgerService::class)->update($ledger, ['name' => 'Renamed Ledger']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'updated', 'subject_id' => $ledger->id, 'subject_type' => Ledger::class]);

        app(LedgerService::class)->delete($ledger->fresh());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'deleted', 'description' => "Ledger 'Renamed Ledger' (TSTLDG) deleted"]);
    }

    public function test_cost_center_crud_is_logged(): void
    {
        $costCenter = app(CostCenterService::class)->create(['name' => 'Test CC']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'created', 'subject_id' => $costCenter->id, 'subject_type' => CostCenter::class]);

        app(CostCenterService::class)->setActive($costCenter, false);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'updated', 'description' => "Cost center 'Test CC' deactivated"]);
    }

    public function test_reconcile_fix_is_logged_with_the_before_after_values(): void
    {
        $finance = app(FinanceService::class);
        Ledger::where('code', 'CASH')->update(['current_balance' => 999]);

        $this->artisan('finance:reconcile --fix')->assertSuccessful();

        $this->assertDatabaseHas('activity_log', ['log_name' => 'finance', 'event' => 'reconciled']);
        $this->assertSame([], $finance->reconcileLedgers());
    }

    public function test_reconcile_without_fix_does_not_log_anything(): void
    {
        Ledger::where('code', 'CASH')->update(['current_balance' => 999]);

        $this->artisan('finance:reconcile')->assertFailed();

        $this->assertDatabaseMissing('activity_log', ['log_name' => 'finance', 'event' => 'reconciled']);
    }
}
