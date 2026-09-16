<?php

use App\Modules\Finance\Controllers\CostCenterController;
use App\Modules\Finance\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

// Admin-only — no public finance endpoints. Phase 0.1/0.2 slice: read-only
// chart of accounts + cost center CRUD. Journal entries / postings land in
// Phase 0.4.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/finance/chart-of-accounts', [LedgerController::class, 'chartOfAccounts'])
        ->middleware('permission:finance_view');

    Route::get('admin/finance/cost-centers', [CostCenterController::class, 'index'])
        ->middleware('permission:finance_view');
    Route::post('admin/finance/cost-centers', [CostCenterController::class, 'store'])
        ->middleware('permission:finance_manage');
});
