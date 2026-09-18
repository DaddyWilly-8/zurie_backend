<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Product\Services\ProductService;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    /** Mirrors OrderService::CANCELLABLE_STATUSES' role — states close()/cancel() may act on. */
    private const OPEN_STATUSES = ['pending', 'partially_received', 'fully_received'];

    public function __construct(
        private readonly CurrencyService $currencyService,
        private readonly FinanceService $financeService,
        private readonly ProductService $productService,
    ) {}

    /**
     * Phase E (VAT/Tax) — a product's own vat_exempted flag always wins
     * over whatever vatPercentage the request supplied; an admin entering
     * a PO can still customize the rate for a non-exempt product (unlike
     * Order's checkout, which is entirely system-computed), but can't
     * override an exemption that's a hard product-level rule.
     */
    private function resolveVatPercentage(int $productId, ?float $requested): float
    {
        $product = $this->productService->findForAdmin($productId);
        if ($product->vat_exempted) {
            return 0.0;
        }

        return $requested ?? (float) config('zurie.default_vat_percentage');
    }

    private static function generatePoNumber(int $id): string
    {
        return 'PO-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Creating a PurchaseOrder never touches inventory or the ledger —
     * only a GRN receiving against it does (see GrnService::create()).
     * This matches the reference doc's own separation: a PO is an intent
     * to buy, not a financial event.
     *
     * A non-null stakeholderId is eagerly promoted to `is_supplier_role`
     * and given a payable ledger here (mirroring
     * SupplierService::create()'s own eager-ledger pattern) so GRN
     * receipt can always assume one exists, the same "never silently
     * auto-create at the point of use" posture FinanceService::ledgerFor()
     * already holds everywhere else — done once, at PO creation, not
     * deferred to first receipt.
     *
     * @param  array<string, mixed>  $data  stakeholderId?, currencyId?, dateRequired?, notes?, items: array<{productId, measurementUnitId, quantity, rate, vatPercentage?, conversionFactor?}>
     */
    public function create(array $data): PurchaseOrder
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A purchase order needs at least one item.']);
        }

        return DB::transaction(function () use ($data) {
            $currency = isset($data['currencyId'])
                ? $this->currencyService->findOrFail($data['currencyId'])
                : $this->currencyService->base();
            $exchangeRate = $this->currencyService->latestRateFor($currency);

            if (! empty($data['stakeholderId'])) {
                $this->ensureSupplierLedger((int) $data['stakeholderId']);
            }

            $purchaseOrder = PurchaseOrder::create([
                'order_date' => now()->toDateString(),
                'date_required' => $data['dateRequired'] ?? now(),
                'stakeholder_id' => $data['stakeholderId'] ?? null,
                'currency_id' => $currency->id,
                'exchange_rate' => $exchangeRate,
                'status' => 'pending',
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $purchaseOrder->update(['po_number' => self::generatePoNumber($purchaseOrder->id)]);

            $totalAmount = 0.0;
            $totalVat = 0.0;

            foreach ($data['items'] as $line) {
                $lineTotal = $line['quantity'] * $line['rate'];
                $totalAmount += $lineTotal;

                $vatPercentage = $this->resolveVatPercentage($line['productId'], $line['vatPercentage'] ?? null);
                $vatAmount = $lineTotal * $vatPercentage / 100;
                $totalVat += $vatAmount;

                $purchaseOrder->items()->create([
                    'product_id' => $line['productId'],
                    'measurement_unit_id' => $line['measurementUnitId'],
                    'conversion_factor' => $line['conversionFactor'] ?? 1,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'vat_percentage' => $vatPercentage,
                    'line_total' => $lineTotal,
                ]);
            }

            $purchaseOrder->update(['total_amount' => $totalAmount, 'vat_amount' => $totalVat]);

            activity('purchase_order')
                ->performedOn($purchaseOrder)
                ->event('created')
                ->log("Purchase order {$purchaseOrder->po_number} created");

            return $purchaseOrder->load('items');
        });
    }

    /**
     * Full delete+recreate of items, matching the reference doc's explicit
     * rule (never a partial diff) — simpler to reason about, and this
     * codebase already treats Order/Purchase as append-only rather than
     * line-item-patchable for the same reason. Rejected once any GRN
     * exists against this PO: editing quantities/rates after goods have
     * already been received against the old numbers would silently
     * invalidate receipts that already moved stock and posted to the
     * ledger — cancel() and start a new PO instead.
     *
     * @param  array<string, mixed>  $data  same shape as create(), stakeholderId/currencyId/dateRequired/notes/items
     */
    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'A purchase order needs at least one item.']);
        }

        if ($purchaseOrder->grns()->exists()) {
            throw ValidationException::withMessages(['purchaseOrder' => 'This purchase order already has goods received against it and can no longer be edited — cancel it and create a new one instead.']);
        }

        return DB::transaction(function () use ($purchaseOrder, $data) {
            if (! empty($data['stakeholderId'])) {
                $this->ensureSupplierLedger((int) $data['stakeholderId']);
            }

            $currency = isset($data['currencyId'])
                ? $this->currencyService->findOrFail($data['currencyId'])
                : $this->currencyService->base();
            $exchangeRate = $this->currencyService->latestRateFor($currency);

            $purchaseOrder->items()->delete();

            $totalAmount = 0.0;
            $totalVat = 0.0;
            foreach ($data['items'] as $line) {
                $lineTotal = $line['quantity'] * $line['rate'];
                $totalAmount += $lineTotal;

                $vatPercentage = $this->resolveVatPercentage($line['productId'], $line['vatPercentage'] ?? null);
                $vatAmount = $lineTotal * $vatPercentage / 100;
                $totalVat += $vatAmount;

                $purchaseOrder->items()->create([
                    'product_id' => $line['productId'],
                    'measurement_unit_id' => $line['measurementUnitId'],
                    'conversion_factor' => $line['conversionFactor'] ?? 1,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'vat_percentage' => $vatPercentage,
                    'line_total' => $lineTotal,
                ]);
            }

            $purchaseOrder->update([
                'stakeholder_id' => $data['stakeholderId'] ?? $purchaseOrder->stakeholder_id,
                'currency_id' => $currency->id,
                'exchange_rate' => $exchangeRate,
                'date_required' => $data['dateRequired'] ?? $purchaseOrder->date_required,
                'notes' => $data['notes'] ?? $purchaseOrder->notes,
                'total_amount' => $totalAmount,
                'vat_amount' => $totalVat,
            ]);

            return $purchaseOrder->load('items');
        });
    }

    public function paginateAdmin(int $page, int $pageSize): LengthAwarePaginator
    {
        return PurchaseOrder::query()->with('items')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findOrFail(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()->with(['items', 'grns'])->findOrFail($id);
    }

    /**
     * Deleting is only ever safe before anything downstream (inventory,
     * ledger) has happened against this PO — deliberately narrower than
     * the reference doc's literal "cascade-delete items -> VAT ->
     * GRNs+movements -> journals" rule, which would mean silently
     * reversing stock/ledger effects other parts of this codebase already
     * trust as final once posted. Once a GRN exists, cancel() (a status
     * change, fully reversible in meaning even if not in the ledger) is
     * the only remaining way to stop a PO, matching how Order/Purchase
     * both already treat cancellation vs. deletion.
     */
    public function delete(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->grns()->exists()) {
            throw ValidationException::withMessages(['purchaseOrder' => 'This purchase order already has goods received against it and cannot be deleted — cancel it instead.']);
        }

        DB::transaction(function () use ($purchaseOrder) {
            $purchaseOrder->items()->delete();
            $purchaseOrder->delete();
        });
    }

    public function close(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if (! in_array($purchaseOrder->status, self::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['purchaseOrder' => "A purchase order in status '{$purchaseOrder->status}' cannot be closed."]);
        }

        $purchaseOrder->update(['status' => 'closed']);

        return $purchaseOrder;
    }

    /**
     * Only works on a PO that's actually closed — matching the doc's rule
     * that reopen() rejects a PO not already in the closed state (e.g. one
     * that's cancelled, which is a terminal state reopen() never touches).
     */
    public function reopen(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->status !== 'closed') {
            throw ValidationException::withMessages(['purchaseOrder' => "Only a closed purchase order can be reopened (current status: '{$purchaseOrder->status}')."]);
        }

        $this->recomputeStatus($purchaseOrder);

        return $purchaseOrder->fresh();
    }

    public function cancel(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        if ($purchaseOrder->grns()->exists()) {
            throw ValidationException::withMessages(['purchaseOrder' => 'A purchase order that already has goods received against it cannot be cancelled.']);
        }

        if (! in_array($purchaseOrder->status, self::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['purchaseOrder' => "A purchase order in status '{$purchaseOrder->status}' cannot be cancelled."]);
        }

        $purchaseOrder->update(['status' => 'canceled']);

        return $purchaseOrder;
    }

    /**
     * Recomputes `status` from actual received quantities across every
     * item's GRN lines — called by GrnService after every receipt and by
     * reopen() above. Never trusts a stored value as authoritative input;
     * this is the one place that's allowed to write it, always derived
     * fresh. A manually 'closed'/'canceled' PO is never touched by this
     * (those are terminal overrides, only close()/reopen()/cancel() above
     * change them).
     */
    public function recomputeStatus(PurchaseOrder $purchaseOrder): void
    {
        $itemIds = $purchaseOrder->items()->pluck('id');
        $totalOrdered = (float) $purchaseOrder->items()->sum('quantity');
        $totalReceived = (float) DB::table('grn_purchase_order_item')
            ->whereIn('purchase_order_item_id', $itemIds)
            ->sum('quantity_received');

        $status = match (true) {
            $totalReceived <= 0 => 'pending',
            $totalReceived >= $totalOrdered => 'fully_received',
            default => 'partially_received',
        };

        $purchaseOrder->update(['status' => $status]);
    }

    /**
     * Eagerly ensures the stakeholder is marked supplier-role and has a
     * payable ledger, reusing Supplier's existing ledger machinery rather
     * than the reference doc's separate `ledger_stakeholder` pivot —
     * Supplier and Stakeholder are the same physical row post-Phase-C
     * consolidation, so giving this stakeholder a second ledger keyed by
     * a different owning class would fragment one real-world entity's
     * payable balance across two ledgers instead of one, which is worse
     * than the doc's model, not a faithful copy of its intent.
     */
    private function ensureSupplierLedger(int $stakeholderId): void
    {
        $supplier = Supplier::query()->findOrFail($stakeholderId);

        if (! $supplier->is_supplier_role) {
            $supplier->update(['is_supplier_role' => true]);
        }

        try {
            $this->financeService->ledgerFor($supplier);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $sundryCreditors = $this->financeService->ledgerGroupByCode('CRED');
            $this->financeService->findOrCreateLedgerFor($supplier, $sundryCreditors, $supplier->name);
        }
    }
}
