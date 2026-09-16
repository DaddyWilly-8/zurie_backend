<?php

namespace App\Modules\Report\Services;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Services\OrderService;
use App\Modules\Product\Services\ProductService;

/**
 * Read-only aggregation over what other modules already record — no
 * manually-maintained totals table (Architecture Principle 4). Every
 * number here is derived live from Orders/the Finance ledger/Inventory,
 * so it can never drift from what those modules actually say happened.
 */
class ReportService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly InventoryService $inventoryService,
        private readonly ProductService $productService,
        private readonly FinanceService $financeService,
    ) {}

    /**
     * @return array<string, array{count: int, total: float}>
     */
    public function salesByChannel(): array
    {
        return $this->orderService->sumBySource();
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
     * Straight off the ledger — never separately computed. Matches
     * Zurie_V2_Architecture_Design (2).md §17's formula exactly:
     * Sales Revenue - COGS = Gross Profit; Gross Profit - Operating
     * Expenses = Net Profit. Operating expenses are summed across every
     * category ledger under Indirect Expenses, however many exist —
     * ReportService never needs to know their individual codes.
     *
     * @return array{grossSales: float, salesDiscounts: float, netSales: float, costOfGoodsSold: float, grossProfit: float, operatingExpenses: float, netProfit: float}
     */
    public function revenueSummary(): array
    {
        $sales = (float) $this->financeService->systemLedger('SALES')->current_balance;
        $discounts = (float) $this->financeService->systemLedger('SALES-DISC')->current_balance;
        $cogs = (float) $this->financeService->systemLedger('COGS')->current_balance;
        $operatingExpenses = $this->financeService->sumLedgersInGroup('IND-EXP');

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
}
