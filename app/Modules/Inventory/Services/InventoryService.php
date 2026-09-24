<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Outlet\Services\OutletService;

/**
 * Multi-store foundation — `Inventory` is now one row per (product,
 * outlet), not one row per product globally (see the migration that
 * added `sales_outlet_id`). Every method that reads or writes a specific
 * stock level takes an `?outletId`, defaulting to
 * `OutletService::defaultOnlineOutlet()`'s id when omitted — this is
 * what keeps every existing caller (Product admin, Dashboard, Report,
 * Purchase, Grn — none of which have an outlet in scope yet) working
 * completely unchanged for a single-store business, while genuinely
 * supporting more than one store from today for the one caller that
 * already has an outlet in scope (Order's checkout/cancel flow, which
 * now passes it explicitly). Aggregate read methods (counts, low-stock,
 * batched lookups) instead default to summing **across every outlet** —
 * "how much of this product do we have," full stop — since that's what
 * a global dashboard/storefront stock badge actually needs to mean once
 * stock is split across stores.
 */
class InventoryService
{
    public function __construct(private readonly OutletService $outletService) {}

    private function resolveOutletId(?int $outletId): int
    {
        return $outletId ?? $this->outletService->defaultOnlineOutlet()->id;
    }

    /**
     * Idempotent — safe to call for a product/outlet pair that predates
     * the ProductCreated listener, or got created before Inventory
     * existed.
     */
    public function provisionForProduct(int $productId, ?int $outletId = null): Inventory
    {
        return Inventory::firstOrCreate(
            ['product_id' => $productId, 'sales_outlet_id' => $this->resolveOutletId($outletId)],
            ['quantity' => 0, 'stock_status' => 'OUT_OF_STOCK']
        );
    }

