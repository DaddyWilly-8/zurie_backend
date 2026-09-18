<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Requests\StorePurchaseOrderRequest;
use App\Modules\Procurement\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Procurement\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseOrderService $purchaseOrderService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $purchaseOrders = $this->purchaseOrderService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            PurchaseOrderResource::collection($purchaseOrders->items()),
            ['count' => $purchaseOrders->total(), 'page' => $purchaseOrders->currentPage(), 'pageSize' => $purchaseOrders->perPage()]
        );
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        $purchaseOrder = $this->purchaseOrderService->create($request->validated());

        return $this->created(new PurchaseOrderResource($purchaseOrder));
    }

    public function show(int $id)
    {
        return $this->ok(new PurchaseOrderResource($this->purchaseOrderService->findOrFail($id)));
    }

    public function update(UpdatePurchaseOrderRequest $request, int $id)
    {
        $purchaseOrder = $this->purchaseOrderService->update($this->purchaseOrderService->findOrFail($id), $request->validated());

        return $this->ok(new PurchaseOrderResource($purchaseOrder));
    }

    public function destroy(int $id)
    {
        $this->purchaseOrderService->delete($this->purchaseOrderService->findOrFail($id));

        return $this->ok(['message' => 'Purchase order deleted.']);
    }

    public function close(int $id)
    {
        $purchaseOrder = $this->purchaseOrderService->close($this->purchaseOrderService->findOrFail($id));

        return $this->ok(new PurchaseOrderResource($purchaseOrder));
    }

    public function reopen(int $id)
    {
        $purchaseOrder = $this->purchaseOrderService->reopen($this->purchaseOrderService->findOrFail($id));

        return $this->ok(new PurchaseOrderResource($purchaseOrder));
    }

    public function cancel(int $id)
    {
        $purchaseOrder = $this->purchaseOrderService->cancel($this->purchaseOrderService->findOrFail($id));

        return $this->ok(new PurchaseOrderResource($purchaseOrder));
    }
}
