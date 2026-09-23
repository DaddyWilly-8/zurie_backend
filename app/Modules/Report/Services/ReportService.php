<?php

namespace App\Modules\Report\Services;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Product\Services\ProductService;
use App\Modules\Stakeholder\Services\StakeholderService;
use App\Modules\Transaction\Services\TransactionService;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only aggregation over what other modules already record — no
 * manually-maintained totals table (Architecture Principle 4). Every
 * number here is derived live from Orders/the Finance ledger/Inventory,
 * so it can never drift from what those modules actually say happened.
 *
 * The heaviest reports (whole-table scans: trial balance, balance sheet,
 * debtors, creditors) are cached for a short TTL rather than made
 * real-time — a report that's a few seconds stale is never wrong in a way
 * that matters to someone glancing at it, and a plain TTL needs no
 * invalidation logic (nothing to keep in sync when an order/payment/GRN
 * posts elsewhere). CACHE_STORE is `database` by default in this app
 * (see .env.example) — swap to Redis before this matters at real scale.
 */
class ReportService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly InventoryService $inventoryService,
        private readonly ProductService $productService,
        private readonly FinanceService $financeService,
        private readonly TransactionService $transactionService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly GrnService $grnService,
        private readonly StakeholderService $stakeholderService,
    ) {}

    /**
     * @param  string|null  $from  inclusive 'YYYY-MM-DD'; omit for all-time
     * @param  string|null  $to  inclusive 'YYYY-MM-DD'; omit for open-ended
     * @return array<string, array{count: int, total: float}>
     */
    public function salesByChannel(?string $from = null, ?string $to = null): array
    {
        return $this->orderService->sumBySource($from, $to);
    }

    /**
     * @return array<int, array{productId: int, productName: string, quantity: int}>
     */
    public function lowStockAlerts(): array
    {
        $rows = $this->inventoryService->lowStock();
        $names = $this->productService->namesFor(array_column($rows, 'productId'));

        return array_map(
            fn (array $row) => [
                'productId' => $row['productId'],
                'productName' => $names[$row['productId']] ?? 'Unknown product',
                'quantity' => $row['quantity'],
            ],
            $rows,
        );
    }

    /**
     * All-time (no dates given): straight off the ledger's running total —
     * never separately computed. With a date range given: computed from
     * only the journal lines posted in that window (FinanceService::
     * ledgerMovementInPeriod()/groupMovementInPeriod()), since
     * current_balance itself is never date-partitioned. Matches
     * Zurie_V2_Architecture_Design (2).md §17's formula exactly:
     * Sales Revenue - COGS = Gross Profit; Gross Profit - Operating
     * Expenses = Net Profit. Operating expenses are summed across every
     * category ledger under Indirect Expenses, however many exist —
     * ReportService never needs to know their individual codes.
     *
     * @param  string|null  $from  inclusive 'YYYY-MM-DD'; omit for all-time
     * @param  string|null  $to  inclusive 'YYYY-MM-DD'; omit for open-ended
     * @return array{grossSales: float, salesDiscounts: float, netSales: float, costOfGoodsSold: float, grossProfit: float, operatingExpenses: float, netProfit: float}
     */
    public function revenueSummary(?string $from = null, ?string $to = null): array
    {
        if ($from === null && $to === null) {
            $sales = (float) $this->financeService->systemLedger('SALES')->current_balance;
            $discounts = (float) $this->financeService->systemLedger('SALES-DISC')->current_balance;
            $cogs = (float) $this->financeService->systemLedger('COGS')->current_balance;
            $operatingExpenses = $this->financeService->sumLedgersInGroup('IND-EXP');
        } else {
            $sales = $this->financeService->ledgerMovementInPeriod('SALES', $from, $to);
            $discounts = $this->financeService->ledgerMovementInPeriod('SALES-DISC', $from, $to);
            $cogs = $this->financeService->ledgerMovementInPeriod('COGS', $from, $to);
            $operatingExpenses = $this->financeService->groupMovementInPeriod('IND-EXP', $from, $to);
        }

        $netSales = $sales - $discounts;
        $grossProfit = $netSales - $cogs;

        return [
            'grossSales' => $sales,
            'salesDiscounts' => $discounts,
            'netSales' => $netSales,
            'costOfGoodsSold' => $cogs,
            'grossProfit' => $grossProfit,
            'operatingExpenses' => $operatingExpenses,
            'netProfit' => $grossProfit - $operatingExpenses,
        ];
    }

    /**
     * @return array<int, array{ledgerId: int, name: string, code: string, groupName: string, debit: float, credit: float}>
     */
    public function trialBalance(): array
    {
        return Cache::remember('report:trial-balance', 60, fn () => $this->financeService->trialBalance());
    }

    /**
     * @return array{assets: array{total: float, groups: array<string, array<int, array{ledgerId: int, name: string, code: string, balance: float}>>}, liabilities: array{total: float, groups: array<string, array<int, array{ledgerId: int, name: string, code: string, balance: float}>>}, equity: array{total: float, groups: array<string, array<int, array{ledgerId: int, name: string, code: string, balance: float}>>, retainedEarnings: float}, isBalanced: bool}
     */
    public function balanceSheet(): array
    {
        return Cache::remember('report:balance-sheet', 60, fn () => $this->financeService->balanceSheet());
    }

    /**
     * Global inventory valuation at buying-price basis (matches COGS's
     * own costing basis, not selling price). Pass `$outletId` to scope
     * to one store's stock.
     *
     * @return array{totalValue: float, lines: array<int, array{productId: int, productName: string, outletId: int, quantity: int, buyingPrice: float, value: float}>}
     */
    public function inventoryValue(?int $outletId = null): array
    {
        return Cache::remember("report:inventory-value:{$outletId}", 60, function () use ($outletId) {
            $rows = $this->inventoryService->allStockRows($outletId);
            $productIds = array_unique(array_column($rows, 'productId'));
            $names = $this->productService->namesFor($productIds);
            $prices = $this->productService->buyingPricesFor($productIds);

            $totalValue = 0.0;
            $lines = [];
            foreach ($rows as $row) {
                if ($row['quantity'] <= 0) {
                    continue;
                }

                $price = $prices[$row['productId']] ?? 0.0;
                $value = $row['quantity'] * $price;
                $totalValue += $value;

                $lines[] = [
                    'productId' => $row['productId'],
                    'productName' => $names[$row['productId']] ?? 'Unknown product',
                    'outletId' => $row['outletId'],
                    'quantity' => $row['quantity'],
                    'buyingPrice' => $price,
                    'value' => $value,
                ];
            }

            return ['totalValue' => $totalValue, 'lines' => $lines];
        });
    }

    /**
     * Every stakeholder-customer's outstanding balance: total (non-
     * cancelled) order value minus total Receipts applied against them.
     * Deliberately computed, not stored — see OrderService::
     * totalsByStakeholder()'s docblock for why this doesn't need a
     * per-customer receivable ledger the way Suppliers get a payable one.
     *
     * @return array<int, array{stakeholderId: int, name: string, billed: float, received: float, outstanding: float}>
     */
    public function debtors(): array
    {
        return Cache::remember('report:debtors', 60, function () {
            $billed = $this->orderService->totalsByStakeholder();
            $received = $this->transactionService->receiptsAppliedByStakeholder();

            $rows = [];
            foreach ($billed as $stakeholderId => $billedAmount) {
                $outstanding = $billedAmount - ($received[$stakeholderId] ?? 0.0);
                if ($outstanding <= 0.01) {
                    continue;
                }

                $rows[] = [
                    'stakeholderId' => $stakeholderId,
                    'billed' => $billedAmount,
                    'received' => $received[$stakeholderId] ?? 0.0,
                    'outstanding' => $outstanding,
                ];
            }

            return $this->attachStakeholderNames($rows);
        });
    }

    /**
     * Every supplier's payable ledger balance — directly available since
     * Suppliers already get an individual ledger at creation time,
     * unlike customers (see debtors()'s docblock for the asymmetry).
     *
     * @return array<int, array{stakeholderId: int, name: string, outstanding: float}>
     */
    public function creditors(): array
    {
        return Cache::remember('report:creditors', 60, function () {
            $balances = $this->financeService->payableBalancesBySupplier();

            $rows = [];
            foreach ($balances as $stakeholderId => $balance) {
                if ($balance <= 0.01) {
                    continue;
                }

                $rows[] = ['stakeholderId' => $stakeholderId, 'outstanding' => $balance];
            }

            return $this->attachStakeholderNames($rows);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  each with a stakeholderId key
     * @return array<int, array<string, mixed>>
     */
    private function attachStakeholderNames(array $rows): array
    {
        return array_map(function (array $row) {
            try {
                $row['name'] = $this->stakeholderService->findOrFail($row['stakeholderId'])->name;
            } catch (\Throwable) {
                $row['name'] = 'Unknown stakeholder';
            }

            return $row;
        }, $rows);
    }

    /**
     * @return array{purchaseOrders: array<string, array{count: int, total: float}>, deliveriesReceived: int}
     */
    /**
     * @param  string|null  $from  inclusive 'YYYY-MM-DD'; omit for all-time
     * @param  string|null  $to  inclusive 'YYYY-MM-DD'; omit for open-ended
     */
    public function purchaseSummary(?string $from = null, ?string $to = null): array
    {
        return [
            'purchaseOrders' => $this->purchaseOrderService->totalsByStatus($from, $to),
            'deliveriesReceived' => $this->grnService->count($from, $to),
        ];
    }

    /**
     * Store-scoped stock list — one outlet's own inventory, for the
     * `stores/{id}/stock_list` report equivalent.
     *
     * @return array<int, array{productId: int, productName: string, quantity: int, stockStatus: string}>
     */
    public function storeStockList(int $outletId): array
    {
        $rows = $this->inventoryService->allStockRows($outletId);
        $names = $this->productService->namesFor(array_column($rows, 'productId'));

        return array_map(
            fn (array $row) => [
                'productId' => $row['productId'],
                'productName' => $names[$row['productId']] ?? 'Unknown product',
                'quantity' => $row['quantity'],
                'stockStatus' => $row['stockStatus'],
            ],
            $rows,
        );
    }
}