    /**
     * Deadlock prevention for multi-line stock writes — take every needed
     * row lock up front, always ordered by (outlet, product), instead of
     * locking each row as the loop happens to reach it. Two carts holding
     * the same two products in opposite order would otherwise each lock
     * one row and wait forever on the other. Provisions any missing rows
     * first (so there is a row to lock). Must be called inside the
     * caller's DB::transaction() — lockForUpdate() is a no-op outside one.
     *
     * @param  array<int, int|string>  $productIds
     * @param  array<int, int|null>|int|null  $outletIds  one outlet, several (transfers), or null for the default outlet
     */
    public function lockStockRows(array $productIds, array|int|null $outletIds = null): void
    {
        $outlets = collect(is_array($outletIds) ? $outletIds : [$outletIds])
            ->map(fn ($id) => $this->resolveOutletId($id))
            ->unique()->sort()->values()->all();
        $products = collect($productIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        foreach ($outlets as $outlet) {
            foreach ($products as $product) {
                $this->provisionForProduct($product, $outlet);
            }
        }

        if ($outlets !== [] && $products !== []) {
            Inventory::query()
                ->whereIn('sales_outlet_id', $outlets)
                ->whereIn('product_id', $products)
                ->orderBy('sales_outlet_id')->orderBy('product_id')
                ->lockForUpdate()
                ->get(['id']);
        }
    }

    public function getForProduct(int $productId, ?int $outletId = null): Inventory
    {
        return $this->provisionForProduct($productId, $outletId);
    }

    /**
     * Dashboard's `productsInStock` stat — counts distinct products with
     * at least one outlet row `stock_status: IN_STOCK`, not `LOW_STOCK`
     * (that's its own bucket, not folded into either "in stock" or "out
     * of stock" here). Pass `$outletId` to scope to one store; omitted,
     * this is a global "in stock somewhere" count across every store.
     */
    public function countInStock(?int $outletId = null): int
    {
        return Inventory::query()
            ->where('stock_status', 'IN_STOCK')
            ->when($outletId !== null, fn ($query) => $query->where('sales_outlet_id', $outletId))
            ->when($outletId === null, fn ($query) => $query->distinct('product_id'))
            ->count($outletId === null ? 'product_id' : '*');
    }

    /**
     * Dashboard's `productsOutOfStock` stat — a product only counts as
     * globally out of stock once *every* outlet reports OUT_OF_STOCK
     * (checked via a NOT EXISTS-style subquery: no row for that product
     * has any other status) — same caveat as countInStock() above, scope
     * to one store via `$outletId` when given.
     */
    public function countOutOfStock(?int $outletId = null): int
    {
        if ($outletId !== null) {
            return Inventory::query()
                ->where('sales_outlet_id', $outletId)
                ->where('stock_status', 'OUT_OF_STOCK')
                ->count();
        }

        return Inventory::query()
            ->where('stock_status', 'OUT_OF_STOCK')
            ->whereNotIn('product_id', function ($query) {
                $query->select('product_id')->from('inventory')->where('stock_status', '!=', 'OUT_OF_STOCK');
            })
            ->distinct('product_id')
            ->count('product_id');
    }

    /**
     * Report's low-stock alert list — every (product, outlet) row
     * manually flagged LOW_STOCK (there's no auto quantity threshold,
     * same caveat as everywhere else this status is mentioned). Returns
     * raw productId/outletId/quantity triples; Report combines this with
     * ProductService/OutletService for names rather than this module
     * reaching into either module's table.
     *
     * @return array<int, array{productId: int, outletId: int, quantity: int}>
     */
    public function lowStock(?int $outletId = null): array
    {
        return Inventory::query()
            ->where('stock_status', 'LOW_STOCK')
            ->when($outletId !== null, fn ($query) => $query->where('sales_outlet_id', $outletId))
            ->get(['product_id', 'sales_outlet_id', 'quantity'])
            ->map(fn ($row) => [
                'productId' => $row->product_id,
                'outletId' => $row->sales_outlet_id,
                'quantity' => $row->quantity,
            ])
            ->all();
    }

    /**
     * Called by DeleteInventoryRecord (reacting to Product's ProductDeleted
     * event) when a product is deleted — deletes every outlet's row for
     * that product, not just one. product_id has no FK/cascade, so
     * without this the inventory rows would be left behind permanently,
     * orphaned and meaningless. Silently no-ops if no row exists (e.g. a
     * product that predates Inventory, or was already cleaned up) — not an
     * invariant anything depends on, same "best-effort cleanup" posture as
     * MediaService::deleteByUrl().
     */
    public function deleteForProduct(int $productId): void
    {
        Inventory::query()->where('product_id', $productId)->delete();
    }

    /**
     * @param  array<string, mixed>  $data  camelCase keys: quantity?, stockStatus?
     */
    public function update(int $productId, array $data, ?int $outletId = null): Inventory
    {
        $resolvedOutletId = $this->resolveOutletId($outletId);
        $inventory = $this->provisionForProduct($productId, $resolvedOutletId);
        $previousQuantity = $inventory->quantity;

        if (array_key_exists('quantity', $data)) {
            $inventory->quantity = (int) $data['quantity'];

            // Auto-derive status from the new quantity in both directions —
            // 0 => OUT_OF_STOCK, positive => IN_STOCK — unless the same
            // request also explicitly sets stockStatus, which always wins.
            // LOW_STOCK is manual-only; there's no quantity threshold
            // defined for it, so it's never auto-assigned.
            if (! array_key_exists('stockStatus', $data)) {
                $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';
            }
        }

        if (array_key_exists('stockStatus', $data)) {
            // Manual override is always allowed regardless of quantity —
            // e.g. quality hold, discontinued, while stock is still positive.
            $inventory->stock_status = $data['stockStatus'];
        }

        $inventory->save();

        // Admin-initiated quantity change (not a side effect of an order) —
        // record it as an "adjustment" movement so the ledger captures why
        // stock moved, same as every other quantity change in the system.
        $delta = $inventory->quantity - $previousQuantity;
        if ($delta !== 0) {
            $this->recordMovement($productId, $resolvedOutletId, 'adjustment', $delta);
        }

        // Logged here only, not via a LogsActivity trait on the Inventory
        // model — decrementForOrder()/restockForOrder() also call save(),
        // and a trait would log those too. Checkout/cancellation already
        // produce their own Order-level activity entry that explains the
        // stock movement; a per-line-item Inventory entry on top of that
        // would just be noise. This method is the one place a stock change
        // is admin-initiated rather than a side effect of an order, so it's
        // the one place that gets its own entry. No Product name available
        // here without adding a ProductService dependency Inventory doesn't
        // otherwise need — productId is enough for an audit trail entry.
        activity('inventory')
            ->performedOn($inventory)
            ->event('updated')
            ->log("Inventory for product #{$productId} updated to quantity {$inventory->quantity} ({$inventory->stock_status})");

        return $inventory;
    }

    /**
     * Batched lookup for Product's cross-module read pattern — one query
     * per page of results, not one per product. A product's status here
     * is the aggregate across every outlet (IN_STOCK if it's in stock
     * *anywhere*, OUT_OF_STOCK only if every outlet reports that,
     * LOW_STOCK if any outlet is flagged and none report IN_STOCK) —
     * matches what a public storefront "in stock" badge needs to mean.
     * Pass `$outletId` to scope to one store's status instead. Missing
     * rows (a product with no inventory record at all) resolve to
     * OUT_OF_STOCK, matching the "quantity 0 => OUT_OF_STOCK" convention
     * rather than leaving a gap.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string> productId => stockStatus
     */
    public function getStatusForProducts(array $productIds, ?int $outletId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        if ($outletId !== null) {
            $statuses = Inventory::query()
                ->where('sales_outlet_id', $outletId)
                ->whereIn('product_id', $productIds)
                ->pluck('stock_status', 'product_id')
                ->all();
        } else {
            $rows = Inventory::query()
                ->whereIn('product_id', $productIds)
                ->get(['product_id', 'stock_status']);

            $statuses = [];
            foreach ($rows->groupBy('product_id') as $productId => $rowsForProduct) {
                $rowStatuses = $rowsForProduct->pluck('stock_status');
                $statuses[$productId] = match (true) {
                    $rowStatuses->contains('IN_STOCK') => 'IN_STOCK',
                    $rowStatuses->contains('LOW_STOCK') => 'LOW_STOCK',
                    default => 'OUT_OF_STOCK',
                };
            }
        }

        foreach ($productIds as $productId) {
            $statuses[$productId] ??= 'OUT_OF_STOCK';
        }

        return $statuses;
    }

    /**
     * Atomically decrements stock for one checkout line at the selling
     * outlet. Must be called from inside a DB::transaction() started by
     * the caller (Order's checkout flow) — lockForUpdate() only actually
     * holds the row lock within an active transaction; called outside one
     * it's effectively a no-op and the concurrent-checkout race this
     * exists to prevent (two requests both reading the same
     * pre-decrement quantity and both committing a decrement past zero)
     * would still be possible.
     *
     * Provisions the inventory row first (unlocked — safe even if two
     * requests race here, since (product_id, sales_outlet_id) is
     * DB-unique and firstOrCreate handles the collision), then
     * re-selects it with lockForUpdate() to actually hold the row for
     * the read-check-write below.
     *
     * @throws InsufficientStockException if the requested quantity exceeds what's currently on hand at that outlet
     */
    /**
     * Current quantity of one product at one outlet (default: online).
     */
    public function quantityAt(int $productId, ?int $outletId = null): int
    {
        return (int) Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $this->resolveOutletId($outletId))
            ->value('quantity');
    }

