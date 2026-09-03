<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Requests\StoreOrderRequest;
use App\Modules\Order\Requests\UpdateOrderRequest;
use App\Modules\Order\Resources\OrderListResource;
use App\Modules\Order\Resources\OrderResource;
use App\Modules\Order\Services\OrderService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderService $orderService) {}

    /**
     * POST /orders — public checkout, no auth required.
     */
    public function store(StoreOrderRequest $request)
    {
        $order = $this->orderService->checkout($request->validated());

        return $this->created(new OrderResource($order));
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $filters = $request->only(['status', 'search']);

        $orders = $this->orderService->paginateAdmin($filters, $page, $pageSize);

        return $this->paginated(
            OrderListResource::collection($orders->items()),
            [
                'count' => $orders->total(),
                'page' => $orders->currentPage(),
                'pageSize' => $orders->perPage(),
            ]
        );
    }

    /**
     * GET /admin/orders/{order} — {order} resolves by order_number, not the
     * internal id (see Order::getRouteKeyName()), same as update()/cancel()
     * below.
     */
    public function show(Order $order)
    {
        return $this->ok(new OrderResource($order->load('items')));
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        $order = $this->orderService->updateStatusOrNotes($order, $request->validated());

        return $this->ok(new OrderResource($order));
    }

    /**
     * POST /admin/orders/{order}/cancel — the only way to cancel an order.
     * Not available via the generic PATCH (see UpdateOrderRequest) since
     * cancellation restocks inventory, a side effect that endpoint doesn't
     * carry.
     */
    public function cancel(Order $order)
    {
        $order = $this->orderService->cancel($order);

        return $this->ok(new OrderResource($order));
    }
}
