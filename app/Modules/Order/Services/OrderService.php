<?php

namespace App\Modules\Order\Services;

use App\Modules\Coupon\Services\CouponService;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Order\Exceptions\InvalidOrderTransitionException;
use App\Modules\Order\Models\Order;
use App\Modules\Outlet\Models\SalesOutlet;
use App\Modules\Outlet\Services\OutletService;
use App\Modules\PriceList\Services\PriceListService;
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
        private readonly OutletService $outletService,
        private readonly PriceListService $priceListService,
        private readonly FinanceService $financeService,
        private readonly NotificationService $notificationService,
        private readonly CouponService $couponService,
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
     * Checkout — the only place a `source: website` Order row is ever
     * created. Authoritative pricing only: every line's name/price comes
     * from ProductService::findActiveForOrder() + PriceListService, never
     * from the client payload, and any `total` the client sends is never
     * read at all (not even present in StoreOrderRequest's validated()
     * output).
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
        $outlet = $this->outletService->defaultOnlineOutlet();

        return $this->createOrder(
            customerData: [
                'name' => $data['customerName'],
                'phone' => $data['customerPhone'],
                'whatsapp_number' => $data['whatsappNumber'] ?? null,
                'email' => $data['customerEmail'] ?? null,
            ],
            items: $data['items'],
            source: 'website',
            outlet: $outlet,
            status: 'new',
            // No payment gateway yet — revenue is recognized at order
            // placement, but the debit lands in Accounts Receivable, not
            // Cash, since nothing's actually been collected. See
            // ChartOfAccountsSeeder's "AR" ledger comment.
            paymentLedgerCode: 'AR',
            causedByAnonymous: true,
            activityLabel: fn (Order $order) => "Guest checkout — Order {$order->order_number} placed",
            couponCode: $data['couponCode'] ?? null,
        );
    }

    /**
     * POS sale — same underlying mechanics as checkout() (authoritative
     * pricing, locked inventory decrement, ledger posting), but: source is
     * `pos` not `website`; status goes straight to `delivered` since goods
     * leave immediately at the counter (no async fulfillment pipeline);
     * and the debit lands in Cash, not Accounts Receivable, since payment
     * is collected at the point of sale. See
     * Zurie_V2_Architecture_Design (2).md §14/§32.
     *
     * Customer resolution reuses the exact same guest/registered mechanism
     * as website checkout — a "walk-in" customer is simply one identified
     * by name+phone with no linked user account, same as a guest checkout;
     * no separate walk-in code path or record type exists (see
     * CustomerService::findOrCreate()).
     *
     * @param  array<string, mixed>  $data  outletId, items, customerId? (existing customer) or customerName+customerPhone (guest/walk-in)
     */
    public function posSale(array $data): Order
    {
        $outlet = $this->outletService->findOrFail($data['outletId']);

        $customerData = isset($data['customerId'])
            ? null
            : [
                'name' => $data['customerName'],
                'phone' => $data['customerPhone'],
                'whatsapp_number' => $data['whatsappNumber'] ?? null,
                'email' => $data['customerEmail'] ?? null,
            ];

        return $this->createOrder(
            customerData: $customerData,
            existingCustomerId: $data['customerId'] ?? null,
            items: $data['items'],
            source: 'pos',
            outlet: $outlet,
            status: 'delivered',
            paymentLedgerCode: 'CASH',
            causedByAnonymous: false,
            activityLabel: fn (Order $order) => "POS sale — Order {$order->order_number} completed at {$outlet->name}",
            couponCode: $data['couponCode'] ?? null,
        );
    }

    /**
     * Shared core of checkout()/posSale() — everything that doesn't differ
     * between channels: customer resolution, order + line creation,
     * price-list-aware pricing, locked inventory decrement, and ledger
     * posting. The two public methods above only decide *which* values to
     * pass in (source, status, payment ledger, activity wording).
     *
     * @param  array<string, mixed>|null  $customerData  name, phone, whatsapp_number?, email? — null if $existingCustomerId is given instead
     * @param  array<int, array{productId: int, quantity: int}>  $items
     */
    private function createOrder(
        ?array $customerData,
        array $items,
        string $source,
        SalesOutlet $outlet,
        string $status,
        string $paymentLedgerCode,
        bool $causedByAnonymous,
        \Closure $activityLabel,
        ?int $existingCustomerId = null,
        ?string $couponCode = null,
    ): Order {
        return DB::transaction(function () use (
            $customerData, $items, $source, $outlet, $status,
            $paymentLedgerCode, $causedByAnonymous, $activityLabel, $existingCustomerId, $couponCode,
        ) {
            $customer = $existingCustomerId !== null
                ? $this->customerService->findForAdmin($existingCustomerId)
                : $this->customerService->findOrCreate($customerData);

            $order = Order::create([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'whatsapp_number' => $customer->whatsapp_number,
                'customer_email' => $customer->email,
                'status' => $status,
                'source' => $source,
                'outlet_id' => $outlet->id,
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

            $totalRevenue = 0.0;
            $totalCost = 0.0;

            foreach ($items as $line) {
                $product = $this->productService->findActiveForOrder($line['productId']);

                // Row-locked decrement — see InventoryService::decrementForOrder()
                // for why this (not just this method's surrounding
                // DB::transaction()) is what actually prevents two
                // concurrent checkouts from both reading the same
                // pre-decrement quantity and both committing a decrement
                // past zero. Throws InsufficientStockException, caught
                // globally in bootstrap/app.php, if stock is short — which
                // rolls back this entire transaction.
                $this->inventoryService->decrementForOrder($product->id, $line['quantity'], Order::class, $order->id);

                $resolved = $this->priceListService->resolvePrice(
                    productId: $product->id,
                    outletId: $outlet->id,
                    customerId: $customer->id,
                    defaultPrice: (float) $product->price,
                    defaultSalePrice: $product->sale_price !== null ? (float) $product->sale_price : null,
                );
                $unitSellingPrice = $resolved['salePrice'] ?? $resolved['price'];

                $lineTotal = $unitSellingPrice * $line['quantity'];
                $lineCost = (float) $product->buying_price * $line['quantity'];
                $totalRevenue += $lineTotal;
                $totalCost += $lineCost;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_buying_price' => $product->buying_price,
                    'unit_selling_price' => $unitSellingPrice,
                    'quantity' => $line['quantity'],
                    'line_total' => $lineTotal,
                ]);
            }

            // Coupon validated against the gross subtotal (before
            // discount) — min_order_amount is meant to gate on what the
            // customer is buying, not what they end up paying after the
            // same coupon reduces it. Redeeming inside this transaction
            // means a checkout that fails downstream (it can't at this
            // point, but consistency matters) never burns a use.
            $discount = 0.0;
            $coupon = null;
            if ($couponCode !== null) {
                $coupon = $this->couponService->validate($couponCode, $totalRevenue);
                $discount = $this->couponService->calculateDiscount($coupon, $totalRevenue);
            }

            $order->update([
                'total_amount' => $totalRevenue - $discount,
                'discount_amount' => $discount,
                'coupon_id' => $coupon?->id,
            ]);

            if ($coupon !== null) {
                $this->couponService->redeem($coupon);
            }

            $this->postSaleToLedger($order, $totalRevenue, $discount, $totalCost, $outlet->cost_center_id, $paymentLedgerCode);

            // Logged manually, not via Order having the LogsActivity trait
            // — this method issues 3+ separate saves (create, order_number
            // update, total_amount update), so the trait would log that
            // many noisy entries per order instead of 1 meaningful one.
            $activity = activity('order')->performedOn($order)->event('created');
            if ($causedByAnonymous) {
                $activity->causedByAnonymous();
            }
            $activity->log($activityLabel($order));

            return $order->load('items');
        });
    }

    /**
     * Debit the payment ledger (Accounts Receivable for website, Cash for
     * POS) for what's actually owed/collected (revenue minus any coupon
     * discount), credit Sales Account for the *full gross* revenue, and
     * — when a discount applies — debit Sales Discounts (contra-revenue)
     * for the difference, so Net Sales = Sales Account - Sales Discounts
     * matches what was really charged. Debit Cost of Goods Sold, credit
     * Inventory Asset for the cost, unaffected by any discount. All lines
     * tagged with the outlet's cost center so Profit/Loss-by-branch
     * (Phase 5) falls straight out of the ledger. See
     * Zurie_V2_Architecture_Design (2).md §30.2/§34's worked example.
     */
    private function postSaleToLedger(Order $order, float $grossRevenue, float $discount, float $cost, ?int $costCenterId, string $paymentLedgerCode): void
    {
        $paymentLedger = $this->financeService->systemLedger($paymentLedgerCode);
        $sales = $this->financeService->systemLedger('SALES');
        $cogs = $this->financeService->systemLedger('COGS');
        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');

        $lines = [
            ['ledger_id' => $paymentLedger->id, 'type' => 'debit', 'amount' => $grossRevenue - $discount, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $sales->id, 'type' => 'credit', 'amount' => $grossRevenue, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $cogs->id, 'type' => 'debit', 'amount' => $cost, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $inventoryAsset->id, 'type' => 'credit', 'amount' => $cost, 'cost_center_id' => $costCenterId],
        ];

        if ($discount > 0) {
            $salesDiscounts = $this->financeService->systemLedger('SALES-DISC');
            $lines[] = ['ledger_id' => $salesDiscounts->id, 'type' => 'debit', 'amount' => $discount, 'cost_center_id' => $costCenterId];
        }

        $this->financeService->postEntry(
            $lines,
            narration: "Order {$order->order_number}",
            referenceType: Order::class,
            referenceId: $order->id,
        );
    }

    /**
     * Exact mirror of postSaleToLedger() with debit/credit swapped — used
     * by cancel() so a cancelled order's revenue/COGS/discount impact
     * nets to zero in the ledger, not just in the order's own status.
     * Gross revenue and discount are reconstructed from the order's own
     * stored total_amount/discount_amount (total_amount is already net of
     * discount) rather than re-summing items, since a coupon's discount
     * can't otherwise be recovered after the fact.
     */
    private function reverseSaleLedger(Order $order, float $cost, string $paymentLedgerCode, ?int $costCenterId): void
    {
        $discount = (float) $order->discount_amount;
        $grossRevenue = (float) $order->total_amount + $discount;

        $paymentLedger = $this->financeService->systemLedger($paymentLedgerCode);
        $sales = $this->financeService->systemLedger('SALES');
        $cogs = $this->financeService->systemLedger('COGS');
        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');

        $lines = [
            ['ledger_id' => $sales->id, 'type' => 'debit', 'amount' => $grossRevenue, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $paymentLedger->id, 'type' => 'credit', 'amount' => $grossRevenue - $discount, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $inventoryAsset->id, 'type' => 'debit', 'amount' => $cost, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $cogs->id, 'type' => 'credit', 'amount' => $cost, 'cost_center_id' => $costCenterId],
        ];

        if ($discount > 0) {
            $salesDiscounts = $this->financeService->systemLedger('SALES-DISC');
            $lines[] = ['ledger_id' => $salesDiscounts->id, 'type' => 'credit', 'amount' => $discount, 'cost_center_id' => $costCenterId];
        }

        $this->financeService->postEntry(
            $lines,
            narration: "Cancellation of Order {$order->order_number}",
            referenceType: Order::class,
            referenceId: $order->id,
        );
    }

    /**
     * A registered customer's own order history — GET /account/orders.
     * Scoped strictly to their linked Customer id; never accepts an
     * arbitrary id from the request, only ever the one resolved from the
     * authenticated session (see AccountController).
     */
    public function paginateForCustomer(int $customerId, int $page, int $pageSize): LengthAwarePaginator
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->with('items')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
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
     * Report's "Sales by Channel" — grouped by `source`, excluding
     * cancelled orders (their revenue was already reversed in the ledger,
     * so counting them here too would double-count the same sale as both
     * "happened" and "reversed").
     *
     * @return array<string, array{count: int, total: float}>
     */
    public function sumBySource(): array
    {
        return Order::query()
            ->where('status', '!=', 'cancelled')
            ->selectRaw('source, count(*) as count, sum(total_amount) as total')
            ->groupBy('source')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->source => ['count' => (int) $row->count, 'total' => (float) $row->total],
            ])
            ->all();
    }

    /**
     * Target's `current_amount` — non-cancelled order revenue within a
     * given calendar month, same cancelled-orders exclusion reasoning as
     * sumBySource().
     *
     * @param  string  $yearMonth  'YYYY-MM'
     */
    public function sumRevenueForPeriod(string $yearMonth): float
    {
        return (float) Order::query()
            ->where('status', '!=', 'cancelled')
            ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$yearMonth])
            ->sum('total_amount');
    }

    /**
     * CashierSession's reconciliation — cash POS sales for one outlet
     * within a time window (the session's opened_at to now/closed_at).
     * Every POS sale currently pays via Cash (see posSale()'s
     * `paymentLedgerCode: 'CASH'`) — if a payment-method selector is ever
     * added to POS, this should filter to cash-paid orders specifically
     * rather than all POS sales.
     */
    public function sumPosSalesForOutlet(int $outletId, string $from, string $to): float
    {
        return (float) Order::query()
            ->where('outlet_id', $outletId)
            ->where('source', 'pos')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount');
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

            $this->notifyCustomerOfStatusChange($order);
        } elseif ($order->wasChanged('notes')) {
            activity('order')
                ->performedOn($order)
                ->event('updated')
                ->log("Order {$order->order_number} notes updated");
        }

        return $order->load('items');
    }

    /**
     * Only fires for a registered customer (a linked user_id) — a guest
     * order has no account to notify. Best-effort: never blocks the
     * status update itself if something's off with the customer lookup.
     */
    private function notifyCustomerOfStatusChange(Order $order): void
    {
        $customer = $this->customerService->findForAdmin($order->customer_id);
        if ($customer->user_id !== null) {
            $this->notificationService->notify(
                $customer->user_id,
                'order_status_changed',
                "Your order {$order->order_number} status changed to '{$order->status}'.",
            );
        }
    }

    /**
     * POST /admin/orders/{id}/cancel — the only way an order's status ever
     * becomes `cancelled`. Restocks every line item's quantity (reversing
     * checkout()'s decrementForOrder() calls) and reverses the revenue/COGS
     * ledger posting from createOrder() — both inside the same transaction
     * as the status change, so neither side effect can happen without the
     * order actually ending up cancelled, or vice versa. Without the
     * ledger reversal, a cancelled order would permanently overstate
     * recognized revenue.
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

            $totalCost = 0.0;
            foreach ($order->items as $item) {
                $this->inventoryService->restockForOrder($item->product_id, $item->quantity, Order::class, $order->id);
                $totalCost += (float) $item->unit_buying_price * $item->quantity;
            }

            $paymentLedgerCode = $order->source === 'pos' ? 'CASH' : 'AR';
            $outlet = $order->outlet_id !== null ? $this->outletService->findOrFail($order->outlet_id) : null;
            $this->reverseSaleLedger($order, $totalCost, $paymentLedgerCode, $outlet?->cost_center_id);

            // Give back the coupon use a cancelled order consumed — without
            // this, a maxUses-limited coupon is permanently burned by an
            // order that never actually happened, denying that use to
            // every future customer for no reason. Found via deep-test:
            // checking out with a maxUses=1 coupon then cancelling left
            // the coupon unusable forever.
            if ($order->coupon_id !== null) {
                $this->couponService->unredeemById($order->coupon_id);
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
