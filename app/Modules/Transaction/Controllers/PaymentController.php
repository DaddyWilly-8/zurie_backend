<?php

namespace App\Modules\Transaction\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transaction\Requests\StorePaymentRequest;
use App\Modules\Transaction\Resources\PaymentResource;
use App\Modules\Transaction\Services\TransactionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransactionService $transactionService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));
        $payments = $this->transactionService->paginatePayments($page, $pageSize);

        return $this->paginated(
            PaymentResource::collection($payments->items()),
            ['count' => $payments->total(), 'page' => $payments->currentPage(), 'pageSize' => $payments->perPage()]
        );
    }

    public function store(StorePaymentRequest $request)
    {
        return $this->created(new PaymentResource($this->transactionService->createPayment($request->validated())));
    }

    public function show(int $id)
    {
        return $this->ok(new PaymentResource($this->transactionService->findPayment($id)));
    }

    public function destroy(int $id)
    {
        $this->transactionService->deletePayment($this->transactionService->findPayment($id));

        return $this->ok(['message' => 'Payment deleted.']);
    }
}
