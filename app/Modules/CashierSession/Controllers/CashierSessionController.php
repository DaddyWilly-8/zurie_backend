<?php

namespace App\Modules\CashierSession\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashierSession\Models\CashierSession;
use App\Modules\CashierSession\Requests\CloseCashierSessionRequest;
use App\Modules\CashierSession\Requests\OpenCashierSessionRequest;
use App\Modules\CashierSession\Resources\CashierSessionResource;
use App\Modules\CashierSession\Services\CashierSessionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class CashierSessionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashierSessionService $cashierSessionService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $sessions = $this->cashierSessionService->paginateAdmin($page, $pageSize);

        return $this->paginated(
            CashierSessionResource::collection($sessions->items()),
            ['count' => $sessions->total(), 'page' => $sessions->currentPage(), 'pageSize' => $sessions->perPage()]
        );
    }

    public function open(OpenCashierSessionRequest $request)
    {
        $data = $request->validated();
        $session = $this->cashierSessionService->open(
            (int) $data['outletId'],
            (float) $data['openingBalance'],
            $request->user()->id,
        );

        return $this->created(new CashierSessionResource($session));
    }

    public function close(CloseCashierSessionRequest $request, CashierSession $cashierSession)
    {
        $session = $this->cashierSessionService->close(
            $cashierSession,
            (float) $request->validated()['closingBalance'],
            $request->user()->id,
        );

        return $this->ok(new CashierSessionResource($session));
    }

    public function current(Request $request)
    {
        $outletId = (int) $request->query('outletId');
        $session = $this->cashierSessionService->currentForOutlet($outletId);

        return $this->ok(new CashierSessionResource($session));
    }
}
