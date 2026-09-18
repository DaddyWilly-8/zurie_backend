<?php

namespace App\Modules\Transaction\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Transaction\Models\FundTransfer;
use App\Modules\Transaction\Models\JournalVoucher;
use App\Modules\Transaction\Models\Payment;
use App\Modules\Transaction\Models\Receipt;
use App\Modules\Transaction\Models\ReceiptOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All four Transaction subtypes (Payment, Receipt, Journal Voucher, Fund
 * Transfer) in one service — they share the exact "header + items[], each
 * item posts one JournalEntry" shape (see FinanceService::postSimpleEntry()),
 * differing only in which ledger is fixed on the header vs. supplied per
 * item. Kept as one class rather than four near-duplicates so the shared
 * numbering/deletion/reversal logic below isn't copy-pasted four times —
 * consistent with the reference doc's own observation that ProsERP
 * duplicating this per-controller was a mistake worth not repeating.
 */
class TransactionService
{
    public function __construct(private readonly FinanceService $financeService) {}

    private static function generateNumber(string $prefix, int $id): string
    {
        return "{$prefix}-" . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------------
    // Payment — credit_ledger_id fixed on the header (money leaving that
    // ledger, e.g. Cash/Bank); each item supplies its own debit_ledger_id
    // (e.g. an expense category, a supplier's payable ledger) + amount.
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  transactionDate?, reference?, narration?, creditLedgerId, items: array<{debitLedgerId, amount}>
     */
    public function createPayment(array $data): Payment
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A payment needs at least one item.']);
        }

