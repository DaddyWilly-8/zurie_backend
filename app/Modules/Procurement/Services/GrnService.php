<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Vat\Models\VatTransaction;
use App\Modules\Vat\Services\VatService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrnService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly FinanceService $financeService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly VatService $vatService,
    ) {}

    private static function generateGrnNumber(int $id): string
    {
        return 'GRN-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The one place a PurchaseOrder actually moves stock and posts to the
     * ledger — matching the reference doc's separation of "order intent"
     * (PurchaseOrder) from "goods actually received" (Grn). Deletable via
     * delete() below ("un-receive"), but never editable — a correction is
     * always delete-then-recreate, never a partial edit of what was
     * received.
     *
     * @param  array<string, mixed>  $data  purchaseOrderId, dateReceived?, costFactor?, notes?, lines: array<{purchaseOrderItemId, quantityReceived}>
     */
    public function create(array $data): Grn
    {
        if (empty($data['lines'])) {
            throw ValidationException::withMessages(['lines' => 'A GRN needs at least one received line.']);
        }

        return DB::transaction(function () use ($data) {
            $purchaseOrder = PurchaseOrder::query()->with('items')->findOrFail($data['purchaseOrderId']);

            if (in_array($purchaseOrder->status, ['closed', 'canceled'], true)) {
                throw ValidationException::withMessages(['purchaseOrder' => "Cannot receive goods against a purchase order in status '{$purchaseOrder->status}'."]);
            }

            // Fails loudly rather than silently creating one — a GRN
            // moving real stock/ledger value against a stakeholder with no
            // resolvable payable ledger is exactly the kind of silent gap
            // this codebase's established "never auto-create at point of
            // use" posture (see FinanceService::ledgerFor()'s own
            // docblock) exists to prevent. PurchaseOrderService::create()
            // already provisions this ledger eagerly for a non-null
            // stakeholder, so reaching this branch means something bypassed
            // that step.
            $supplier = $purchaseOrder->stakeholder_id !== null
                ? Supplier::query()->findOrFail($purchaseOrder->stakeholder_id)
                : null;

            if ($supplier !== null) {
                $this->financeService->ledgerFor($supplier);
            }

            $grn = Grn::create([
                'date_received' => $data['dateReceived'] ?? now()->toDateString(),
                'cost_factor' => $data['costFactor'] ?? 1,
                'grnable_type' => PurchaseOrder::class,
                'grnable_id' => $purchaseOrder->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $grn->update(['grn_number' => self::generateGrnNumber($grn->id)]);

            $totalCost = 0.0;
            $totalVat = 0.0;

            foreach ($data['lines'] as $line) {
                /** @var PurchaseOrderItem $item */
                $item = $purchaseOrder->items->firstWhere('id', (int) $line['purchaseOrderItemId']);
                if ($item === null) {
                    throw ValidationException::withMessages(['lines' => "Purchase order item #{$line['purchaseOrderItemId']} does not belong to this purchase order."]);
                }

                $alreadyReceived = (float) DB::table('grn_purchase_order_item')
                    ->where('purchase_order_item_id', $item->id)
                    ->sum('quantity_received');

                $unreceived = $item->quantity - $alreadyReceived;
                $quantityReceived = (float) $line['quantityReceived'];

                if ($quantityReceived > $unreceived + 0.0001) {
                    throw ValidationException::withMessages([
                        'lines' => "Cannot receive {$quantityReceived} for item #{$item->id} — only {$unreceived} remains unreceived.",
                    ]);
                }

                $grn->items()->attach($item->id, ['quantity_received' => $quantityReceived]);

                $stockQuantity = (int) round($quantityReceived * $item->conversion_factor);
                $this->inventoryService->receivePurchase(
                    $item->product_id,
                    $stockQuantity,
                    referenceType: Grn::class,
                    referenceId: $grn->id,
                );

                $lineCost = $quantityReceived * $item->rate * $grn->cost_factor;
                $totalCost += $lineCost;
                // Phase E (VAT/Tax) — VAT on only the portion actually
                // received this GRN, using the rate already resolved and
                // stored per line at PO creation time (see
                // PurchaseOrderService::resolveVatPercentage()), not
                // recomputed here.
                $totalVat += $lineCost * $item->vat_percentage / 100;
            }

            $this->postToLedger($grn, $purchaseOrder, $supplier, $totalCost, $totalVat);

            if ($totalVat > 0) {
                $this->vatService->record($grn, 'input', $totalVat);
            }

            $this->purchaseOrderService->recomputeStatus($purchaseOrder);

            activity('grn')
                ->performedOn($grn)
                ->event('created')
                ->log("GRN {$grn->grn_number} received against purchase order {$purchaseOrder->po_number}");

            return $grn->load('items');
        });
    }

    /**
     * Debit Inventory Asset for the landed cost (rate * quantity *
     * cost_factor, the reference doc's landed-cost multiplier); credit the
     * stakeholder's payable ledger when one exists, or Cash directly for
     * a "Cash Purchase" (null stakeholder) — same reasoning as
     * PurchaseService::postToLedger()'s no-supplier-record branch would
     * use if it had one, kept consistent here.
     */
    private function postToLedger(Grn $grn, PurchaseOrder $purchaseOrder, ?Supplier $supplier, float $totalCost, float $totalVat = 0.0): void
    {
        if ($totalCost <= 0 && $totalVat <= 0) {
            return;
        }

        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');
        $creditLedger = $supplier !== null
            ? $this->financeService->ledgerFor($supplier)
            : $this->financeService->systemLedger('CASH');

        $lines = [
            ['ledger_id' => $inventoryAsset->id, 'type' => 'debit', 'amount' => $totalCost],
        ];

        if ($totalVat > 0) {
            $vatInput = $this->financeService->systemLedger('VAT-IN');
            $lines[] = ['ledger_id' => $vatInput->id, 'type' => 'debit', 'amount' => $totalVat];
        }

        $lines[] = ['ledger_id' => $creditLedger->id, 'type' => 'credit', 'amount' => $totalCost + $totalVat];

        $this->financeService->postEntry(
            $lines,
            narration: "GRN {$grn->grn_number} against purchase order {$purchaseOrder->po_number}",
            referenceType: Grn::class,
            referenceId: $grn->id,
            currencyId: $purchaseOrder->currency_id,
            exchangeRate: $purchaseOrder->exchange_rate,
        );
    }

    /**
     * `purchaseOrderId` filters to one PO's own delivery history — the
     * GRNs tab on a PurchaseOrder row. `grnable_type` is always
     * PurchaseOrder::class today (the only GRN source that exists), but
     * still filtered explicitly rather than assumed, matching the
     * polymorphic column's own intent.
     */
    public function paginateAdmin(int $page, int $pageSize, ?int $purchaseOrderId = null): LengthAwarePaginator
    {
        return Grn::query()
            ->with('items')
            ->when($purchaseOrderId !== null, fn ($query) => $query
                ->where('grnable_type', PurchaseOrder::class)
                ->where('grnable_id', $purchaseOrderId))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findOrFail(int $id): Grn
    {
        return Grn::query()->with(['items', 'grnable'])->findOrFail($id);
    }

    /** Purchase summary report — how many deliveries have been received, total. */
    public function count(): int
    {
        return Grn::query()->count();
    }

    /**
     * "Un-receive" — reverses everything create() did: takes the received
     * stock back out (rejected if it's already been sold/moved on since —
     * see InventoryService::reversePurchaseReceipt()'s docblock), reverses
     * the ledger entry via FinanceService::deleteEntry() (the same
     * balance-safe hard-delete Phase G's Payment/Receipt/etc. use, not a
     * raw row delete that would leave `ledgers.current_balance` wrong),
     * deletes the VAT record this GRN produced (it no longer describes a
     * real transaction once reversed — leaving it would permanently
     * overstate the VAT summary), then deletes the GRN itself (cascading
     * its `grn_purchase_order_item` pivot rows) and recomputes the
     * purchase order's status, which may fall back from fully_received to
     * partially_received or pending.
     */
    public function delete(Grn $grn): void
    {
        DB::transaction(function () use ($grn) {
            foreach ($grn->items as $item) {
                $stockQuantity = (int) round($item->pivot->quantity_received * $item->conversion_factor);
                $this->inventoryService->reversePurchaseReceipt(
                    $item->product_id,
                    $stockQuantity,
                    referenceType: Grn::class,
                    referenceId: $grn->id,
                );
            }

            $journalEntryIds = JournalEntry::where('reference_type', Grn::class)
                ->where('reference_id', $grn->id)
                ->pluck('id');
            foreach ($journalEntryIds as $journalEntryId) {
                $this->financeService->deleteEntry(JournalEntry::findOrFail($journalEntryId));
            }

            VatTransaction::where('vatable_type', Grn::class)->where('vatable_id', $grn->id)->delete();

            $purchaseOrder = $grn->grnable;
            $grnNumber = $grn->grn_number;

            $grn->delete();

            if ($purchaseOrder instanceof PurchaseOrder) {
                $this->purchaseOrderService->recomputeStatus($purchaseOrder);
            }

            activity('grn')
                ->event('deleted')
                ->log("GRN {$grnNumber} un-received");
        });
    }
}
