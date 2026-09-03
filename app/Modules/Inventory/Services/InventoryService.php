<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Inventory;

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
    public function decrementForOrder(int $productId, int $quantity): void
    {
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
    public function restockForOrder(int $productId, int $quantity): void
    {
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
