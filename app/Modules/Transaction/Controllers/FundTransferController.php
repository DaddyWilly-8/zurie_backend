<?php

namespace App\Modules\Transaction\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transaction\Requests\StoreFundTransferRequest;
use App\Modules\Transaction\Resources\FundTransferResource;
use App\Modules\Transaction\Services\TransactionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class FundTransferController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransactionService $transactionService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = $this->pageSize($request);
        $transfers = $this->transactionService->paginateFundTransfers($page, $pageSize);

        return $this->paginated(
            FundTransferResource::collection($transfers->items()),
            ['count' => $transfers->total(), 'page' => $transfers->currentPage(), 'pageSize' => $transfers->perPage()]
        );
    }

    public function store(StoreFundTransferRequest $request)
    {
        return $this->created(new FundTransferResource($this->transactionService->createFundTransfer($request->validated())));
    }

    public function show(int $id)
    {
        return $this->ok(new FundTransferResource($this->transactionService->findFundTransfer($id)));
    }

    public function destroy(int $id)
    {
        $this->transactionService->deleteFundTransfer($this->transactionService->findFundTransfer($id));

        return $this->ok(['message' => 'Fund transfer deleted.']);
    }
}
