<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Safety net for `inventory.quantity` — same shape and role as Finance's
 * own `finance:reconcile` for ledger balances (see
 * InventoryService::reconcileStock()). Read-only: it reports drift, it
 * never silently rewrites a stock quantity. Exits non-zero on drift so a
 * scheduler/monitor can alert on it.
 */
class ReconcileStockCommand extends Command
{
    protected $signature = 'inventory:reconcile';

    protected $description = 'Recompute stock quantities from inventory movements and report drift';

    public function handle(InventoryService $inventory): int
    {
        $drift = $inventory->reconcileStock();

        if ($drift === []) {
            $this->info('Every stock quantity matches its recorded movements.');

            return self::SUCCESS;
        }

        $this->table(
            ['Product ID', 'Outlet ID', 'Stored', 'Expected', 'Difference'],
            array_map(fn (array $row) => [$row['productId'], $row['outletId'], $row['stored'], $row['expected'], $row['difference']], $drift),
        );

        $this->error(count($drift).' stock row(s) drifted from their recorded movements. Investigate before running any manual correction — this indicates either a bug in a stock-write path or a write that bypassed InventoryService entirely.');

        return self::FAILURE;
    }
}