    public function decrementForOrder(
        int $productId,
        int $quantity,
        ?int $outletId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $resolvedOutletId = $this->resolveOutletId($outletId);
        $this->provisionForProduct($productId, $resolvedOutletId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $resolvedOutletId)
            ->lockForUpdate()
            ->first();

        if ($inventory->quantity < $quantity) {
            throw new InsufficientStockException($productId, $inventory->quantity, $quantity);
        }

        $inventory->quantity -= $quantity;

        // Same bidirectional zero-crossing rule as update() — a decrement
        // that empties stock auto-sets OUT_OF_STOCK; one that doesn't stays
        // IN_STOCK. No LOW_STOCK auto-assignment here either, same as
        // everywhere else — that's manual-only.
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';

        $inventory->save();

        $this->recordMovement($productId, $resolvedOutletId, 'sale', -$quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Reverses decrementForOrder() — used by Order's cancellation flow to
     * restock every line item's quantity, at the same outlet the order
     * was originally sold from. Same lockForUpdate() pattern as the
     * decrement: an increment can't go negative the way a decrement can,
     * but two concurrent writes to the same inventory row (e.g. a
     * cancellation racing a fresh checkout on the same product) could
     * still lose an update without the row lock, so this stays consistent
     * with decrementForOrder() rather than skipping it as "unnecessary."
     * Must be called from inside a DB::transaction() started by the
     * caller, same requirement as decrementForOrder().
     */
    public function restockForOrder(
        int $productId,
        int $quantity,
        ?int $outletId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $resolvedOutletId = $this->resolveOutletId($outletId);
        $this->provisionForProduct($productId, $resolvedOutletId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $resolvedOutletId)
            ->lockForUpdate()
            ->first();

        $inventory->quantity += $quantity;

        // Same bidirectional zero-crossing rule as decrementForOrder()/
        // update() — restocking a product that was OUT_OF_STOCK flips it
        // back to IN_STOCK. LOW_STOCK stays manual-only, as everywhere else.
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';

        $inventory->save();

        $this->recordMovement($productId, $resolvedOutletId, 'sale_reversal', $quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Stock-in from a received Purchase/GRN, at a given outlet — same
     * lockForUpdate() pattern as restockForOrder(), different movement
     * `type` so the ledger distinguishes "customer returned/order
     * cancelled" from "we bought more stock." Must be called from inside
     * the caller's DB::transaction(), same requirement as every other
     * method here. Purchase/PurchaseOrder/Grn have no outlet concept of
     * their own yet, so every current caller omits `$outletId` and this
     * resolves to the default outlet — a genuine per-purchase outlet
     * picker is a follow-up once those modules gain one, not a schema
     * change (the column already exists and is ready for it).
     */
    public function receivePurchase(
        int $productId,
        int $quantity,
        ?int $outletId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $resolvedOutletId = $this->resolveOutletId($outletId);
        $this->provisionForProduct($productId, $resolvedOutletId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $resolvedOutletId)
            ->lockForUpdate()
            ->first();

        $inventory->quantity += $quantity;
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';
        $inventory->save();

        $this->recordMovement($productId, $resolvedOutletId, 'purchase', $quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Reverses receivePurchase() — used when "un-receiving" a GRN. Unlike
     * restockForOrder()/receivePurchase(), this can legitimately fail:
     * the stock received by this GRN may have already been sold or
     * otherwise moved on since, in which case there isn't enough left to
     * take back out at that outlet. Same InsufficientStockException the
     * checkout path already throws for the same underlying reason (a
     * decrement that would take quantity below zero), reusing its
     * existing global 422 handling in bootstrap/app.php rather than
     * inventing a parallel error shape for what's really the same
     * situation.
     */
    public function reversePurchaseReceipt(
        int $productId,
        int $quantity,
        ?int $outletId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $resolvedOutletId = $this->resolveOutletId($outletId);
        $this->provisionForProduct($productId, $resolvedOutletId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $resolvedOutletId)
            ->lockForUpdate()
            ->first();

        if ($inventory->quantity < $quantity) {
            throw new InsufficientStockException($productId, $inventory->quantity, $quantity);
        }

        $inventory->quantity -= $quantity;
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';
        $inventory->save();

        $this->recordMovement($productId, $resolvedOutletId, 'purchase_reversal', -$quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Stock-out for an Inventory Transfer's source outlet — internal
     * (moving to another of our own outlets) or external (leaving the
     * business entirely). Same InsufficientStockException guard as every
     * other decrement. Must be called from inside the caller's
     * DB::transaction(), same requirement as every other method here.
     */
    public function decrementForTransfer(
        int $productId,
        int $quantity,
        int $outletId,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $this->provisionForProduct($productId, $outletId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $outletId)
            ->lockForUpdate()
            ->first();

        if ($inventory->quantity < $quantity) {
            throw new InsufficientStockException($productId, $inventory->quantity, $quantity);
        }

        $inventory->quantity -= $quantity;
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';
        $inventory->save();

        $this->recordMovement($productId, $outletId, 'transfer_out', -$quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Stock-in for an Inventory Transfer's destination outlet — the
     * "internal" transfer type only (external transfers have no
     * destination inside this business). Must be called from inside the
     * caller's DB::transaction().
     */
    public function incrementForTransfer(
        int $productId,
        int $quantity,
        int $outletId,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $this->provisionForProduct($productId, $outletId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->where('sales_outlet_id', $outletId)
            ->lockForUpdate()
            ->first();

        $inventory->quantity += $quantity;
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';
        $inventory->save();

        $this->recordMovement($productId, $outletId, 'transfer_in', $quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Writes one append-only row to the movements ledger — never updated or
     * deleted afterward. `inventory.quantity` remains the fast-read cached
     * balance; this is the audit trail it's derived from. See
     * Zurie_V2_Architecture_Design (2).md §12/§35 (Phase 0.5). Must be
     * called from inside the same transaction as the balance change it
     * describes, same requirement as the lockForUpdate() calls above.
     */
    private function recordMovement(
        int $productId,
        int $outletId,
        string $type,
        int $quantityDelta,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): void {
        InventoryMovement::create([
            'product_id' => $productId,
            'sales_outlet_id' => $outletId,
            'type' => $type,
            'quantity' => $quantityDelta,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Sum of quantity across every outlet by default ("how much of this
     * product do we have, total") — pass `$outletId` to scope to one
     * store instead.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int> productId => quantity
     */
    public function getQuantitiesForProducts(array $productIds, ?int $outletId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $query = Inventory::query()->whereIn('product_id', $productIds);
        if ($outletId !== null) {
            $query->where('sales_outlet_id', $outletId);
        }

        $quantities = $query
            ->selectRaw('product_id, SUM(quantity) as total_quantity')
            ->groupBy('product_id')
            ->pluck('total_quantity', 'product_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();

        foreach ($productIds as $productId) {
            $quantities[$productId] ??= 0;
        }

        return $quantities;
    }

    /**
     * Every (product, outlet) row with stock on hand — the Inventory
     * Value and store-scoped Stock List reports' data source. Pass
     * `$outletId` to scope to one store.
     *
     * @return array<int, array{productId: int, outletId: int, quantity: int, stockStatus: string}>
     */
    public function allStockRows(?int $outletId = null): array
    {
        return Inventory::query()
            ->when($outletId !== null, fn ($query) => $query->where('sales_outlet_id', $outletId))
            ->get(['product_id', 'sales_outlet_id', 'quantity', 'stock_status'])
            ->map(fn (Inventory $row) => [
                'productId' => $row->product_id,
                'outletId' => $row->sales_outlet_id,
                'quantity' => $row->quantity,
                'stockStatus' => $row->stock_status,
            ])
            ->all();
    }
}
