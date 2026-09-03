<?php

namespace App\Modules\Order\Services;

use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Order\Exceptions\InvalidOrderTransitionException;
use App\Modules\Order\Models\Order;
use App\Modules\Product\Services\ProductService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Statuses cancel() may act on. Deliberately excludes `delivered`
     * (goods are already gone — restocking would be wrong) and `cancelled`
     * itself (cancel() isn't idempotent-by-design; calling it twice is
     * rejected rather than silently no-op'd, so a second click/retry
     * surfaces as an explicit error instead of quietly doing nothing).
     */
    private const CANCELLABLE_STATUSES = ['new', 'confirmed', 'processing', 'ready_for_delivery'];

    /**
     * Strict forward-only pipeline — `PATCH /admin/orders/{id}` may only
     * move a status to exactly the value mapped here for its current
     * status, one step at a time. No skipping ahead (e.g. `new` straight
     * to `ready_for_delivery`), no moving backward (e.g. `processing` back
     * to `confirmed`), and no re-sending the same status as a no-op — none
     * of those appear as an allowed "next" value for their current status.
     * `delivered` maps to an empty list: terminal, same as `cancelled`
     * (handled separately below since cancellation carries the restock
     * side effect and only happens via cancel()). This is a deliberate
     * decision to prioritize a clean, auditable pipeline over flexibility
     * — see zurie-backend-implementation-spec.md §17.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_STATUS_TRANSITIONS = [
        'new' => ['confirmed'],
        'confirmed' => ['processing'],
        'processing' => ['ready_for_delivery'],
        'ready_for_delivery' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly CustomerService $customerService,
        private readonly ProductService $productService,
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Sequential, zero-padded, `ORD-` prefixed — e.g. `ORD-000123`. Derived
     * straight from the order's own auto-increment id rather than a
     * separate counter, so uniqueness comes for free from the same
     * guarantee the database already gives `id` (no extra locking, no
     * collision risk). Not zero-padding-limited to 6 digits — an id past
     * 999999 just produces a longer number (`ORD-1234567`), not a
     * truncated/wrapped one.
     */
    private static function generateOrderNumber(int $id): string
    {
        return 'ORD-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Checkout — the only place an Order row is ever created. Authoritative
     * pricing only: every line's name/price/salePrice/buyingPrice comes
     * from ProductService::findActiveForOrder(), never from the client
     * payload, and any `total` the client sends is never read at all (not
     * even present in StoreOrderRequest's validated() output).
     *
     * Wrapped in a single transaction covering the customer lookup, every
     * line's stock decrement, and the order + order_items rows — if any
     * line fails (product not found/not published, or insufficient stock
     * via InsufficientStockException), everything already done in this
     * checkout rolls back, including stock already decremented for earlier
     * lines in the same request. No partial orders, no phantom decrements.
     *
     * @param  array<string, mixed>  $data  validated StoreOrderRequest payload
     */
    public function checkout(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->customerService->findOrCreate([
                'name' => $data['customerName'],
                'phone' => $data['customerPhone'],
                'whatsapp_number' => $data['whatsappNumber'] ?? null,
                'email' => $data['customerEmail'] ?? null,
            ]);

            $order = Order::create([
                'customer_id' => $customer->id,
                'customer_name' => $data['customerName'],
                'customer_phone' => $data['customerPhone'],
                'whatsapp_number' => $data['whatsappNumber'] ?? null,
                'customer_email' => $data['customerEmail'] ?? null,
                'status' => 'new',
                'total_amount' => 0,
            ]);

            // order_number is derived from the row's own auto-increment id,
            // set in a follow-up update right after create() rather than
            // computed in advance — the id doesn't exist until the INSERT
            // happens. Deriving it from id (already unique, already
            // assigned atomically by the DB) means no separate counter/
            // sequence table and no extra locking is needed to keep numbers
            // unique — MySQL's auto_increment already does that work.
            $order->update(['order_number' => self::generateOrderNumber($order->id)]);

            $totalAmount = 0;

            foreach ($data['items'] as $line) {
                $product = $this->productService->findActiveForOrder($line['productId']);

                // Row-locked decrement — see InventoryService::decrementForOrder()
                // for why this (not just this method's surrounding
                // DB::transaction()) is what actually prevents two
                // concurrent checkouts from both reading the same
                // pre-decrement quantity and both committing a decrement
                // past zero. Throws InsufficientStockException, caught
                // globally in bootstrap/app.php, if stock is short — which
                // rolls back this entire transaction.
                $this->inventoryService->decrementForOrder($product->id, $line['quantity']);

                $unitSellingPrice = (float) ($product->sale_price ?? $product->price);
                $lineTotal = $unitSellingPrice * $line['quantity'];
                $totalAmount += $lineTotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_buying_price' => $product->buying_price,
                    'unit_selling_price' => $unitSellingPrice,
                    'quantity' => $line['quantity'],
                    'line_total' => $lineTotal,
                ]);
            }

            $order->update(['total_amount' => $totalAmount]);

            // Logged manually, not via Order having the LogsActivity trait
            // — checkout() itself issues 3 separate saves (create, the
            // order_number update, the total_amount update), so the trait
            // would log 3 noisy entries per order instead of 1 meaningful
            // one. Always a guest action (checkout has no auth:sanctum
            // middleware) — causedByAnonymous() rather than relying on the
            // logger's default causer resolution, which would just resolve
            // to null anyway on an unauthenticated request; explicit here
            // so it reads as a deliberate choice, not an oversight. The
            // order number goes in the description (not just the short
            // "Guest checkout" label) so an admin scanning the activity log
            // knows which order it was without opening it.
            activity('order')
                ->performedOn($order)
                ->causedByAnonymous()
                ->event('created')
                ->log("Guest checkout — Order {$order->order_number} placed");

            return $order->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdmin(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        $query = Order::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * Dashboard's `newOrders` stat — orders still sitting in `status: new`,
     * i.e. placed but not yet acted on by an admin. Not "orders created
     * today" or similar — literally the `new` status bucket.
     */
    public function countNew(): int
    {
        return Order::query()->where('status', 'new')->count();
    }

    /**
     * Dashboard's `recentOrders` list — the latest N orders regardless of
     * status. No relations eager-loaded: DashboardController wraps this
     * with OrderListResource, same resource GET /admin/orders uses, which
     * never reads `items` or any relation.
     *
     * @return Collection<int, Order>
     */
    public function recent(int $limit = 5): Collection
    {
        return Order::query()->latest()->limit($limit)->get();
    }

    /**
     * PATCH /admin/orders/{id} — status and/or notes only. Never touches
     * customer_*, total_amount, or items; those are fixed at checkout.
     * `cancelled` is not a value UpdateOrderRequest even accepts (see its
     * rules()) — cancellation only happens through cancel() below, which
     * carries the restock side effect this method knows nothing about.
     *
     * A requested `status` must be the exact next step in
     * ALLOWED_STATUS_TRANSITIONS for the order's current status — this
     * covers both terminal states (`delivered`/`cancelled` map to an empty
     * list, so any status change on either is rejected) and every
     * skip-ahead/backward-move case in one check, not just the two
     * terminal ends. `notes` has no such restriction — always editable,
     * any lifecycle stage, since it carries no business consequence.
     *
     * @param  array<string, mixed>  $data  validated UpdateOrderRequest payload
     *
     * @throws InvalidOrderTransitionException  if the requested status isn't a valid next step from the order's current status
     */
    public function updateStatusOrNotes(Order $order, array $data): Order
    {
        if (array_key_exists('status', $data)) {
            $allowedNext = self::ALLOWED_STATUS_TRANSITIONS[$order->status] ?? [];

            if (! in_array($data['status'], $allowedNext, true)) {
                throw new InvalidOrderTransitionException(
                    $allowedNext === []
                        ? "Order {$order->order_number}'s status ({$order->status}) is final and cannot be changed further."
                        : "Order {$order->order_number} cannot move from '{$order->status}' to '{$data['status']}'. The only allowed next status is '{$allowedNext[0]}'."
                );
            }
        }

        $order->fill(array_intersect_key($data, array_flip(['status', 'notes'])))->save();

        // Manual, same reasoning as checkout() — no LogsActivity trait on
        // Order. causedBy() is left unset here on purpose: this route is
        // auth:sanctum-gated, so the logger's default causer resolution
        // (the current authenticated admin) is already correct without
        // spelling it out.
        if ($order->wasChanged('status')) {
            activity('order')
                ->performedOn($order)
                ->event('updated')
                ->log("Order {$order->order_number} status changed to '{$order->status}'");
        } elseif ($order->wasChanged('notes')) {
            activity('order')
                ->performedOn($order)
                ->event('updated')
                ->log("Order {$order->order_number} notes updated");
        }

        return $order->load('items');
    }

    /**
     * POST /admin/orders/{id}/cancel — the only way an order's status ever
     * becomes `cancelled`. Restocks every line item's quantity (reversing
     * checkout()'s decrementForOrder() calls) inside the same transaction
     * as the status change, so a restock can never happen without the
     * order actually ending up cancelled, or vice versa.
     *
     * @throws InvalidOrderTransitionException  if the order's current status isn't in CANCELLABLE_STATUSES
     */
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            if (! in_array($order->status, self::CANCELLABLE_STATUSES, true)) {
                throw new InvalidOrderTransitionException(
                    "Order {$order->order_number} cannot be cancelled from its current status ({$order->status})."
                );
            }

            $order->loadMissing('items');

            foreach ($order->items as $item) {
                $this->inventoryService->restockForOrder($item->product_id, $item->quantity);
            }

            $order->status = 'cancelled';
            $order->save();

            activity('order')
                ->performedOn($order)
                ->event('cancelled')
                ->log("Order {$order->order_number} cancelled");

            return $order->load('items');
        });
    }
}
