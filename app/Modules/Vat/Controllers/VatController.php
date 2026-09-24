<?php

namespace App\Modules\Vat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vat\Resources\VatTransactionResource;
use App\Modules\Vat\Services\VatService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

/** Read-only — see VatService's docblock for why there's no store/update/delete here. */
class VatController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VatService $vatService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);
        $type = $request->query('type');

        $transactions = $this->vatService->paginate($type, $page, $pageSize);

        return $this->paginated(
            VatTransactionResource::collection($transactions->items()),
            ['count' => $transactions->total(), 'page' => $transactions->currentPage(), 'pageSize' => $transactions->perPage()]
        );
    }

    public function summary(Request $request)
    {
        return $this->ok($this->vatService->summary($request->query('from'), $request->query('to')));
    }
}
