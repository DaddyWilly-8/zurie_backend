<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dispatch tracking only — no inventory or ledger effect (Order already
 * decremented stock at sale time; see the migration's docblock for why).
 * Reads Order/OrderItem directly rather than through OrderService, the
 * same established precedent GrnService already sets by querying
 * Supplier directly for read-only cross-module lookups.
 */
class DeliveryService
{
    private static function generateDeliveryNumber(int $id): string
    {
        return 'DLV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data  orderNumber, dateDispatched?, notes?, lines: array<{orderItemId, quantityDispatched}>
     */
    public function create(array $data): Delivery
    {
        if (empty($data['lines'])) {
            throw ValidationException::withMessages(['lines' => 'A delivery needs at least one dispatched line.']);
        }

        return DB::transaction(function () use ($data) {
            $order = Order::query()->with('items')->where('order_number', $data['orderNumber'])->firstOrFail();

            $delivery = Delivery::create([
                'order_id' => $order->id,
                'date_dispatched' => $data['dateDispatched'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);
            $delivery->update(['delivery_number' => self::generateDeliveryNumber($delivery->id)]);

            foreach ($data['lines'] as $line) {
                /** @var OrderItem|null $item */
                $item = $order->items->firstWhere('id', (int) $line['orderItemId']);
                if ($item === null) {
                    throw ValidationException::withMessages(['lines' => "Order item #{$line['orderItemId']} does not belong to this order."]);
                }

                $alreadyDispatched = (float) DB::table('delivery_order_item')
                    ->where('order_item_id', $item->id)
                    ->sum('quantity_dispatched');

                $undispatched = $item->quantity - $alreadyDispatched;
                $quantityDispatched = (float) $line['quantityDispatched'];

                if ($quantityDispatched > $undispatched + 0.0001) {
                    throw ValidationException::withMessages([
                        'lines' => "Cannot dispatch {$quantityDispatched} for item #{$item->id} — only {$undispatched} remains undispatched.",
                    ]);
                }

                $delivery->lines()->create([
                    'order_item_id' => $item->id,
                    'quantity_dispatched' => $quantityDispatched,
                ]);
            }

            activity('delivery')
                ->performedOn($delivery)
                ->event('created')
                ->log("Delivery {$delivery->delivery_number} dispatched against order {$order->order_number}");

            return $delivery->load('lines');
        });
    }

    /**
     * Every Delivery against one Order, each line enriched with product
     * name/quantity ordered so the Delivery tab is self-contained
     * (product names live in Product's own module — OrderItem already
     * snapshots product_name at order time, so no cross-module lookup is
     * needed here either).
     */
    public function forOrder(int $orderId): \Illuminate\Support\Collection
    {
        return Delivery::query()
            ->where('order_id', $orderId)
            ->with('lines')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Delivery $delivery) => [
                'id' => $delivery->id,
                'deliveryNumber' => $delivery->delivery_number,
                'dateDispatched' => $delivery->date_dispatched->toDateString(),
                'notes' => $delivery->notes,
                'lines' => $delivery->lines->map(function (\App\Modules\Delivery\Models\DeliveryOrderItem $line) {
                    $orderItem = OrderItem::find($line->order_item_id);

                    return [
                        'orderItemId' => $line->order_item_id,
                        'productName' => $orderItem?->product_name ?? 'Unknown product',
                        'quantityDispatched' => $line->quantity_dispatched,
                    ];
                }),
            ]);
    }

    /**
     * Per-order-item undispatched quantities — the dispatch form's
     * "X remaining of Y ordered" display, same pattern as
     * PurchaseOrderItemResource::remainingQuantity().
     *
     * @return array<int, array{orderItemId: int, productName: string, quantity: int, dispatchedQuantity: int, remainingQuantity: int}>
     */
    public function undispatchedItemsForOrder(string $orderNumber): array
    {
        $order = Order::query()->with('items')->where('order_number', $orderNumber)->firstOrFail();

        return $order->items->map(function (OrderItem $item) {
            $dispatched = (float) DB::table('delivery_order_item')
                ->where('order_item_id', $item->id)
                ->sum('quantity_dispatched');

            return [
                'orderItemId' => $item->id,
                'productName' => $item->product_name,
                'quantity' => $item->quantity,
                'dispatchedQuantity' => $dispatched,
                'remainingQuantity' => max(0, $item->quantity - $dispatched),
            ];
        })->all();
    }
}
