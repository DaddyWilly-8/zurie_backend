<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchase\Requests\StorePurchaseRequest;
use App\Modules\Purchase\Resources\PurchaseResource;
use App\Modules\Purchase\Services\PurchaseService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseService $purchaseService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $purchases = $this->purchaseService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            PurchaseResource::collection($purchases->items()),
            ['count' => $purchases->total(), 'page' => $purchases->currentPage(), 'pageSize' => $purchases->perPage()]
        );
    }

    public function store(StorePurchaseRequest $request)
    {
        $purchase = $this->purchaseService->create($request->validated());

        return $this->created(new PurchaseResource($purchase));
    }

    public function show(string $purchaseNumber)
    {
        return $this->ok(new PurchaseResource($this->purchaseService->findByPurchaseNumber($purchaseNumber)));
    }
}
