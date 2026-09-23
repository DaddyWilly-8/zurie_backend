<?php

namespace Tests\Feature\Transaction;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Transaction\Services\TransactionService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
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

    public function test_payment_debits_the_expense_and_credits_the_paying_ledger(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $expense = $this->finance->systemLedger('INV-WRITEOFF')->id; // any expense ledger

        $payment = $this->transactions->createPayment([
            'creditLedgerId' => $cash,
            'items' => [['debitLedgerId' => $expense, 'amount' => 250]],
        ]);

        $this->assertSame(250.0, (float) $payment->total_amount);
        $this->assertSame(250.0, (float) $this->finance->systemLedger('INV-WRITEOFF')->fresh()->current_balance);
        $this->assertSame(-250.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'transaction',
            'event' => 'created',
            'subject_id' => $payment->id,
            'subject_type' => \App\Modules\Transaction\Models\Payment::class,
        ]);
    }

    public function test_payment_with_no_items_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->transactions->createPayment(['creditLedgerId' => $this->finance->systemLedger('CASH')->id, 'items' => []]);
    }

    public function test_deleting_a_payment_reverses_its_ledger_effect(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $expense = $this->finance->systemLedger('INV-WRITEOFF')->id;

        $payment = $this->transactions->createPayment([
            'creditLedgerId' => $cash,
            'items' => [['debitLedgerId' => $expense, 'amount' => 100]],
        ]);

        $paymentNumber = $payment->payment_number;
        $this->transactions->deletePayment($payment->fresh());

        $this->assertSame(0.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->systemLedger('INV-WRITEOFF')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'transaction',
            'event' => 'deleted',
            'description' => "Payment {$paymentNumber} deleted, ledger effect reversed",
        ]);
    }

    public function test_receipt_debits_the_receiving_ledger_and_credits_income(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $sales = $this->finance->systemLedger('SALES')->id;

        $receipt = $this->transactions->createReceipt([
            'debitLedgerId' => $cash,
            'items' => [['creditLedgerId' => $sales, 'amount' => 400]],
        ]);

        $this->assertSame(400.0, (float) $receipt->total_amount);
        $this->assertSame(400.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame(400.0, (float) $this->finance->systemLedger('SALES')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }

    public function test_receipt_linked_to_a_sale_cannot_be_deleted(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $sales = $this->finance->systemLedger('SALES')->id;

        $receipt = $this->transactions->createReceipt([
            'debitLedgerId' => $cash,
            'items' => [['creditLedgerId' => $sales, 'amount' => 100]],
            'sales' => [['orderId' => 999, 'amountApplied' => 100]],
        ]);

        $this->expectException(ValidationException::class);

        $this->transactions->deleteReceipt($receipt->fresh());
    }

    public function test_receipt_without_a_sale_link_can_be_deleted_and_reverses_ledgers(): void
    {
        $cash = $this->finance->systemLedger('CASH')->id;
        $sales = $this->finance->systemLedger('SALES')->id;

        $receipt = $this->transactions->createReceipt([
            'debitLedgerId' => $cash,
            'items' => [['creditLedgerId' => $sales, 'amount' => 150]],
        ]);

        $this->transactions->deleteReceipt($receipt->fresh());

        $this->assertSame(0.0, (float) $this->finance->systemLedger('CASH')->fresh()->current_balance);
        $this->assertSame(0.0, (float) $this->finance->systemLedger('SALES')->fresh()->current_balance);
        $this->assertSame([], $this->finance->reconcileLedgers());
    }
}
