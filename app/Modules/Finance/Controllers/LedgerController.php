<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Resources\LedgerGroupResource;
use App\Modules\Finance\Services\FinanceService;
use App\Support\Http\ApiResponse;

class LedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FinanceService $financeService) {}

    /**
     * GET /admin/finance/chart-of-accounts — full ledger group tree with
     * their ledgers nested, for the admin Finance screen and for verifying
     * the seeded default chart of accounts.
     */
    public function chartOfAccounts()
    {
        return $this->ok(LedgerGroupResource::collection($this->financeService->chartOfAccounts()));
    }
}
