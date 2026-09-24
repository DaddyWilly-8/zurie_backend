<?php

namespace App\Modules\Order\Services;

use App\Modules\Auth\Services\CustomerAccountService;
use App\Modules\Coupon\Services\CouponService;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Order\Exceptions\InvalidOrderTransitionException;
use App\Modules\Order\Models\Order;
use App\Modules\Outlet\Models\SalesOutlet;
use App\Modules\Outlet\Services\OutletService;
use App\Modules\PriceList\Services\PriceListService;
use App\Modules\Product\Services\CategoryService;
use App\Modules\Product\Services\ProductService;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Vat\Services\VatService;
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
        private readonly CurrencyService $currencyService,
        private readonly VatService $vatService,
        private readonly CustomerAccountService $customerAccountService,
        private readonly CategoryService $categoryService,
        private readonly SettingsService $settingsService,
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
        return 'ORD-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
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
            currencyId: $data['currencyId'] ?? null,
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
            currencyId: $data['currencyId'] ?? null,
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
        ?int $currencyId = null,
    ): Order {
        return DB::transaction(function () use (
            $customerData, $items, $source, $outlet, $status,
            $paymentLedgerCode, $causedByAnonymous, $activityLabel, $existingCustomerId, $couponCode, $currencyId,
        ) {
            $customer = $existingCustomerId !== null
                ? $this->customerService->findForAdmin($existingCustomerId)
                : $this->customerService->findOrCreate($customerData);

            // Defaults to the base currency at its current rate when the
            // caller doesn't specify one — every existing checkout/POS
            // call still posts in implicit base-currency terms, unchanged
            // from before Currency existed.
            $currency = $currencyId !== null
                ? $this->currencyService->findOrFail($currencyId)
                : $this->currencyService->base();
            $exchangeRate = $this->currencyService->latestRateFor($currency);

            $order = Order::create([
                'customer_id' => $customer->id,
                // Phase C (Stakeholder merge) — Customer now IS a
                // stakeholders row (see Customer model docblock), so
                // $customer->id already is the stakeholder id; no separate
                // lookup/mirror needed. See Zurie_V3_ProsERP_Adaptation_Plan.md.
                'stakeholder_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'whatsapp_number' => $customer->whatsapp_number,
                'customer_email' => $customer->email,
                'status' => $status,
                'source' => $source,
                'outlet_id' => $outlet->id,
                'total_amount' => 0,
                'currency_id' => $currency->id,
                'exchange_rate' => $exchangeRate,
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
            $lines = [];
            // Admin > Settings > Tax (falls back to config/zurie.php).
            $tax = $this->settingsService->getTax();

            // Lock every needed stock row up front in a fixed order, so two
            // carts with the same products in opposite order can't deadlock.
            $this->inventoryService->lockStockRows(array_column($items, 'productId'), $outlet->id);

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
                $this->inventoryService->decrementForOrder(
                    $product->id,
                    $line['quantity'],
                    outletId: $outlet->id,
                    referenceType: Order::class,
                    referenceId: $order->id,
                );

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

                // Phase E (VAT/Tax) — system-computed only, never trusted
                // from the client, same "authoritative pricing only" rule
                // this method already applies to price/name/cost — see
                // config/zurie.php's docblock for why this isn't a
                // client-supplied field.
                $lines[] = [
                    'product' => $product,
                    'quantity' => $line['quantity'],
                    'unitSellingPrice' => $unitSellingPrice,
                    'lineTotal' => $lineTotal,
                    'lineCost' => $lineCost,
                    'vatPercentage' => $product->vat_exempted ? 0.0 : $tax['vatPercentage'],
                ];
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

            $pricesIncludeVat = $tax['pricesIncludeVat'];
            $lineVats = $this->vatPerLine($lines, $discount, $pricesIncludeVat);
            $totalVat = round(array_sum($lineVats), 2);

            // Keyed by category id — how much of this order's revenue/cost
            // belongs to each category, so postSaleToLedger() can post
            // Sales/COGS per category's own Income/Expense ledger where
            // one's set (falling back to the global SALES/COGS ledger
            // otherwise). See resolveCategoryLedger()'s docblock.
            $categoryTotals = [];
            foreach ($lines as $index => $line) {
                $product = $line['product'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_buying_price' => $product->buying_price,
                    'unit_selling_price' => $line['unitSellingPrice'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['lineTotal'],
                    'vat_percentage' => $line['vatPercentage'],
                    'vat_amount' => $lineVats[$index],
                ]);

                $categoryTotals[$product->category_id] ??= ['revenue' => 0.0, 'cost' => 0.0];
                $categoryTotals[$product->category_id]['revenue'] += $this->lineRevenue($line['lineTotal'], $lineVats[$index], $pricesIncludeVat);
                $categoryTotals[$product->category_id]['cost'] += $line['lineCost'];
            }

            // What the customer actually pays: VAT-inclusive prices already
            // contain the VAT; otherwise it's added on top. Always net of
            // the coupon discount.
            $amountDue = round($totalRevenue - $discount + ($pricesIncludeVat ? 0.0 : $totalVat), 2);

            $order->update([
                'total_amount' => $amountDue,
                'discount_amount' => $discount,
                'vat_amount' => $totalVat,
                'prices_include_vat' => $pricesIncludeVat,
                'coupon_id' => $coupon?->id,
            ]);

            if ($coupon !== null) {
                $this->couponService->redeem($coupon);
            }

            $this->postSaleToLedger($order, $amountDue, $discount, $totalCost, $totalVat, $categoryTotals, $outlet->cost_center_id, $paymentLedgerCode, $currency->id, $exchangeRate);

            if ($totalVat > 0) {
                $this->vatService->record($order, 'output', $totalVat);
            }

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
    /**
     * @param  array<int|null, array{revenue: float, cost: float}>  $categoryTotals
     */
    private function postSaleToLedger(Order $order, float $amountDue, float $discount, float $cost, float $vat, array $categoryTotals, ?int $costCenterId, string $paymentLedgerCode, ?int $currencyId = null, ?float $exchangeRate = null): void
    {
        $paymentLedger = $this->financeService->systemLedger($paymentLedgerCode);
        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');

        $lines = [
            // Exactly what the customer pays (the order's total_amount).
            // VAT is a liability owed to the government, not revenue, so
            // it's credited to VAT Output; the category revenue lines
            // already exclude it (see lineRevenue()).
            ['ledger_id' => $paymentLedger->id, 'type' => 'debit', 'amount' => $amountDue, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $inventoryAsset->id, 'type' => 'credit', 'amount' => $cost, 'cost_center_id' => $costCenterId],
        ];

        // Revenue/cost split one Sales-credit and COGS-debit line per
        // category present in the order, each against that category's own
        // Income/Expense ledger if it has one set, else the global
        // SALES/COGS system ledgers — see resolveCategoryLedger(). Every
        // other line (payment, inventory, discount, VAT) stays a single
        // aggregate line exactly as before this split; only revenue/cost
        // classification is category-aware, which is all the feature asked
        // for. Revenue lines sum to the net sales value and cost lines to
        // $cost exactly, since $categoryTotals was built from the same items.
        foreach ($categoryTotals as $categoryId => $totals) {
            $income = $this->resolveCategoryLedger($categoryId, 'income_ledger_id', 'SALES');
            $expense = $this->resolveCategoryLedger($categoryId, 'expense_ledger_id', 'COGS');

            $lines[] = ['ledger_id' => $income->id, 'type' => 'credit', 'amount' => $totals['revenue'], 'cost_center_id' => $costCenterId];
            $lines[] = ['ledger_id' => $expense->id, 'type' => 'debit', 'amount' => $totals['cost'], 'cost_center_id' => $costCenterId];
        }

        if ($discount > 0) {
            $salesDiscounts = $this->financeService->systemLedger('SALES-DISC');
            $lines[] = ['ledger_id' => $salesDiscounts->id, 'type' => 'debit', 'amount' => $discount, 'cost_center_id' => $costCenterId];
        }

        if ($vat > 0) {
            $vatOutput = $this->financeService->systemLedger('VAT-OUT');
            $lines[] = ['ledger_id' => $vatOutput->id, 'type' => 'credit', 'amount' => $vat, 'cost_center_id' => $costCenterId];
        }

        $this->financeService->postEntry(
            $lines,
            narration: "Order {$order->order_number}",
            referenceType: Order::class,
            referenceId: $order->id,
            currencyId: $currencyId,
            exchangeRate: $exchangeRate,
        );
    }

    /**
     * VAT per order line, computed on the price after the coupon discount.
     * The discount is shared across lines in proportion to their value
     * (the last line takes the rounding remainder, so the shares add up to
     * the discount exactly), because lines can carry different VAT rates —
     * a vat_exempted product owes none.
     *
     * With VAT-inclusive prices the VAT is extracted from the discounted
     * line value (value x rate / (100 + rate)); otherwise it's added on top
     * (value x rate / 100).
     *
     * @param  array<int, array{lineTotal: float, vatPercentage: float}>  $lines
     * @return array<int, float>
     */
    private function vatPerLine(array $lines, float $discount, bool $pricesIncludeVat): array
    {
        $gross = array_sum(array_column($lines, 'lineTotal'));
        $vats = [];
        $discountLeft = $discount;
        $lastIndex = array_key_last($lines);

        foreach ($lines as $index => $line) {
            $lineDiscount = $index === $lastIndex || $gross <= 0
                ? $discountLeft
                : round($discount * $line['lineTotal'] / $gross, 2);
            $discountLeft -= $lineDiscount;

            $taxable = max(0.0, $line['lineTotal'] - $lineDiscount);
            $rate = $line['vatPercentage'];

            $vats[$index] = $rate <= 0 ? 0.0 : round(
                $pricesIncludeVat ? $taxable * $rate / (100 + $rate) : $taxable * $rate / 100,
                2,
            );
        }

        return $vats;
    }

    /**
     * The revenue a line contributes to Sales before the (separately
     * booked) discount: its full value when VAT is added on top, or its
     * value minus the VAT inside it when prices include VAT.
     */
    private function lineRevenue(float $lineTotal, float $lineVat, bool $pricesIncludeVat): float
    {
        return $pricesIncludeVat ? $lineTotal - $lineVat : $lineTotal;
    }

    /**
     * A category's own ledger for the given $field (income_ledger_id or
     * expense_ledger_id) when it has one set, else the matching global
     * system ledger ($fallbackCode). $categoryId itself can be null (a
     * product whose category was deleted after the sale — see cancel()'s
     * own category lookup) or the category row can have been deleted —
     * both fall back the same way as "field not set", never an error, so
     * a sale can never fail to post just because a category's own ledger
     * setup is incomplete or gone.
     */
    private function resolveCategoryLedger(?int $categoryId, string $field, string $fallbackCode): Ledger
    {
        if ($categoryId !== null) {
            $category = $this->categoryService->findOrNull($categoryId);
            $ledgerId = $category?->{$field};

            $ledger = $this->financeService->findLedger($ledgerId);
            if ($ledger !== null) {
                return $ledger;
            }
        }

        return $this->financeService->systemLedger($fallbackCode);
    }

    /**
     * Exact mirror of postSaleToLedger() with debit/credit swapped — used
     * by cancel() so a cancelled order's revenue/COGS/discount impact
     * nets to zero in the ledger, not just in the order's own status.
     * Discount/VAT are reconstructed from the order's own stored columns
     * (total_amount is already net of discount) since a coupon's discount
     * can't otherwise be recovered after the fact — but revenue/cost
     * themselves come from $categoryTotals (built by cancel() from the
     * order's actual line items), the same per-category split
     * postSaleToLedger() posted at sale time, so the reversal lands on
     * exactly the ledgers the original sale did.
     *
     * @param  array<int|null, array{revenue: float, cost: float}>  $categoryTotals
     */
    private function reverseSaleLedger(Order $order, float $cost, array $categoryTotals, string $paymentLedgerCode, ?int $costCenterId): void
    {
        $discount = (float) $order->discount_amount;
        $vat = (float) $order->vat_amount;

        $paymentLedger = $this->financeService->systemLedger($paymentLedgerCode);
        $inventoryAsset = $this->financeService->systemLedger('INV-ASSET');

        $lines = [
            ['ledger_id' => $paymentLedger->id, 'type' => 'credit', 'amount' => (float) $order->total_amount, 'cost_center_id' => $costCenterId],
            ['ledger_id' => $inventoryAsset->id, 'type' => 'debit', 'amount' => $cost, 'cost_center_id' => $costCenterId],
        ];

        foreach ($categoryTotals as $categoryId => $totals) {
            $income = $this->resolveCategoryLedger($categoryId, 'income_ledger_id', 'SALES');
            $expense = $this->resolveCategoryLedger($categoryId, 'expense_ledger_id', 'COGS');

            $lines[] = ['ledger_id' => $income->id, 'type' => 'debit', 'amount' => $totals['revenue'], 'cost_center_id' => $costCenterId];
            $lines[] = ['ledger_id' => $expense->id, 'type' => 'credit', 'amount' => $totals['cost'], 'cost_center_id' => $costCenterId];
        }

        if ($discount > 0) {
            $salesDiscounts = $this->financeService->systemLedger('SALES-DISC');
            $lines[] = ['ledger_id' => $salesDiscounts->id, 'type' => 'credit', 'amount' => $discount, 'cost_center_id' => $costCenterId];
        }

        if ($vat > 0) {
            $vatOutput = $this->financeService->systemLedger('VAT-OUT');
            $lines[] = ['ledger_id' => $vatOutput->id, 'type' => 'debit', 'amount' => $vat, 'cost_center_id' => $costCenterId];
        }

        $this->financeService->postEntry(
            $lines,
            narration: "Cancellation of Order {$order->order_number}",
            referenceType: Order::class,
            referenceId: $order->id,
            // Reverses in the exact currency/rate the order was originally
            // posted in — read from the order's own stored columns, not
            // re-resolved to whatever the base currency is *now* (which
            // could theoretically have changed via designateBase() between
            // the sale and its cancellation).
            currencyId: $order->currency_id,
            exchangeRate: $order->exchange_rate !== null ? (float) $order->exchange_rate : null,
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
    /**
     * Debtors report's data source — every stakeholder's total order
     * value (excluding cancelled orders and POS sales), keyed by
     * stakeholder id. POS sales are paid at the till (posted to Cash, not
     * Accounts Receivable — see cancel()'s payment-ledger choice), so they
     * were never owed and must not show up as debt. The
     * report itself (ReportService::debtors()) subtracts each
     * stakeholder's applied Receipts to get the actual outstanding
     * balance — this method only sums the "billed" side, deliberately
     * not the settled side, since Receipt is a different module's
     * concern (Extensibility Constitution, Rule 2).
     *
     * @return array<int, float> stakeholderId => totalOrderValue
     */
    public function totalsByStakeholder(): array
    {
        return Order::query()
            ->where('status', '!=', 'cancelled')
            ->where('source', '!=', 'pos')
            ->whereNotNull('stakeholder_id')
            ->selectRaw('stakeholder_id, sum(total_amount) as total')
            ->groupBy('stakeholder_id')
            ->pluck('total', 'stakeholder_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * @param  string|null  $from  inclusive 'YYYY-MM-DD'; omit for all-time
     * @param  string|null  $to  inclusive 'YYYY-MM-DD'; omit for open-ended
     */
    public function sumBySource(?string $from = null, ?string $to = null): array
    {
        return Order::query()
            ->where('status', '!=', 'cancelled')
            ->when($from !== null, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('created_at', '<=', $to))
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
     * @throws InvalidOrderTransitionException if the requested status isn't a valid next step from the order's current status
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
     * Only fires for a registered customer with a linked CustomerAccount
     * — a guest order, or one whose stakeholder never signed up, has no
     * account to notify. Best-effort: never blocks the status update
     * itself if something's off with the lookup.
     */
    private function notifyCustomerOfStatusChange(Order $order): void
    {
        $customer = $this->customerService->findForAdmin($order->customer_id);
        $account = $this->customerAccountService->findByStakeholderId($customer->id);

        if ($account !== null) {
            $this->notificationService->notify(
                $account,
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
     * @throws InvalidOrderTransitionException if the order's current status isn't in CANCELLABLE_STATUSES
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

            $this->inventoryService->lockStockRows($order->items->pluck('product_id')->all(), $order->outlet_id);

            // productId => categoryId, batched — order_items only stores
            // product_id, so the per-category split reverseSaleLedger()
            // needs (matching postSaleToLedger()'s own split at sale time)
            // has to be reconstructed via this lookup rather than read
            // directly off the item.
            $categoryIdsByProduct = $this->productService->categoryIdsFor(
                $order->items->pluck('product_id')->unique()->all(),
            );

            $totalCost = 0.0;
            $categoryTotals = [];
            foreach ($order->items as $item) {
                $this->inventoryService->restockForOrder(
                    $item->product_id,
                    $item->quantity,
                    outletId: $order->outlet_id,
                    referenceType: Order::class,
                    referenceId: $order->id,
                );
                $lineCost = (float) $item->unit_buying_price * $item->quantity;
                $totalCost += $lineCost;

                $categoryId = $categoryIdsByProduct[$item->product_id] ?? null;
                $categoryTotals[$categoryId] ??= ['revenue' => 0.0, 'cost' => 0.0];
                $categoryTotals[$categoryId]['revenue'] += $this->lineRevenue((float) $item->line_total, (float) $item->vat_amount, (bool) $order->prices_include_vat);
                $categoryTotals[$categoryId]['cost'] += $lineCost;
            }

            $paymentLedgerCode = $order->source === 'pos' ? 'CASH' : 'AR';
            $outlet = $order->outlet_id !== null ? $this->outletService->findOrFail($order->outlet_id) : null;
            $this->reverseSaleLedger($order, $totalCost, $categoryTotals, $paymentLedgerCode, $outlet?->cost_center_id);

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
