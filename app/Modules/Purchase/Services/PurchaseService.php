<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchase\Models\Purchase;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly InventoryService $inventoryService,
        private readonly FinanceService $financeService,
    ) {}

    /**
     * `{id}` zero-padded, `PUR-` prefixed — same scheme as
     * OrderService::generateOrderNumber(), same uniqueness guarantee for
     * the same reason (derived from the row's own auto-increment id).
     */
    private static function generatePurchaseNumber(int $id): string
    {
        return 'PUR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Creating a purchase immediately receives it — there is no separate
     * pending-purchase-order workflow yet (a future extension: add a
     * `status` column and gate the inventory/ledger side effects below
     * behind a separate receive() action, without needing to change this
     * table's existing columns).
     *
     * Wrapped in one transaction covering the supplier lookup, every
     * line's inventory receipt, and the ledger posting — if any line
     * fails, nothing already done in this purchase is left half-applied.
     *
     * @param  array<string, mixed>  $data  supplierId, items: array<{productId, quantity, costPrice}>, amountPaid?, notes?, costCenterId?
     */
    public function create(array $data): Purchase
    {
        return DB::transaction(function () use ($data) {
            $supplier = $this->supplierService->findOrFail($data['supplierId']);

            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                'total_amount' => 0,
                'amount_paid' => 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $purchase->update(['purchase_number' => self::generatePurchaseNumber($purchase->id)]);

            $totalAmount = 0.0;

            foreach ($data['items'] as $line) {
                $lineTotal = $line['quantity'] * $line['costPrice'];
                $totalAmount += $lineTotal;

                $purchase->items()->create([
                    'product_id' => $line['productId'],
                    'quantity' => $line['quantity'],
                    'cost_price' => $line['costPrice'],
                    'line_total' => $lineTotal,
                ]);

                $this->inventoryService->receivePurchase(
                    $line['productId'],
                    $line['quantity'],
                    Purchase::class,
                    $purchase->id,
                );
            }

            // Never let a caller claim to have paid more than the total —
            // clamp rather than reject, since it's a benign input mistake,
            // not a security concern worth a hard failure.
            $amountPaid = min((float) ($data['amountPaid'] ?? 0), $totalAmount);

            $purchase->update(['total_amount' => $totalAmount, 'amount_paid' => $amountPaid]);

            $this->postToLedger($purchase, $supplier, $totalAmount, $amountPaid, $data['costCenterId'] ?? null);

            activity('purchase')
                ->performedOn($purchase)
                ->event('created')
                ->log("Purchase {$purchase->purchase_number} received from {$supplier->name}");

            return $purchase->load('items');
        });
    }

    /**
     * Debit Inventory Asset for the full total always — goods received are
     * an asset regardless of payment status. Credit side depends on how
     * much was paid at receipt: fully on credit -> the supplier's own
     * ledger (their balance IS accounts payable, see
     * Zurie_V2_Architecture_Design (2).md §30.2); fully paid -> Cash;
     * split -> both, in proportion. Matches the doc's ABC Bags Ltd example
     * exactly when amount_paid is 0.
     */
    private function postToLedger(Purchase $purchase, Supplier $supplier, float $total, float $paid, ?int $costCenterId): void
    {
        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');
        $supplierLedger = $this->financeService->ledgerFor($supplier);

        $lines = [
            ['ledger_id' => $inventoryAsset->id, 'type' => 'debit', 'amount' => $total, 'cost_center_id' => $costCenterId],
        ];

        if ($paid <= 0) {
            $lines[] = ['ledger_id' => $supplierLedger->id, 'type' => 'credit', 'amount' => $total, 'cost_center_id' => $costCenterId];
        } elseif ($paid >= $total) {
            $cash = $this->financeService->systemLedger('CASH');
            $lines[] = ['ledger_id' => $cash->id, 'type' => 'credit', 'amount' => $total, 'cost_center_id' => $costCenterId];
        } else {
            $cash = $this->financeService->systemLedger('CASH');
            $lines[] = ['ledger_id' => $cash->id, 'type' => 'credit', 'amount' => $paid, 'cost_center_id' => $costCenterId];
            $lines[] = ['ledger_id' => $supplierLedger->id, 'type' => 'credit', 'amount' => $total - $paid, 'cost_center_id' => $costCenterId];
        }

        $this->financeService->postEntry(
            $lines,
            narration: "Purchase {$purchase->purchase_number} from {$supplier->name}",
            referenceType: Purchase::class,
            referenceId: $purchase->id,
        );
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return Purchase::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findByPurchaseNumber(string $purchaseNumber): Purchase
    {
        return Purchase::where('purchase_number', $purchaseNumber)->with('items')->firstOrFail();
    }
}
