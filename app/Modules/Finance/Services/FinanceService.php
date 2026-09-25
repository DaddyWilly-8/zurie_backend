<?php

namespace App\Modules\Finance\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Finance\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalEntryLine;
use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Models\LedgerGroup;
use App\Modules\Supplier\Models\Supplier;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FinanceService
{
    public function __construct(private readonly CurrencyService $currencyService) {}

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
            ['code' => sprintf('%s-%s', $group->code, Str::slug($name))]
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
     * @throws UnbalancedJournalEntryException if debits != credits
     */
    public function postEntry(
        array $lines,
        ?string $narration = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
        ?string $date = null,
        ?int $currencyId = null,
        ?float $exchangeRate = null,
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

        // Every line is rounded into an exact Money (integer cents) the
        // moment it arrives — see App\Support\Money's docblock for why —
        // so the balance check below can never itself introduce float
        // drift, however many lines an entry has (a 50-line journal
        // voucher summed in float can genuinely drift by more than a
        // cent; summed in integer cents it cannot drift at all).
        $lines = array_map(function (array $line) {
            $line['amount'] = Money::fromDecimal($line['amount']);

            return $line;
        }, $lines);

        $totalDebits = Money::zero();
        $totalCredits = Money::zero();

        foreach ($lines as $line) {
            if ($line['type'] === 'debit') {
                $totalDebits = $totalDebits->add($line['amount']);
            } else {
                $totalCredits = $totalCredits->add($line['amount']);
            }
        }

        // One cent of slack remains here deliberately — not float
        // tolerance (there is none left, the sums above are exact), but a
        // genuine business tolerance: VAT-percentage math on independent
        // lines can leave a caller's total legitimately one cent short or
        // over after each line rounds to its own nearest cent.
        if (abs($totalDebits->cents() - $totalCredits->cents()) > 1) {
            // Never reachable from valid client input (see this method's
            // own docblock) — always a caller-side bug assembling lines.
            // Logged with full context here specifically because the
            // caller (checkout, a GRN receive, a payment) is what a
            // support ticket will actually be about, and the 500 response
            // alone doesn't carry which order/purchase/receipt triggered
            // a broken posting.
            Log::error('Unbalanced journal entry rejected', [
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'narration' => $narration,
                'total_debits' => $totalDebits->toFloat(),
                'total_credits' => $totalCredits->toFloat(),
            ]);

            throw new UnbalancedJournalEntryException($totalDebits->toFloat(), $totalCredits->toFloat());
        }

        // Lines carry Money internally for the check above; postEntry()'s
        // own line-storage and applyToLedgerBalance() take Money too now,
        // so nothing downstream reintroduces the exact amount as a float.

        $entry = DB::transaction(function () use ($lines, $narration, $referenceType, $referenceId, $createdBy, $date, $currencyId, $exchangeRate) {
            $this->lockLedgersInOrder(array_column($lines, 'ledger_id'));

            // Defaults to the base currency at rate 1.0 when the caller
            // doesn't specify one — every existing caller (Order,
            // Purchase, Expense) still posts in implicit base-currency
            // terms, unchanged from before Currency existed.
            $currency = $currencyId !== null
                ? $this->currencyService->findOrFail($currencyId)
                : $this->currencyService->base();
            $resolvedExchangeRate = $exchangeRate ?? $this->currencyService->latestRateFor($currency);

            $entry = JournalEntry::create([
                'date' => $date ?? now()->toDateString(),
                'narration' => $narration,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $createdBy,
                'currency_id' => $currency->id,
                'exchange_rate' => $resolvedExchangeRate,
            ]);

            // Every line within one postEntry() call has always carried
            // the same cost_center_id (no caller has ever varied it
            // per-line) — collected here as the distinct set and attached
            // to the whole entry via the pivot, not stored per line. See
            // cost_center_journal_entry's migration.
            $costCenterIds = [];

            foreach ($lines as $line) {
                /** @var Money $amount */
                $amount = $line['amount'];

                $entry->lines()->create([
                    'ledger_id' => $line['ledger_id'],
                    'type' => $line['type'],
                    'amount' => $amount->toDecimalString(),
                ]);

                $this->applyToLedgerBalance($line['ledger_id'], $line['type'], $amount);

                if (! empty($line['cost_center_id'])) {
                    $costCenterIds[$line['cost_center_id']] = true;
                }
            }

            if ($costCenterIds !== []) {
                $entry->costCenters()->sync(array_keys($costCenterIds));
            }

            return $entry->load('lines', 'costCenters');
        });

        $this->logPostedEntry($entry, $lines);

        return $entry;
    }

    /**
     * Structured trace for every journal entry actually posted — the
     * single point every money-moving flow (checkout, GRN receive,
     * payments/receipts/vouchers) passes through, so a support question
     * ("what happened to order #1234's ledger postings?") can be
     * answered by grepping one log line instead of reconstructing it from
     * the journal_entries table by hand. Called from postEntry() itself
     * so no caller can forget to log its own posting.
     */
    private function logPostedEntry(JournalEntry $entry, array $lines): void
    {
        Log::info('Journal entry posted', [
            'entry_id' => $entry->id,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'narration' => $entry->narration,
            'line_count' => count($lines),
            'ledger_ids' => array_column($lines, 'ledger_id'),
        ]);
    }

    /**
     * Phase G (Payment/Receipt/Journal Voucher/Fund Transfer) — the
     * recurring "one debit ledger, one credit ledger, one amount" shape
     * every line item across all four transaction subtypes reduces to
     * (Journal Voucher's own pair per line included). A thin wrapper
     * around postEntry() so TransactionService doesn't duplicate this
     * 2-line array shape four times — the doc notes ProsERP itself
     * duplicates this per-controller; this codebase shouldn't repeat that.
     */
    public function postSimpleEntry(
        int $debitLedgerId,
        int $creditLedgerId,
        float $amount,
        ?string $narration = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $currencyId = null,
        ?float $exchangeRate = null,
    ): JournalEntry {
        return $this->postEntry(
            [
                ['ledger_id' => $debitLedgerId, 'type' => 'debit', 'amount' => $amount],
                ['ledger_id' => $creditLedgerId, 'type' => 'credit', 'amount' => $amount],
            ],
            narration: $narration,
            referenceType: $referenceType,
            referenceId: $referenceId,
            currencyId: $currencyId,
            exchangeRate: $exchangeRate,
        );
    }

    /**
     * Phase G — the four Transaction subtypes (Payment/Receipt/Journal
     * Voucher/Fund Transfer) are the first callers in this codebase that
     * ever hard-delete a JournalEntry rather than reversing it with a new
     * opposite entry (Order/Purchase's cancel() flows always post a new
     * reversing entry instead — see OrderService::reverseSaleLedger()).
     * A raw `$entry->delete()` would leave `ledgers.current_balance` wrong
     * forever, since that balance is a running total only ever adjusted by
     * applyToLedgerBalance() at postEntry() time, never recomputed from
     * scratch. This applies the exact opposite balance adjustment for
     * every line before deleting the entry (which cascades its lines via
     * the FK), so the ledger ends up exactly as if the entry never
     * existed.
     */
    public function deleteEntry(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $this->lockLedgersInOrder($entry->lines->pluck('ledger_id')->all());

            foreach ($entry->lines as $line) {
                $this->applyToLedgerBalance($line->ledger_id, $line->type === 'debit' ? 'credit' : 'debit', Money::fromDecimal($line->amount));
            }

            $entry->delete();
        });
    }

    /**
     * Recomputes each ledger's balance from opening_balance plus every
     * journal line (same own-direction rule as applyToLedgerBalance()) and
     * returns the ledgers whose stored current_balance disagrees. With
     * $fix = true the stored value is overwritten. Every intermediate sum
     * is exact integer-cent Money, not float, so drift this reports is
     * always real drift in the data — never an artifact of summing floats
     * across however many journal lines a ledger has accumulated.
     *
     * @return array<int, array{ledgerId: int, name: string, code: string, stored: float, expected: float, difference: float}>
     */
    public function reconcileLedgers(bool $fix = false): array
    {
        $sums = JournalEntryLine::query()
            ->selectRaw('ledger_id, type, SUM(amount) as total')
            ->groupBy('ledger_id', 'type')
            ->get()
            ->groupBy('ledger_id');

        $drift = [];

        foreach (Ledger::query()->with('group')->get() as $ledger) {
            $normalBalanceIsDebit = in_array($ledger->group->nature, ['asset', 'expense'], true);
            if ($ledger->is_contra) {
                $normalBalanceIsDebit = ! $normalBalanceIsDebit;
            }

            $expected = Money::fromDecimal($ledger->opening_balance);
            foreach ($sums->get($ledger->id, collect()) as $row) {
                $rowAmount = Money::fromDecimal($row->total);
                $expected = ((($row->type === 'debit') === $normalBalanceIsDebit))
                    ? $expected->add($rowAmount)
                    : $expected->subtract($rowAmount);
            }

            $stored = Money::fromDecimal($ledger->current_balance);
            if (! $stored->equals($expected)) {
                $drift[] = [
                    'ledgerId' => $ledger->id,
                    'name' => $ledger->name,
                    'code' => $ledger->code,
                    'stored' => $stored->toFloat(),
                    'expected' => $expected->toFloat(),
                    'difference' => $stored->subtract($expected)->toFloat(),
                ];

                if ($fix) {
                    $ledger->update(['current_balance' => $expected->toDecimalString()]);
                }
            }
        }

        return $drift;
    }

    /**
     * Deadlock prevention: two concurrent transactions that lock the same
     * ledgers in different orders (a sale touches Cash then Sales; a GRN
     * touches Inventory then a supplier) can each hold what the other
     * wants, and MySQL kills one of them with a 500. Taking every lock up
     * front, always in ascending id order, means all transactions queue
     * in the same order and can never deadlock on ledger rows. Must run
     * inside the caller's DB::transaction() — lockForUpdate() is a no-op
     * outside one.
     *
     * @param  array<int, int|string>  $ledgerIds
     */
    private function lockLedgersInOrder(array $ledgerIds): void
    {
        $ids = collect($ledgerIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($ids !== []) {
            Ledger::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }
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
    private function applyToLedgerBalance(int $ledgerId, string $type, Money $amount): void
    {
        $ledger = Ledger::query()->with('group')->where('id', $ledgerId)->lockForUpdate()->first();

        $normalBalanceIsDebit = in_array($ledger->group->nature, ['asset', 'expense'], true);
        if ($ledger->is_contra) {
            $normalBalanceIsDebit = ! $normalBalanceIsDebit;
        }
        $movesInNormalDirection = ($type === 'debit') === $normalBalanceIsDebit;

        // The accumulating value in this whole system — every posting for
        // the business's lifetime adds/subtracts here. Done in exact
        // integer-cent Money rather than float specifically because this
        // is the one place float drift would be silent, permanent, and
        // compounding (a per-transaction rounding gap you'd never notice
        // until finance:reconcile flagged a books-vs-journal mismatch
        // months later). See App\Support\Money's docblock.
        $current = Money::fromDecimal($ledger->current_balance);
        $new = $movesInNormalDirection ? $current->add($amount) : $current->subtract($amount);

        $ledger->current_balance = $new->toDecimalString();
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
     * Same "own normal direction positive" figure as a system ledger's
     * current_balance, but computed from only the journal lines posted
     * within [$from, $to] instead of the all-time running total — the
     * period-scoped counterpart to systemLedger()->current_balance,
     * needed because current_balance itself is never date-partitioned
     * (it's a single incrementally-adjusted total since inception).
     */
    public function ledgerMovementInPeriod(string $ledgerCode, ?string $from, ?string $to): float
    {
        $ledger = $this->systemLedger($ledgerCode);
        $normalBalanceIsDebit = in_array($ledger->group->nature, ['asset', 'expense'], true);
        if ($ledger->is_contra) {
            $normalBalanceIsDebit = ! $normalBalanceIsDebit;
        }

        $debits = Money::fromDecimal((string) JournalEntryLine::query()
            ->where('ledger_id', $ledger->id)->where('type', 'debit')
            ->whereHas('entry', fn ($query) => $query
                ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to)))
            ->sum('amount'));

        $credits = Money::fromDecimal((string) JournalEntryLine::query()
            ->where('ledger_id', $ledger->id)->where('type', 'credit')
            ->whereHas('entry', fn ($query) => $query
                ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to)))
            ->sum('amount'));

        return ($normalBalanceIsDebit ? $debits->subtract($credits) : $credits->subtract($debits))->toFloat();
    }

    /**
     * Period-scoped counterpart to sumLedgersInGroup() — every ledger
     * under a group's own-direction movement within [$from, $to].
     */
    public function groupMovementInPeriod(string $groupCode, ?string $from, ?string $to): float
    {
        $group = $this->ledgerGroupByCode($groupCode);
        $ledgerIds = Ledger::where('ledger_group_id', $group->id)->pluck('id');
        $normalBalanceIsDebit = in_array($group->nature, ['asset', 'expense'], true);

        $debits = Money::fromDecimal((string) JournalEntryLine::query()
            ->whereIn('ledger_id', $ledgerIds)->where('type', 'debit')
            ->whereHas('entry', fn ($query) => $query
                ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to)))
            ->sum('amount'));

        $credits = Money::fromDecimal((string) JournalEntryLine::query()
            ->whereIn('ledger_id', $ledgerIds)->where('type', 'credit')
            ->whereHas('entry', fn ($query) => $query
                ->when($from !== null, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to !== null, fn ($q) => $q->whereDate('date', '<=', $to)))
            ->sum('amount'));

        return ($normalBalanceIsDebit ? $debits->subtract($credits) : $credits->subtract($debits))->toFloat();
    }

    /**
     * Every ledger, split into its debit/credit column based on the same
     * normal-balance rule applyToLedgerBalance() already applies when
     * posting — `current_balance` is a single signed running total, not
     * separately-tracked debit/credit totals, so a trial balance derives
     * which column it belongs in from the ledger's own nature (and
     * is_contra flip) rather than storing that distinction twice.
     * Debits and credits are guaranteed to sum equal by construction,
     * since postEntry() never allows an unbalanced entry through.
     *
     * @return array<int, array{ledgerId: int, name: string, code: string, groupName: string, debit: float, credit: float}>
     */
    public function trialBalance(): array
    {
        return Ledger::query()
            ->with('group')
            ->get()
            ->map(function (Ledger $ledger) {
                $balance = (float) $ledger->current_balance;
                $normalBalanceIsDebit = in_array($ledger->group->nature, ['asset', 'expense'], true);
                if ($ledger->is_contra) {
                    $normalBalanceIsDebit = ! $normalBalanceIsDebit;
                }

                return [
                    'ledgerId' => $ledger->id,
                    'name' => $ledger->name,
                    'code' => $ledger->code,
                    'groupName' => $ledger->group->name,
                    'debit' => $normalBalanceIsDebit ? max(0, $balance) : max(0, -$balance),
                    'credit' => $normalBalanceIsDebit ? max(0, -$balance) : max(0, $balance),
                ];
            })
            ->all();
    }

    /**
     * Creditors report's data source — every Supplier's payable ledger
     * balance, keyed by stakeholder id (Supplier and Stakeholder are the
     * same physical row post-Phase-C, so this id is directly usable as a
     * stakeholder id by the caller).
     *
     * @return array<int, float> supplierId => currentBalance
     */
    public function payableBalancesBySupplier(): array
    {
        return Ledger::query()
            ->where('reference_type', Supplier::class)
            ->pluck('current_balance', 'reference_id')
            ->map(fn ($balance) => (float) $balance)
            ->all();
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
     * Plain by-id lookup for a module that only holds a ledger id as a
     * loose FK column (e.g. `categories.income_ledger_id`) and needs the
     * actual Ledger to post against — nullable, since that column itself
     * is always optional. Cross-module read via this Service, per the
     * Extensibility Constitution — never a direct Eloquent reach into
     * `ledgers` from another module.
     */
    public function findLedger(?int $id): ?Ledger
    {
        return $id === null ? null : Ledger::find($id);
    }

    /**
     * Assets = Liabilities + Equity, at any point in time — not just at a
     * period boundary, since this codebase never posts closing entries
     * (income/expense ledgers just keep accumulating). To balance without
     * closing entries, undistributed net profit (income ledgers' normal
     * balance minus expense ledgers') is folded into Equity as "Retained
     * Earnings (Current Period)" — the standard treatment for a live,
     * un-closed balance sheet. Every amount uses the same normal-balance
     * sign convention as trialBalance(): positive is "in this nature's
     * own direction" (an asset's normal debit balance shown positive, a
     * liability's normal credit balance shown positive), so summing
     * within a nature never requires the caller to know debit/credit.
     *
     * @return array{
     *     assets: array{total: float, groups: array<string, array{ledgerId: int, name: string, code: string, balance: float}[]>},
     *     liabilities: array{total: float, groups: array<string, array{ledgerId: int, name: string, code: string, balance: float}[]>},
     *     equity: array{total: float, groups: array<string, array{ledgerId: int, name: string, code: string, balance: float}[]>, retainedEarnings: float},
     *     isBalanced: bool,
     * }
     */
    public function balanceSheet(): array
    {
        $ledgers = Ledger::query()->with('group')->get();

        $buckets = ['asset' => [], 'liability' => [], 'equity' => []];
        // Money, not float — a real business can accumulate dozens of
        // per-supplier payable ledgers under Liabilities alone, and
        // summing that many floats is exactly where drift accumulates.
        $totals = ['asset' => Money::zero(), 'liability' => Money::zero(), 'equity' => Money::zero()];
        $netIncome = Money::zero();

        foreach ($ledgers as $ledger) {
            $nature = $ledger->group->nature;
            // applyToLedgerBalance() already stores current_balance in
            // "normal direction positive" form for the ledger's own
            // nature (contra flip included at write time) — the same
            // fact trialBalance() relies on. No re-derivation or sign
            // flip needed here; using the raw value directly is correct.
            $balance = Money::fromDecimal($ledger->current_balance);

            $row = [
                'ledgerId' => $ledger->id,
                'name' => $ledger->name,
                'code' => $ledger->code,
                'balance' => $balance->toFloat(),
            ];

            if (in_array($nature, ['asset', 'liability', 'equity'], true)) {
                $buckets[$nature][$ledger->group->name][] = $row;
                $totals[$nature] = $totals[$nature]->add($balance);
            } elseif ($nature === 'income') {
                $netIncome = $netIncome->add($balance);
            } elseif ($nature === 'expense') {
                $netIncome = $netIncome->subtract($balance);
            }
        }

        $equityWithRetainedEarnings = $totals['equity']->add($netIncome);
        $liabilitiesPlusEquity = $totals['liability']->add($equityWithRetainedEarnings);

        return [
            'assets' => ['total' => $totals['asset']->toFloat(), 'groups' => $buckets['asset']],
            'liabilities' => ['total' => $totals['liability']->toFloat(), 'groups' => $buckets['liability']],
            'equity' => [
                'total' => $equityWithRetainedEarnings->toFloat(),
                'groups' => $buckets['equity'],
                'retainedEarnings' => $netIncome->toFloat(),
            ],
            'isBalanced' => $totals['asset']->equals($liabilitiesPlusEquity),
        ];
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
