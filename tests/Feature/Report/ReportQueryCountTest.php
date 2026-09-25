<?php

namespace Tests\Feature\Report;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Report\Services\ReportService;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the N+1 in
 * ReportService::attachStakeholderNames() — it used to call
 * StakeholderService::findOrFail() once per row inside array_map(), so a
 * creditors()/debtors() report listing N stakeholders ran N+few queries
 * instead of a fixed handful. Asserts the query count stays flat as the
 * row count grows, not just that the report still returns correct data
 * (which it did even with the N+1 — this test exists specifically to
 * catch the query count regressing again, not a correctness bug).
 */
class ReportQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class]);
    }

    public function test_creditors_report_query_count_does_not_scale_with_supplier_count(): void
    {
        $supplierService = app(SupplierService::class);
        $financeService = app(FinanceService::class);

        $suppliers = [];
        for ($i = 0; $i < 15; $i++) {
            $suppliers[] = $supplierService->create(['name' => "QA Supplier {$i}"]);
        }

        // Give each a real outstanding payable balance the way a GRN
        // would (Dr Inventory, Cr Supplier payable) — a single balanced
        // entry per supplier, posted through FinanceService like any real
        // write, not a raw balance update.
        $inventory = $financeService->systemLedger('INV-ASSET');
        foreach ($suppliers as $supplier) {
            $payable = $financeService->ledgerFor($supplier);
            $financeService->postEntry([
                ['ledger_id' => $inventory->id, 'type' => 'debit', 'amount' => 1000],
                ['ledger_id' => $payable->id, 'type' => 'credit', 'amount' => 1000],
            ], 'Test GRN', 'qa-test', $supplier->id);
        }

        Cache::forget('report:creditors');
        DB::enableQueryLog();
        $rows = app(ReportService::class)->creditors();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->assertCount(15, $rows);

        // A handful of fixed queries (payable balances, batched name
        // lookup, cache write) — never anywhere close to one per
        // supplier. Before the fix this was ~15+ queries for 15 rows.
        $this->assertLessThan(10, $queryCount, "creditors() ran {$queryCount} queries for 15 suppliers — looks like the N+1 is back.");
    }
}
