<?php

namespace App\Modules\InventoryTransfer\Services;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\InventoryTransfer\Models\InventoryTransfer;
use App\Modules\Outlet\Services\OutletService;
use App\Modules\Product\Services\ProductService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Three types — see the migration's docblock for the full reasoning on
 * each:
 * - internal: moves stock between two of our own outlets, no ledger effect.
 * - external: stock leaves the business, posts a write-off ledger entry.
 * - cost_center_change: pure audit-trail record, zero stock/ledger effect
 *   (deliberately scoped down — see the migration's docblock for why).
 */
class InventoryTransferService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly OutletService $outletService,
        private readonly ProductService $productService,
        private readonly FinanceService $financeService,
    ) {}

    private static function generateTransferNumber(int $id): string
    {
        return 'TRF-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data  type, sourceOutletId, destinationOutletId?, sourceCostCenterId?, destinationCostCenterId?, transferDate?, notes?, items: array<{productId, quantity}>
     */
    public function create(array $data): InventoryTransfer
    {
        $type = $data['type'];

        if (! in_array($type, ['internal', 'external', 'cost_center_change'], true)) {
            throw ValidationException::withMessages(['type' => "Unknown transfer type '{$type}'."]);
        }

        if ($type === 'internal' && empty($data['destinationOutletId'])) {
            throw ValidationException::withMessages(['destinationOutletId' => 'An internal transfer needs a destination outlet.']);
        }

        if ($type === 'cost_center_change' && (empty($data['sourceCostCenterId']) || empty($data['destinationCostCenterId']))) {
            throw ValidationException::withMessages(['sourceCostCenterId' => 'A cost-center change needs both a source and destination cost center.']);
        }

        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A transfer needs at least one item.']);
        }

        return DB::transaction(function () use ($data, $type) {
            $sourceOutlet = $this->outletService->findOrFail($data['sourceOutletId']);
            $destinationOutletId = $type === 'internal' ? $data['destinationOutletId'] : ($type === 'cost_center_change' ? $sourceOutlet->id : null);

            $transfer = InventoryTransfer::create([
                'type' => $type,
                'source_outlet_id' => $sourceOutlet->id,
                'destination_outlet_id' => $destinationOutletId,
                'source_cost_center_id' => $data['sourceCostCenterId'] ?? null,
                'destination_cost_center_id' => $data['destinationCostCenterId'] ?? null,
                'transfer_date' => $data['transferDate'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);
            $transfer->update(['transfer_number' => self::generateTransferNumber($transfer->id)]);

            $totalWriteOffValue = 0.0;

            if ($type !== 'cost_center_change') {
                // Source and (for internal) destination rows locked together in
                // (outlet, product) order so opposite-direction transfers can't deadlock.
                $this->inventoryService->lockStockRows(
                    array_column($data['items'], 'productId'),
                    array_filter([$sourceOutlet->id, $type === 'internal' ? $destinationOutletId : null]),
                );
            }

            foreach ($data['items'] as $line) {
                $quantity = (int) $line['quantity'];

                $transfer->items()->create([
                    'product_id' => $line['productId'],
                    'quantity' => $quantity,
                ]);

                if ($type === 'cost_center_change') {
                    // No stock movement — see class docblock.
                    continue;
                }

                $this->inventoryService->decrementForTransfer(
                    $line['productId'],
                    $quantity,
                    $sourceOutlet->id,
                    referenceType: InventoryTransfer::class,
                    referenceId: $transfer->id,
                );

                if ($type === 'internal') {
                    $this->inventoryService->incrementForTransfer(
                        $line['productId'],
                        $quantity,
                        $destinationOutletId,
                        referenceType: InventoryTransfer::class,
                        referenceId: $transfer->id,
                    );
                } else {
                    // external — valued at the product's buying price, so
                    // the write-off reflects what this stock actually cost
                    // the business, matching COGS's own valuation basis.
                    $product = $this->productService->findForAdmin($line['productId']);
                    $totalWriteOffValue += $quantity * (float) $product->buying_price;
                }
            }

            if ($type === 'external' && $totalWriteOffValue > 0) {
                $writeOff = $this->financeService->systemLedger('INV-WRITEOFF');
                $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');

                $this->financeService->postEntry(
                    [
                        ['ledger_id' => $writeOff->id, 'type' => 'debit', 'amount' => $totalWriteOffValue],
                        ['ledger_id' => $inventoryAsset->id, 'type' => 'credit', 'amount' => $totalWriteOffValue],
                    ],
                    narration: "Inventory transfer {$transfer->transfer_number} (external)",
                    referenceType: InventoryTransfer::class,
                    referenceId: $transfer->id,
                );
            }

            activity('inventory_transfer')
                ->performedOn($transfer)
                ->event('created')
                ->log("Inventory transfer {$transfer->transfer_number} ({$type}) created");

            return $transfer->load('items');
        });
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return InventoryTransfer::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findOrFail(int $id): InventoryTransfer
    {
        return InventoryTransfer::query()->with('items')->findOrFail($id);
    }
}
