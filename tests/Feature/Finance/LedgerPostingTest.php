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

    /**
     * The 1-cent VAT-rounding tolerance must never let a real mismatch
     * reach ledgers.current_balance — found live: before this was fixed, a
     * 1-cent-off posting was accepted as-is and flipped balanceSheet()'s
     * isBalanced from true to false. The gap must be absorbed into the
     * entry itself (the short side's last line) so what's actually
     * written is always exactly balanced.
     */
    public function test_one_cent_gap_is_absorbed_not_silently_accepted(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');

        $entry = $this->finance->postEntry([
            ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 100.01],
            ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 100.00],
        ], narration: 'one-cent gap');

        $creditLine = $entry->lines->firstWhere('type', 'credit');
        $this->assertSame('100.01', $creditLine->amount, 'the short side must be topped up to match exactly');
        $this->assertSame(100.01, $this->balance('CASH'));
        $this->assertSame(100.01, $this->balance('SALES'));

        $bs = $this->finance->balanceSheet();
        $this->assertTrue($bs['isBalanced'], 'a tolerated 1-cent gap must never break Assets = Liabilities + Equity');
    }

    public function test_two_cent_gap_is_still_rejected(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');

        $this->expectException(UnbalancedJournalEntryException::class);

        try {
            $this->finance->postEntry([
                ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 100.02],
                ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 100.00],
            ], narration: 'two-cent gap');
        } finally {
            $this->assertSame(0.0, $this->balance('CASH'));
        }
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

    /**
     * Regression test for a real "Assets != Liabilities + Equity" bug:
     * balanceSheet() summed every ledger's stored current_balance
     * directly into its nature's total without correcting for
     * is_contra, so a contra-income ledger (Sales Discounts, stored
     * debit-positive — the opposite of a normal income ledger) was
     * added to net income instead of subtracted, unbalancing the sheet
     * by exactly 2x its balance. Neither existing test above would have
     * caught this: test_contra_ledger_flips_direction() never calls
     * balanceSheet(), and test_balance_sheet_balances_after_mixed_postings()
     * never posts to a contra ledger.
     */
    public function test_balance_sheet_balances_with_a_contra_ledger_posting(): void
    {
        $cash = $this->finance->systemLedger('CASH');
        $sales = $this->finance->systemLedger('SALES');
        $discounts = $this->finance->systemLedger('SALES-DISC'); // contra income

        $this->finance->postEntry([
            ['ledger_id' => $cash->id, 'type' => 'debit', 'amount' => 2000],
            ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => 2000],
        ], narration: 'sale');

        // A discount reduces what was received in cash but the full
        // sale was already recognized above — matches how
        // OrderService::postSaleToLedger() posts SALES-DISC in practice.
        $this->finance->postEntry([
            ['ledger_id' => $discounts->id, 'type' => 'debit', 'amount' => 300],
            ['ledger_id' => $cash->id, 'type' => 'credit', 'amount' => 300],
        ], narration: 'discount');

        $sheet = $this->finance->balanceSheet();

        $this->assertTrue($sheet['isBalanced']);
        $this->assertEqualsWithDelta(
            $sheet['assets']['total'],
            $sheet['liabilities']['total'] + $sheet['equity']['total'],
            0.01,
        );
        // Net income should reflect the discount actually reducing it:
        // 2000 sale - 300 discount = 1700, not 2300 (which is what
        // adding the contra ledger instead of subtracting it would give).
        $this->assertEqualsWithDelta(1700.0, $sheet['equity']['retainedEarnings'], 0.01);
    }
}