        return DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'transaction_date' => $data['transactionDate'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'credit_ledger_id' => $data['creditLedgerId'],
                'total_amount' => 0,
            ]);
            $payment->update(['payment_number' => self::generateNumber('PMT', $payment->id)]);

            $total = 0.0;
            foreach ($data['items'] as $line) {
                $amount = (float) $line['amount'];
                $total += $amount;

                $entry = $this->financeService->postSimpleEntry(
                    debitLedgerId: $line['debitLedgerId'],
                    creditLedgerId: $payment->credit_ledger_id,
                    amount: $amount,
                    narration: $payment->narration ?? "Payment {$payment->payment_number}",
                    referenceType: Payment::class,
                    referenceId: $payment->id,
                );

                $payment->items()->create([
                    'debit_ledger_id' => $line['debitLedgerId'],
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            $payment->update(['total_amount' => $total]);

            return $payment->load('items');
        });
    }

    public function paginatePayments(int $page, int $pageSize): LengthAwarePaginator
    {
        return Payment::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findPayment(int $id): Payment
    {
        return Payment::query()->with('items')->findOrFail($id);
    }

    /** Always deletes its journals first, unconditionally — see FinanceService::deleteEntry()'s docblock. */
    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $journalEntryIds = $payment->items->pluck('journal_entry_id')->all();
            $payment->delete();
            foreach ($journalEntryIds as $journalEntryId) {
                $this->financeService->deleteEntry(JournalEntry::findOrFail($journalEntryId));
            }
        });
    }

    // ---------------------------------------------------------------
    // Receipt — debit_ledger_id fixed on the header (money arriving into
    // that ledger, e.g. Cash/Bank); each item supplies credit_ledger_id
    // (e.g. Accounts Receivable being settled) + amount. `sales` optionally
    // links this receipt to one or more Orders' AR balance.
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  transactionDate?, reference?, narration?, debitLedgerId, items: array<{creditLedgerId, amount}>, sales?: array<{orderId, amountApplied}>
     */
    public function createReceipt(array $data): Receipt
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A receipt needs at least one item.']);
        }

        return DB::transaction(function () use ($data) {
            $receipt = Receipt::create([
                'transaction_date' => $data['transactionDate'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'debit_ledger_id' => $data['debitLedgerId'],
                'total_amount' => 0,
            ]);
            $receipt->update(['receipt_number' => self::generateNumber('RCT', $receipt->id)]);

            $total = 0.0;
            foreach ($data['items'] as $line) {
                $amount = (float) $line['amount'];
                $total += $amount;

                $entry = $this->financeService->postSimpleEntry(
                    debitLedgerId: $receipt->debit_ledger_id,
                    creditLedgerId: $line['creditLedgerId'],
                    amount: $amount,
                    narration: $receipt->narration ?? "Receipt {$receipt->receipt_number}",
                    referenceType: Receipt::class,
                    referenceId: $receipt->id,
                );

                $receipt->items()->create([
                    'credit_ledger_id' => $line['creditLedgerId'],
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            foreach ($data['sales'] ?? [] as $sale) {
                ReceiptOrder::create([
                    'receipt_id' => $receipt->id,
                    'order_id' => $sale['orderId'],
                    'amount_applied' => $sale['amountApplied'],
                ]);
            }

            $receipt->update(['total_amount' => $total]);

            return $receipt->load('items', 'sales');
        });
    }

    public function paginateReceipts(int $page, int $pageSize): LengthAwarePaginator
    {
        return Receipt::query()->with(['items', 'sales'])->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findReceipt(int $id): Receipt
    {
        return Receipt::query()->with(['items', 'sales'])->findOrFail($id);
    }

    /**
     * Deletable check enforced server-side (the reference doc flags this
     * as only a frontend-facing accessor in ProsERP, never enforced on
     * the server — a real gap this codebase doesn't repeat, consistent
     * with never trusting the client to self-police, see CLAUDE.md's
     * "Authorization" section).
     */
    public function deleteReceipt(Receipt $receipt): void
    {
        if ($receipt->sales()->exists()) {
            throw ValidationException::withMessages(['receipt' => 'This receipt is linked to one or more sales and cannot be deleted.']);
        }

        DB::transaction(function () use ($receipt) {
            $journalEntryIds = $receipt->items->pluck('journal_entry_id')->all();
            $receipt->delete();
            foreach ($journalEntryIds as $journalEntryId) {
                $this->financeService->deleteEntry(JournalEntry::findOrFail($journalEntryId));
            }
        });
    }

    // ---------------------------------------------------------------
    // Journal Voucher — no fixed ledger; each item supplies its own
    // debit_ledger_id AND credit_ledger_id + amount (a true manual entry).
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  transactionDate?, reference?, narration?, items: array<{debitLedgerId, creditLedgerId, amount}>
     */
    public function createJournalVoucher(array $data): JournalVoucher
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A journal voucher needs at least one item.']);
        }

        foreach ($data['items'] as $line) {
            if ((int) $line['debitLedgerId'] === (int) $line['creditLedgerId']) {
                throw ValidationException::withMessages(['items' => 'A journal voucher line cannot debit and credit the same ledger.']);
            }
        }

        return DB::transaction(function () use ($data) {
            $voucher = JournalVoucher::create([
                'transaction_date' => $data['transactionDate'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'total_amount' => 0,
            ]);
            $voucher->update(['voucher_number' => self::generateNumber('JV', $voucher->id)]);

            $total = 0.0;
            foreach ($data['items'] as $line) {
                $amount = (float) $line['amount'];
                $total += $amount;

                $entry = $this->financeService->postSimpleEntry(
                    debitLedgerId: $line['debitLedgerId'],
                    creditLedgerId: $line['creditLedgerId'],
                    amount: $amount,
                    narration: $voucher->narration ?? "Journal Voucher {$voucher->voucher_number}",
                    referenceType: JournalVoucher::class,
                    referenceId: $voucher->id,
                );

                $voucher->items()->create([
                    'debit_ledger_id' => $line['debitLedgerId'],
                    'credit_ledger_id' => $line['creditLedgerId'],
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            $voucher->update(['total_amount' => $total]);

            return $voucher->load('items');
        });
    }

    public function paginateJournalVouchers(int $page, int $pageSize): LengthAwarePaginator
    {
        return JournalVoucher::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findJournalVoucher(int $id): JournalVoucher
    {
        return JournalVoucher::query()->with('items')->findOrFail($id);
    }

    public function deleteJournalVoucher(JournalVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            $journalEntryIds = $voucher->items->pluck('journal_entry_id')->all();
            $voucher->delete();
            foreach ($journalEntryIds as $journalEntryId) {
                $this->financeService->deleteEntry(JournalEntry::findOrFail($journalEntryId));
            }
        });
    }

    // ---------------------------------------------------------------
    // Fund Transfer — credit_ledger_id fixed on the header (the source
    // ledger money leaves); each item supplies debit_ledger_id (a
    // destination ledger) + amount — moving money between two of Zuriè's
    // own ledgers (e.g. Cash -> Bank).
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  transactionDate?, reference?, narration?, creditLedgerId, items: array<{debitLedgerId, amount}>
     */
    public function createFundTransfer(array $data): FundTransfer
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A fund transfer needs at least one item.']);
        }

        return DB::transaction(function () use ($data) {
            $transfer = FundTransfer::create([
                'transaction_date' => $data['transactionDate'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'credit_ledger_id' => $data['creditLedgerId'],
                'total_amount' => 0,
            ]);
            $transfer->update(['transfer_number' => self::generateNumber('FT', $transfer->id)]);

            $total = 0.0;
            foreach ($data['items'] as $line) {
                $amount = (float) $line['amount'];
                $total += $amount;

                $entry = $this->financeService->postSimpleEntry(
                    debitLedgerId: $line['debitLedgerId'],
                    creditLedgerId: $transfer->credit_ledger_id,
                    amount: $amount,
                    narration: $transfer->narration ?? "Fund Transfer {$transfer->transfer_number}",
                    referenceType: FundTransfer::class,
                    referenceId: $transfer->id,
                );

                $transfer->items()->create([
                    'debit_ledger_id' => $line['debitLedgerId'],
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            $transfer->update(['total_amount' => $total]);

            return $transfer->load('items');
        });
    }

    public function paginateFundTransfers(int $page, int $pageSize): LengthAwarePaginator
    {
        return FundTransfer::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findFundTransfer(int $id): FundTransfer
    {
        return FundTransfer::query()->with('items')->findOrFail($id);
    }

    public function deleteFundTransfer(FundTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $journalEntryIds = $transfer->items->pluck('journal_entry_id')->all();
            $transfer->delete();
            foreach ($journalEntryIds as $journalEntryId) {
                $this->financeService->deleteEntry(JournalEntry::findOrFail($journalEntryId));
            }
        });
    }
}
