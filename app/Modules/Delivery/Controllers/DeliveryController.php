<?php

namespace App\Modules\Delivery\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Requests\StoreDeliveryRequest;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Order\Models\Order;
use App\Support\Http\ApiResponse;

class DeliveryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeliveryService $deliveryService) {}

    /** GET /admin/orders/{orderNumber}/deliveries — the order detail view's Delivery tab. */
    public function index(string $orderNumber)
    {
        return $this->ok($this->deliveryService->forOrder(
            Order::where('order_number', $orderNumber)->firstOrFail()->id,
        ));
    }

    public function store(StoreDeliveryRequest $request, string $orderNumber)
    {
        $delivery = $this->deliveryService->create([
            'orderNumber' => $orderNumber,
            ...$request->validated(),
        ]);

        return $this->created([
            'id' => $delivery->id,
            'deliveryNumber' => $delivery->delivery_number,
            'dateDispatched' => $delivery->date_dispatched->toDateString(),
            'notes' => $delivery->notes,
        ]);
    }

    /** GET /admin/orders/{orderNumber}/undispatched-items — the dispatch form's remaining-quantity picker. */
    public function undispatchedItems(string $orderNumber)
    {
        return $this->ok($this->deliveryService->undispatchedItemsForOrder($orderNumber));
    }
}
