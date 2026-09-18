<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Ledger;
use App\Modules\Finance\Requests\StoreLedgerRequest;
use App\Modules\Finance\Requests\UpdateLedgerRequest;
use App\Modules\Finance\Resources\LedgerGroupResource;
use App\Modules\Finance\Resources\LedgerResource;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Finance\Services\LedgerService;
use App\Support\Http\ApiResponse;

class LedgerController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FinanceService $financeService,
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * GET /admin/finance/chart-of-accounts — full ledger group tree with
     * their ledgers nested, for the admin Finance screen and for verifying
     * the seeded default chart of accounts.
     */
    public function chartOfAccounts()
    {
        return $this->ok(LedgerGroupResource::collection($this->financeService->chartOfAccounts()));
    }

    public function store(StoreLedgerRequest $request)
    {
        $ledger = $this->ledgerService->create($request->validated());

        return $this->created(new LedgerResource($ledger));
    }

    public function update(UpdateLedgerRequest $request, Ledger $ledger)
    {
        $ledger = $this->ledgerService->update($ledger, $request->validated());

        return $this->ok(new LedgerResource($ledger));
    }

    public function destroy(Ledger $ledger)
    {
        $this->ledgerService->delete($ledger);

        return $this->ok(['deleted' => true]);
    }
}
