<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Requests\StoreGrnRequest;
use App\Modules\Procurement\Resources\GrnResource;
use App\Modules\Procurement\Services\GrnService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/** Create/list/show, plus delete ("un-receive") — see GrnService's docblock. Never editable. */
class GrnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GrnService $grnService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $purchaseOrderId = $request->query('purchaseOrderId');
        $grns = $this->grnService->paginateAdmin($page, $pageSize, $purchaseOrderId !== null ? (int) $purchaseOrderId : null);

        return $this->paginated(
            GrnResource::collection($grns->items()),
            ['count' => $grns->total(), 'page' => $grns->currentPage(), 'pageSize' => $grns->perPage()]
        );
    }

    public function store(StoreGrnRequest $request)
    {
        $grn = $this->grnService->create($request->validated());

        return $this->created(new GrnResource($grn));
    }

    public function show(int $id)
    {
        return $this->ok(new GrnResource($this->grnService->findOrFail($id)));
    }

    public function destroy(int $id)
    {
        $this->grnService->delete($this->grnService->findOrFail($id));

        return $this->ok(['message' => 'GRN un-received.']);
    }
}
