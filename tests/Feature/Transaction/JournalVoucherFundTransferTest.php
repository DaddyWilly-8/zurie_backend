<?php

namespace Tests\Feature\Transaction;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Transaction\Services\TransactionService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalVoucherFundTransferTest extends TestCase
{
    use RefreshDatabase;

    private FinanceService $finance;
    private TransactionService $transactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CurrencySeeder::class, ChartOfAccountsSeeder::class]);
        $this->finance = app(FinanceService::class);
        $this->transactions = app(TransactionService::class);
    }

    public function test_journal_voucher_posts_each_line_and_reverses_cleanly_on_delete(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $bank = $this->finance->systemLedger('BANK')->id;

        $voucher = $this->transactions->createJournalVoucher([
            'items' => [['debitLedgerId' => $bank, 'creditLedgerId' => $cash, 'amount' => 300]],
        ]);

        $this->assertSame(300.0, (float) $this->finance->systemLedger('BANK')->fresh()->current_balance);
        $this->assertSame(-300.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());

        $this->transactions->deleteJournalVoucher($voucher->fresh());

        $this->assertSame(0.0, (float) $this->finance->systemLedger('BANK')->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_journal_voucher_line_cannot_debit_and_credit_the_same_ledger(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;

        $this->expectException(ValidationException::class);

        $this->transactions->createJournalVoucher([
            'items' => [['debitLedgerId' => $cash, 'creditLedgerId' => $cash, 'amount' => 10]],
        ]);
    }

    public function test_fund_transfer_moves_money_between_two_of_our_own_ledgers(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $bank = $this->finance->systemLedger('BANK')->id;
        $momo = $this->finance->systemLedger('MOMO')->id;

        $transfer = $this->transactions->createFundTransfer([
            'creditLedgerId' => $cash,
            'items' => [['debitLedgerId' => $bank, 'amount' => 200], ['debitLedgerId' => $momo, 'amount' => 50]],
        ]);

        $this->assertSame(250.0, (float) $transfer->total_amount);
        $this->assertSame(-250.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame(200.0, (float) $this->finance->systemLedger('BANK')->fresh()->current_balance);
        $this->assertSame(50.0, (float) $this->finance->systemLedger('MOMO')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_deleting_a_fund_transfer_reverses_every_line(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $bank = $this->finance->systemLedger('BANK')->id;

        $transfer = $this->transactions->createFundTransfer([
            'creditLedgerId' => $cash,
            'items' => [['debitLedgerId' => $bank, 'amount' => 80]],
        ]);

        $this->transactions->deleteFundTransfer($transfer->fresh());

        $this->assertSame(0.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->systemLedger('BANK')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
        $this->assertDatabaseCount('fund_transfers', 0);
    }
}
