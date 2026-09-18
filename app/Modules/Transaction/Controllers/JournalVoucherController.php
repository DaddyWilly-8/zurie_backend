<?php

namespace App\Modules\Transaction\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transaction\Requests\StoreJournalVoucherRequest;
use App\Modules\Transaction\Resources\JournalVoucherResource;
use App\Modules\Transaction\Services\TransactionService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class JournalVoucherController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransactionService $transactionService) {}

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));
        $vouchers = $this->transactionService->paginateJournalVouchers($page, $pageSize);

        return $this->paginated(
            JournalVoucherResource::collection($vouchers->items()),
            ['count' => $vouchers->total(), 'page' => $vouchers->currentPage(), 'pageSize' => $vouchers->perPage()]
        );
    }

    public function store(StoreJournalVoucherRequest $request)
    {
        return $this->created(new JournalVoucherResource($this->transactionService->createJournalVoucher($request->validated())));
    }

    public function show(int $id)
    {
        return $this->ok(new JournalVoucherResource($this->transactionService->findJournalVoucher($id)));
    }

    public function destroy(int $id)
    {
        $this->transactionService->deleteJournalVoucher($this->transactionService->findJournalVoucher($id));

        return $this->ok(['message' => 'Journal voucher deleted.']);
    }
}
