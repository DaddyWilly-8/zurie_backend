<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Services\FinanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class]);
        $this->finance = app(FinanceService::class);

        $this->finance->postEntry([
            ['ledger_id' => $this->finance->systemLedger('CASH')->id, 'type' => 'debit', 'amount' => 700],
            ['ledger_id' => $this->finance->systemLedger('SALES')->id, 'type' => 'credit', 'amount' => 700],
        ], narration: 'sale');
    }

    public function test_clean_books_report_no_drift(): void
    {
        $this->assertSame([], $this->finance->reconcileLedgers());
        $this->artisan('finance:reconcile')->assertSuccessful();
    }

    public function test_tampered_balance_is_detected_and_command_fails(): void
    {
        Ledger::where('code', 'CASH')->update(['current_balance' => 5]);

        $drift = $this->finance->reconcileLedgers();

        $this->assertCount(1, $drift);
        $this->assertSame('CASH', $drift[0]['code']);
        $this->assertSame(700.0, $drift[0]['expected']);
        $this->artisan('finance:reconcile')->assertFailed();
    }

    public function test_fix_restores_the_recomputed_balance(): void
    {
        Ledger::where('code', 'CASH')->update(['current_balance' => 5]);

        $this->artisan('finance:reconcile --fix')->assertSuccessful();

        $this->assertSame(700.0, (float) Ledger::where('code', 'CASH')->value('current_balance'));
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_line_amounts_are_rounded_to_cents_before_balance_check(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $sales = $this->finance->systemLedger('SALES')->id;

        // 33.3349 and 33.33 both store as 33.33 — balanced once rounded.
        $entry = $this->finance->postEntry([
            ['ledger_id' => $cash, 'type' => 'debit', 'amount' => 33.3349],
            ['ledger_id' => $sales, 'type' => 'credit', 'amount' => 33.33],
        ], narration: 'rounding');

        $this->assertSame('33.33', $entry->lines->firstWhere('type', 'debit')->amount);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }
}
