<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Resources\OrderResource;
use App\Modules\Order\Services\OrderService;
use App\Modules\Pos\Requests\StorePosSaleRequest;
use App\Support\Http\ApiResponse;

/**
 * Thin entrypoint only — the actual sale mechanics (pricing, inventory,
 * ledger posting) live in OrderService::posSale(), the same module that
 * owns website checkout. POS doesn't maintain a separate product/order
 * database — see Zurie_V2_Architecture_Design (2).md §14.
 */
class PosController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderService $orderService) {}

    public function store(StorePosSaleRequest $request)
    {
        $order = $this->orderService->posSale($request->validated());

        return $this->created(new OrderResource($order));
    }
}
