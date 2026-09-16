<?php

namespace App\Modules\Wishlist\Services;

use App\Modules\Wishlist\Models\WishlistItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Self-service, always scoped to one customer id — the frontend already
 * has a local (Zustand/localStorage) wishlist for anonymous browsing; this
 * module exists so a *registered* customer's wishlist survives across
 * devices, matching the doc's "My Wishlist — future" note under Customer
 * Architecture.
 */
class WishlistService
{
    public function forCustomer(int $customerId): Collection
    {
        return WishlistItem::where('customer_id', $customerId)->latest()->get();
    }

    /**
     * Idempotent — adding a product already on the list is a no-op, not a
     * duplicate row (enforced by the unique constraint too).
     */
    public function add(int $customerId, int $productId): WishlistItem
    {
        return WishlistItem::firstOrCreate(['customer_id' => $customerId, 'product_id' => $productId]);
    }

    public function remove(int $customerId, int $productId): void
    {
        WishlistItem::where('customer_id', $customerId)->where('product_id', $productId)->delete();
    }
}
