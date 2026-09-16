<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;

class InventoryService
{
    /**
     * Idempotent — safe to call for a product that predates the
     * ProductCreated listener, or got created before Inventory existed.
     */
    public function provisionForProduct(int $productId): Inventory
    {
        return Inventory::firstOrCreate(
            ['product_id' => $productId],
            ['quantity' => 0, 'stock_status' => 'OUT_OF_STOCK']
        );
    }

    public function getForProduct(int $productId): Inventory
    {
        return $this->provisionForProduct($productId);
    }

    /**
     * Dashboard's `productsInStock` stat — counts `stock_status: IN_STOCK`
     * rows only, not `LOW_STOCK` (that's its own bucket, not folded into
     * either "in stock" or "out of stock" here). Same caveat as
     * getStatusForProducts()'s batched lookup: a product with no inventory
     * row at all (pre-dating the ProductCreated listener) isn't counted in
     * either this or countOutOfStock() — in practice every product gets a
     * row provisioned at creation time now, so this is a non-issue going
     * forward, just worth knowing if these two counts + a hypothetical
     * "no data" bucket don't sum to `totalProducts`.
     */
    public function countInStock(): int
    {
        return Inventory::query()->where('stock_status', 'IN_STOCK')->count();
    }

    /**
     * Dashboard's `productsOutOfStock` stat — same caveat as countInStock()
     * above.
     */
    public function countOutOfStock(): int
    {
        return Inventory::query()->where('stock_status', 'OUT_OF_STOCK')->count();
    }

    /**
     * Report's low-stock alert list — every product manually flagged
     * LOW_STOCK (there's no auto quantity threshold, same caveat as
     * everywhere else this status is mentioned). Returns raw
     * product_id/quantity pairs; Report combines this with ProductService
     * for names rather than this module reaching into Product's table.
     *
     * @return array<int, array{productId: int, quantity: int}>
     */
    public function lowStock(): array
    {
        return Inventory::query()
            ->where('stock_status', 'LOW_STOCK')
            ->get(['product_id', 'quantity'])
            ->map(fn ($row) => ['productId' => $row->product_id, 'quantity' => $row->quantity])
            ->all();
    }

    /**
     * Called by DeleteInventoryRecord (reacting to Product's ProductDeleted
     * event) when a product is deleted — product_id has no FK/cascade, so
     * without this the inventory row would be left behind permanently,
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
    public function update(int $productId, array $data): Inventory
    {
        $inventory = $this->provisionForProduct($productId);
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
            $this->recordMovement($productId, 'adjustment', $delta);
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
     * per page of results, not one per product. Missing rows (a product
     * with no inventory record yet) resolve to OUT_OF_STOCK, matching the
     * "quantity 0 => OUT_OF_STOCK" convention rather than leaving a gap.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>  productId => stockStatus
     */
    public function getStatusForProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $statuses = Inventory::query()
            ->whereIn('product_id', $productIds)
            ->pluck('stock_status', 'product_id')
            ->all();

        foreach ($productIds as $productId) {
            $statuses[$productId] ??= 'OUT_OF_STOCK';
        }

        return $statuses;
    }
    /**
     * Atomically decrements stock for one checkout line. Must be called
     * from inside a DB::transaction() started by the caller (Order's
     * checkout flow) — lockForUpdate() only actually holds the row lock
     * within an active transaction; called outside one it's effectively a
     * no-op and the concurrent-checkout race this exists to prevent (two
     * requests both reading the same pre-decrement quantity and both
     * committing a decrement past zero) would still be possible.
     *
     * Provisions the inventory row first (unlocked — safe even if two
     * requests race here, since product_id is DB-unique and firstOrCreate
     * handles the collision), then re-selects it with lockForUpdate() to
     * actually hold the row for the read-check-write below.
     *
     * @throws InsufficientStockException  if the requested quantity exceeds what's currently on hand
     */
    public function decrementForOrder(
        int $productId,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $this->provisionForProduct($productId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
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

        $this->recordMovement($productId, 'sale', -$quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Reverses decrementForOrder() — used by Order's cancellation flow to
     * restock every line item's quantity. Same lockForUpdate() pattern as
     * the decrement: an increment can't go negative the way a decrement
     * can, but two concurrent writes to the same inventory row (e.g. a
     * cancellation racing a fresh checkout on the same product) could
     * still lose an update without the row lock, so this stays consistent
     * with decrementForOrder() rather than skipping it as "unnecessary."
     * Must be called from inside a DB::transaction() started by the
     * caller, same requirement as decrementForOrder().
     */
    public function restockForOrder(
        int $productId,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $this->provisionForProduct($productId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $inventory->quantity += $quantity;

        // Same bidirectional zero-crossing rule as decrementForOrder()/
        // update() — restocking a product that was OUT_OF_STOCK flips it
        // back to IN_STOCK. LOW_STOCK stays manual-only, as everywhere else.
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';

        $inventory->save();

        $this->recordMovement($productId, 'sale_reversal', $quantity, referenceType: $referenceType, referenceId: $referenceId);
    }

    /**
     * Stock-in from a received Purchase — same lockForUpdate() pattern as
     * restockForOrder(), different movement `type` so the ledger
     * distinguishes "customer returned/order cancelled" from "we bought
     * more stock." Must be called from inside the caller's DB::transaction(),
     * same requirement as every other method here.
     */
    public function receivePurchase(
        int $productId,
        int $quantity,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $this->provisionForProduct($productId);

        $inventory = Inventory::query()
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $inventory->quantity += $quantity;
        $inventory->stock_status = $inventory->quantity === 0 ? 'OUT_OF_STOCK' : 'IN_STOCK';
        $inventory->save();

        $this->recordMovement($productId, 'purchase', $quantity, referenceType: $referenceType, referenceId: $referenceId);
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
        string $type,
        int $quantityDelta,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $createdBy = null,
    ): void {
        InventoryMovement::create([
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $quantityDelta,
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, int>  productId => quantity
     */
    public function getQuantitiesForProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $quantities = Inventory::query()->whereIn('product_id', $productIds)->pluck('quantity', 'product_id')->all();
        foreach ($productIds as $productId) {
            $quantities[$productId] ??= 0;
        }

        return $quantities;
    }
}
