<?php

namespace App\Modules\Transaction\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transaction\Requests\StoreReceiptRequest;
use App\Modules\Transaction\Resources\ReceiptResource;
use App\Modules\Transaction\Services\TransactionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransactionService $transactionService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));
        $receipts = $this->transactionService->paginateReceipts($page, $pageSize);

        return $this->paginated(
            ReceiptResource::collection($receipts->items()),
            ['count' => $receipts->total(), 'page' => $receipts->currentPage(), 'pageSize' => $receipts->perPage()]
        );
    }

    public function store(StoreReceiptRequest $request)
    {
        return $this->created(new ReceiptResource($this->transactionService->createReceipt($request->validated())));
    }

    public function show(int $id)
    {
        return $this->ok(new ReceiptResource($this->transactionService->findReceipt($id)));
    }

    public function destroy(int $id)
    {
        $this->transactionService->deleteReceipt($this->transactionService->findReceipt($id));

        return $this->ok(['message' => 'Receipt deleted.']);
    }
}
