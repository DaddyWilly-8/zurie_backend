<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Requests\StorePurchaseOrderRequest;
use App\Modules\Procurement\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Procurement\Resources\PurchaseOrderResource;
use App\Modules\Procurement\Services\GrnService;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\Transaction\Services\TransactionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly GrnService $grnService,
        private readonly TransactionService $transactionService,
    ) {}

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

    /**
     * "Instant Receive" — the controller-level orchestration point for the
     * old standalone Purchases flow's one-step behavior, now built on
     * PurchaseOrder+Grn instead of a separate model. Deliberately
     * orchestrated here rather than inside PurchaseOrderService itself:
     * GrnService already depends on PurchaseOrderService (for
     * recomputeStatus()), so having PurchaseOrderService call GrnService
     * back would be a circular service dependency. Wrapped in one
     * transaction so a request with instantReceive:true either fully
     * succeeds (PO created AND fully received) or fully rolls back —
     * never a PO left dangling half-received.
     */
    public function store(StorePurchaseOrderRequest $request)
    {
        $data = $request->validated();
        $instantReceive = (bool) ($data['instantReceive'] ?? false);

        $purchaseOrder = DB::transaction(function () use ($data, $instantReceive) {
            $purchaseOrder = $this->purchaseOrderService->create($data);

            if ($instantReceive) {
                $this->grnService->create([
                    'purchaseOrderId' => $purchaseOrder->id,
                    'lines' => $purchaseOrder->items->map(fn ($item) => [
                        'purchaseOrderItemId' => $item->id,
                        'quantityReceived' => $item->quantity,
                    ])->all(),
                ]);
            }

            return $purchaseOrder;
        });

        return $this->created(new PurchaseOrderResource($this->purchaseOrderService->findOrFail($purchaseOrder->id)));
    }

    public function show(int $id)
    {
        return $this->ok(new PurchaseOrderResource($this->purchaseOrderService->findOrFail($id)));
    }

    /** GET /admin/purchase-orders/{id}/payments — the Purchase Order detail view's Payments tab. */
    public function payments(int $id)
    {
        return $this->ok($this->transactionService->paymentsForPurchaseOrder($id));
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
