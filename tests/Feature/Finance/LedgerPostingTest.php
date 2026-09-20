<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Finance\Services\FinanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class]);
        $this->finance = app(FinanceService::class);
    }

    private function balance(string $code): float
    {
        return (float) $this->finance->systemLedger($code)->fresh()->current_balance;
    }

    public function test_balanced_entry_moves_each_ledger_in_its_own_normal_direction(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');

        $this->finance->postEntry([
            ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 1000],
            ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 1000],
        ], narration: 'test sale');

        // asset debit => +, income credit => + (own-direction convention)
        $this->assertSame(1000.0, $this->balance('CASH'));
        $this->assertSame(1000.0, $this->balance('SALES'));
    }

    public function test_unbalanced_entry_is_rejected_and_writes_nothing(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');

        $this->expectException(UnbalancedJournalEntryException::class);

        try {
            $this->finance->postEntry([
                ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 1000],
                ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 900],
            ], narration: 'bad');
        } finally {
            $this->assertSame(0.0, $this->balance('CASH'));
            $this->assertDatabaseCount('journal_entries', 0);
        }
    }

    public function test_delete_entry_restores_balances(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');

        $entry = $this->finance->postEntry([
            ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 500],
            ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 500],
        ], narration: 'to delete');

        $this->finance->deleteEntry($entry);

        $this->assertSame(0.0, $this->balance('CASH'));
        $this->assertSame(0.0, $this->balance('SALES'));
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_contra_ledger_flips_direction(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $discounts = $this->finance->systemLedger('SALES-DISC'); // contra income

        $this->finance->postEntry([
            ['ledger_id' => $discounts->id, 'type' => 'debit', 'amount' => 100],
            ['ledger_id' => $cash->id, 'type' => 'credit', 'amount' => 100],
        ], narration: 'discount');

        $this->assertSame(100.0, $this->balance('SALES-DISC'));
    }

    public function test_balance_sheet_balances_after_mixed_postings(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');
        $cogs = $this->finance->systemLedger('COGS');
        $inv = $this->finance->systemLedger('INV-ASSET');

        $this->finance->postEntry([
            ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 2000],
            ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 2000],
        ], narration: 'sale');
        $this->finance->postEntry([
            ['ledger_id' => $cogs->id, 'type' => 'debit', 'amount' => 800],
            ['ledger_id' => $inv->id, 'type' => 'credit', 'amount' => 800],
        ], narration: 'cost');

        $sheet = $this->finance->balanceSheet();

        $this->assertTrue($sheet['isBalanced']);
        $this->assertEqualsWithDelta(
            $sheet['assets']['total'],
            $sheet['liabilities']['total'] + $sheet['equity']['total'],
            0.01,
        );
    }
}
