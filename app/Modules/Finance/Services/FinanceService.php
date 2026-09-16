<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Models\LedgerGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /**
     * Full chart of accounts, nested two levels deep (top-level group ->
     * sub-group -> its ledgers) to match the seeded default hierarchy
     * (Assets > Current Assets > Cash in Hand, etc). If a group ever needs
     * a third nesting level, extend the eager-load chain here rather than
     * changing the schema — parent_id already supports arbitrary depth.
     */
    public function chartOfAccounts(): Collection
    {
        return LedgerGroup::query()
            ->whereNull('parent_id')
            ->with(['children.ledgers', 'ledgers'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Auto-managed ledger for another module's record (a Supplier's payable
     * ledger, an expense category's ledger) — created once, on first
     * reference, and looked up by reference_type/reference_id afterward.
     * Idempotent: calling this twice for the same owner returns the same
     * ledger rather than creating a duplicate.
     */
    public function findOrCreateLedgerFor(Model $owner, LedgerGroup $group, string $name): Ledger
    {
        return Ledger::firstOrCreate(
            [
                'reference_type' => $owner::class,
                'reference_id' => $owner->getKey(),
            ],
            [
                'ledger_group_id' => $group->id,
                'name' => $name,
                'code' => $this->generateCode($owner, $group),
            ]
        );
    }

    /**
     * Auto-managed ledger keyed by name within a group, not by owning
     * record — for categories shared across many rows (e.g. an Expense
     * "category" string like "Rent") rather than a single Eloquent owner
     * like a Supplier. Idempotent per (group, name) pair.
     */
    public function findOrCreateLedgerByName(LedgerGroup $group, string $name): Ledger
    {
        return Ledger::firstOrCreate(
            ['ledger_group_id' => $group->id, 'name' => $name],
            ['code' => sprintf('%s-%s', $group->code, \Illuminate\Support\Str::slug($name))]
        );
    }

    /**
     * The single entry point for posting money movement anywhere in the
     * system — every module (Order, Purchase, a future Expense feature)
     * calls this rather than writing to `journal_entries`/`ledgers`
     * directly. Enforces the one invariant that makes a ledger trustworthy:
     * total debits must equal total credits, checked *before* any row is
     * written — never a partially-posted entry.
     *
     * @param  array<int, array{ledger_id: int, type: 'debit'|'credit', amount: float, cost_center_id?: int|null}>  $lines
     *
     * @throws UnbalancedJournalEntryException  if debits != credits
     */
    public function postEntry(
        array $lines,
        ?string $narration = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
        ?string $date = null,
    ): JournalEntry {
        // Zero lines trivially satisfies debits === credits (0 === 0)
        // below, which would otherwise let a caller-side bug (an array
        // that ended up empty) silently write a meaningless, empty
        // JournalEntry instead of failing loudly. Found via deep-test:
        // postEntry([]) succeeded silently before this guard. Not
        // UnbalancedJournalEntryException — "debits 0 != credits 0" would
        // be a confusing, literally-false message for this case — this is
        // an internal-caller bug, never reachable from valid client input,
        // so it's left unmapped in bootstrap/app.php and surfaces as a 500
        // like any other programmer error would.
        if ($lines === []) {
            throw new \InvalidArgumentException('postEntry() called with zero lines — every journal entry needs at least one debit and one credit line.');
        }

        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($lines as $line) {
            if ($line['type'] === 'debit') {
                $totalDebits += $line['amount'];
            } else {
                $totalCredits += $line['amount'];
            }
        }

        // Tolerance rather than exact float equality — these totals are
        // sums of plain floats from caller-assembled lines, not bcmath.
        if (abs($totalDebits - $totalCredits) > 0.01) {
            throw new UnbalancedJournalEntryException($totalDebits, $totalCredits);
        }

        return DB::transaction(function () use ($lines, $narration, $referenceType, $referenceId, $createdBy, $date) {
            $entry = JournalEntry::create([
                'date' => $date ?? now()->toDateString(),
                'narration' => $narration,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'ledger_id' => $line['ledger_id'],
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'type' => $line['type'],
                    'amount' => $line['amount'],
                ]);

                $this->applyToLedgerBalance($line['ledger_id'], $line['type'], (float) $line['amount']);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Row-locked read-update-write — same concurrency pattern as
     * InventoryService::decrementForOrder()/restockForOrder(). Must be
     * called from inside postEntry()'s surrounding DB::transaction();
     * lockForUpdate() is a no-op outside an active transaction. Applies
     * standard normal-balance accounting rules: a debit increases an
     * asset/expense ledger's balance and decreases a liability/income/
     * equity ledger's; a credit does the reverse. A contra ledger (e.g.
     * Sales Discounts, flagged is_contra) flips this — it carries the
     * opposite normal balance of its group's nature, since it exists to
     * be subtracted from its parent category rather than added to it.
     */
    private function applyToLedgerBalance(int $ledgerId, string $type, float $amount): void
    {
        $ledger = Ledger::query()->with('group')->where('id', $ledgerId)->lockForUpdate()->first();

        $normalBalanceIsDebit = in_array($ledger->group->nature, ['asset', 'expense'], true);
        if ($ledger->is_contra) {
            $normalBalanceIsDebit = ! $normalBalanceIsDebit;
        }
        $direction = ($type === 'debit') === $normalBalanceIsDebit ? 1 : -1;

        $ledger->current_balance = (float) $ledger->current_balance + ($direction * $amount);
        $ledger->save();
    }

    /**
     * Sum of every ledger's balance under a given group code — e.g. total
     * operating expenses across every category ledger under "Indirect
     * Expenses" (IND-EXP), without the caller needing to know how many
     * category ledgers exist or their individual codes.
     */
    public function sumLedgersInGroup(string $groupCode): float
    {
        $group = $this->ledgerGroupByCode($groupCode);

        return (float) Ledger::where('ledger_group_id', $group->id)->sum('current_balance');
    }

    /**
     * Looks up a seeded system ledger by its fixed code (e.g. "CASH",
     * "INV-ASSET") — the cross-module lookup other modules use instead of
     * querying the Ledger model directly (Extensibility Constitution,
     * Rule 2). These rows never move; see ChartOfAccountsSeeder.
     */
    public function systemLedger(string $code): Ledger
    {
        return Ledger::where('code', $code)->firstOrFail();
    }

    /**
     * Looks up a seeded ledger group by its fixed code (e.g. "CRED" for
     * Sundry Creditors) — used by other modules' create() methods to know
     * where their auto-managed ledger belongs (see SupplierService::create()).
     */
    public function ledgerGroupByCode(string $code): LedgerGroup
    {
        return LedgerGroup::where('code', $code)->firstOrFail();
    }

    /**
     * Looks up an already-created auto-managed ledger (e.g. a Supplier's
     * payable ledger, created eagerly at Supplier::create() time) — unlike
     * findOrCreateLedgerFor(), never creates one; callers that expect the
     * ledger to already exist (Purchase posting against a Supplier) use
     * this and let a missing ledger surface as a real error, not a
     * silent auto-create masking a bug elsewhere.
     */
    public function ledgerFor(Model $owner): Ledger
    {
        return Ledger::query()
            ->where('reference_type', $owner::class)
            ->where('reference_id', $owner->getKey())
            ->firstOrFail();
    }

    /**
     * `{GROUP_CODE}-{owner id}` — unique, deterministic, never needs a
     * separate sequence. E.g. "CRED-14" for supplier id 14 under Sundry
     * Creditors (code "CRED").
     */
    private function generateCode(Model $owner, LedgerGroup $group): string
    {
        return sprintf('%s-%d', $group->code, $owner->getKey());
    }
}
