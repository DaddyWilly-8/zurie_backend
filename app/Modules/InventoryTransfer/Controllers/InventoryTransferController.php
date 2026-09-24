<?php

namespace App\Modules\InventoryTransfer\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryTransfer\Requests\StoreInventoryTransferRequest;
use App\Modules\InventoryTransfer\Resources\InventoryTransferResource;
use App\Modules\InventoryTransfer\Services\InventoryTransferService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/** Create/list/show only — append-only, same reasoning as GRN (a physical stock movement isn't arbitrarily edited/deleted once posted). */
class InventoryTransferController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InventoryTransferService $inventoryTransferService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);

        $transfers = $this->inventoryTransferService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            InventoryTransferResource::collection($transfers->items()),
            ['count' => $transfers->total(), 'page' => $transfers->currentPage(), 'pageSize' => $transfers->perPage()]
        );
    }

    public function store(StoreInventoryTransferRequest $request)
    {
        $transfer = $this->inventoryTransferService->create($request->validated());

        return $this->created(new InventoryTransferResource($transfer));
    }

    public function show(int $id)
    {
        return $this->ok(new InventoryTransferResource($this->inventoryTransferService->findOrFail($id)));
    }
}
