<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Product\Services\ProductService;
use App\Modules\Purchase\Models\Purchase;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use App\Modules\Vat\Services\VatService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly InventoryService $inventoryService,
        private readonly FinanceService $financeService,
        private readonly CurrencyService $currencyService,
        private readonly ProductService $productService,
        private readonly VatService $vatService,
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

            // Defaults to the base currency at its current rate when the
            // caller doesn't specify one — every existing purchase still
            // posts in implicit base-currency terms, unchanged from
            // before Currency existed.
            $currency = isset($data['currencyId'])
                ? $this->currencyService->findOrFail($data['currencyId'])
                : $this->currencyService->base();
            $exchangeRate = $this->currencyService->latestRateFor($currency);

            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                // Phase C (Stakeholder merge) — Supplier now IS a
                // stakeholders row (see Supplier model docblock), so
                // $supplier->id already is the stakeholder id; no separate
                // lookup/mirror needed. See Zurie_V3_ProsERP_Adaptation_Plan.md.
                'stakeholder_id' => $supplier->id,
                'total_amount' => 0,
                'amount_paid' => 0,
                'notes' => $data['notes'] ?? null,
                'currency_id' => $currency->id,
                'exchange_rate' => $exchangeRate,
            ]);

            $purchase->update(['purchase_number' => self::generatePurchaseNumber($purchase->id)]);

            $totalAmount = 0.0;
            $totalVat = 0.0;

            foreach ($data['items'] as $line) {
                $lineTotal = $line['quantity'] * $line['costPrice'];
                $totalAmount += $lineTotal;

                // Phase E (VAT/Tax) — same product.vat_exempted rule as
                // Order's checkout, computed server-side from the product's
                // own record rather than trusted from the request.
                $product = $this->productService->findForAdmin($line['productId']);
                $vatPercentage = $product->vat_exempted ? 0.0 : (float) config('zurie.default_vat_percentage');
                $vatAmount = $lineTotal * $vatPercentage / 100;
                $totalVat += $vatAmount;

                $purchase->items()->create([
                    'product_id' => $line['productId'],
                    'quantity' => $line['quantity'],
                    'cost_price' => $line['costPrice'],
                    'line_total' => $lineTotal,
                    'vat_percentage' => $vatPercentage,
                    'vat_amount' => $vatAmount,
                ]);

                $this->inventoryService->receivePurchase(
                    $line['productId'],
                    $line['quantity'],
                    referenceType: Purchase::class,
                    referenceId: $purchase->id,
                );
            }

            // Never let a caller claim to have paid more than the total —
            // clamp rather than reject, since it's a benign input mistake,
            // not a security concern worth a hard failure. VAT is included
            // in what's owed, matching how a real supplier invoice works.
            $amountPaid = min((float) ($data['amountPaid'] ?? 0), $totalAmount + $totalVat);

            $purchase->update(['total_amount' => $totalAmount, 'vat_amount' => $totalVat, 'amount_paid' => $amountPaid]);

            $this->postToLedger($purchase, $supplier, $totalAmount, $totalVat, $amountPaid, $data['costCenterId'] ?? null, $currency->id, $exchangeRate);

            if ($totalVat > 0) {
                $this->vatService->record($purchase, 'input', $totalVat);
            }

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
    private function postToLedger(Purchase $purchase, Supplier $supplier, float $total, float $vat, float $paid, ?int $costCenterId, ?int $currencyId = null, ?float $exchangeRate = null): void
    {
        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');
        $supplierLedger = $this->financeService->ledgerFor($supplier);
        // What's actually owed (and, if partially paid, what's still owed
        // on credit) includes VAT — the supplier invoice's total is goods
        // + VAT, same as any real purchase invoice.
        $totalOwed = $total + $vat;

        $lines = [
            ['ledger_id' => $inventoryAsset->id, 'type' => 'debit', 'amount' => $total, 'cost_center_id' => $costCenterId],
        ];

        if ($vat > 0) {
            $vatInput = $this->financeService->systemLedger('VAT-IN');
            $lines[] = ['ledger_id' => $vatInput->id, 'type' => 'debit', 'amount' => $vat, 'cost_center_id' => $costCenterId];
        }

        if ($paid <= 0) {
            $lines[] = ['ledger_id' => $supplierLedger->id, 'type' => 'credit', 'amount' => $totalOwed, 'cost_center_id' => $costCenterId];
        } elseif ($paid >= $totalOwed) {
            $cash = $this->financeService->systemLedger('CASH');
            $lines[] = ['ledger_id' => $cash->id, 'type' => 'credit', 'amount' => $totalOwed, 'cost_center_id' => $costCenterId];
        } else {
            $cash = $this->financeService->systemLedger('CASH');
            $lines[] = ['ledger_id' => $cash->id, 'type' => 'credit', 'amount' => $paid, 'cost_center_id' => $costCenterId];
            $lines[] = ['ledger_id' => $supplierLedger->id, 'type' => 'credit', 'amount' => $totalOwed - $paid, 'cost_center_id' => $costCenterId];
        }

        $this->financeService->postEntry(
            $lines,
            narration: "Purchase {$purchase->purchase_number} from {$supplier->name}",
            referenceType: Purchase::class,
            referenceId: $purchase->id,
            currencyId: $currencyId,
            exchangeRate: $exchangeRate,
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
