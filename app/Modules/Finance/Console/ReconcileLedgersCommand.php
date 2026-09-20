<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Services\FinanceService;
use Illuminate\Console\Command;

/**
 * Safety net for the incrementally-maintained `ledgers.current_balance`:
 * recomputes every balance from opening_balance + journal lines and
 * reports any ledger that disagrees. Read-only unless --fix is given.
 * Exits non-zero on drift so a scheduler/monitor can alert on it.
 */
class ReconcileLedgersCommand extends Command
{
    protected $signature = 'finance:reconcile {--fix : Overwrite drifted current_balance values with the recomputed ones}';

    protected $description = 'Recompute ledger balances from journal lines and report drift';

    public function handle(FinanceService $finance): int
    {
        $drift = $finance->reconcileLedgers((bool) $this->option('fix'));

        if ($drift === []) {
            $this->info('All ledger balances match their journal lines.');

            return self::SUCCESS;
        }

        $this->table(
            ['Ledger', 'Code', 'Stored', 'Expected', 'Difference'],
            array_map(fn (array $row) => [$row['name'], $row['code'], $row['stored'], $row['expected'], $row['difference']], $drift),
        );

        $this->option('fix')
            ? $this->warn('Drift corrected (--fix).')
            : $this->error(count($drift).' ledger(s) drifted. Re-run with --fix to correct.');

        return $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
