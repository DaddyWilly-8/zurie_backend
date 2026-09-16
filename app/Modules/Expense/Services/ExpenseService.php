<?php

namespace App\Modules\Expense\Services;

use App\Modules\Expense\Models\Expense;
use App\Modules\Finance\Services\FinanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(private readonly FinanceService $financeService) {}

    /**
     * Debit the category's ledger (auto-created under Indirect Expenses on
     * first use of that category name), credit whichever asset ledger paid
     * it. See Zurie_V2_Architecture_Design (2).md §30.1's Indirect Expenses
     * note.
     *
     * @param  array<string, mixed>  $data  category, amount, paymentMethod?, description?, costCenterId?, created_by?
     *
     * `created_by` stays snake_case deliberately — unlike the other keys
     * here, it's never part of the client's validated() JSON; the
     * controller sets it server-side from the authenticated user
     * (`$data['created_by'] = $request->user()?->id`), so it isn't subject
     * to the camelCase-JSON-request convention the other keys follow.
     */
    public function create(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            $expense = Expense::create([
                'category' => $data['category'],
                'amount' => $data['amount'],
                'payment_method' => $data['paymentMethod'] ?? 'cash',
                'description' => $data['description'] ?? null,
                'cost_center_id' => $data['costCenterId'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $indirectExpenses = $this->financeService->ledgerGroupByCode('IND-EXP');
            $categoryLedger = $this->financeService->findOrCreateLedgerByName($indirectExpenses, $expense->category);

            $paymentLedgerCode = $expense->payment_method === 'bank' ? 'BANK' : 'CASH';
            $paymentLedger = $this->financeService->systemLedger($paymentLedgerCode);

            $this->financeService->postEntry(
                [
                    ['ledger_id' => $categoryLedger->id, 'type' => 'debit', 'amount' => (float) $expense->amount, 'cost_center_id' => $expense->cost_center_id],
                    ['ledger_id' => $paymentLedger->id, 'type' => 'credit', 'amount' => (float) $expense->amount, 'cost_center_id' => $expense->cost_center_id],
                ],
                narration: "Expense: {$expense->category}" . ($expense->description ? " — {$expense->description}" : ''),
                referenceType: Expense::class,
                referenceId: $expense->id,
                createdBy: $expense->created_by,
            );

            activity('expense')
                ->performedOn($expense)
                ->event('created')
                ->log("Expense recorded: {$expense->category} ({$expense->amount})");

            return $expense;
        });
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return Expense::query()->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }
}
