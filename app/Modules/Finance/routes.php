<?php

use App\Modules\Finance\Controllers\CostCenterController;
use App\Modules\Finance\Controllers\LedgerController;
use App\Modules\Finance\Controllers\LedgerGroupController;
use Illuminate\Support\Facades\Route;

// Admin-only — no public finance endpoints.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/finance/chart-of-accounts', [LedgerController::class, 'chartOfAccounts'])
        ->middleware('permission:finance_view');

    Route::get('admin/finance/ledger-groups/{ledgerGroup}', [LedgerGroupController::class, 'show'])
        ->middleware('permission:finance_view');
    Route::post('admin/finance/ledger-groups', [LedgerGroupController::class, 'store'])
        ->middleware('permission:finance_manage');
    Route::patch('admin/finance/ledger-groups/{ledgerGroup}', [LedgerGroupController::class, 'update'])
        ->middleware('permission:finance_manage');
    Route::delete('admin/finance/ledger-groups/{ledgerGroup}', [LedgerGroupController::class, 'destroy'])
        ->middleware('permission:finance_manage');

    Route::post('admin/finance/ledgers', [LedgerController::class, 'store'])
        ->middleware('permission:finance_manage');
    Route::patch('admin/finance/ledgers/{ledger}', [LedgerController::class, 'update'])
        ->middleware('permission:finance_manage');
    Route::delete('admin/finance/ledgers/{ledger}', [LedgerController::class, 'destroy'])
        ->middleware('permission:finance_manage');

    Route::get('admin/finance/cost-centers', [CostCenterController::class, 'index'])
        ->middleware('permission:finance_view');
    Route::get('admin/finance/cost-centers/{costCenter}', [CostCenterController::class, 'show'])
        ->middleware('permission:finance_view');
    Route::post('admin/finance/cost-centers', [CostCenterController::class, 'store'])
        ->middleware('permission:finance_manage');
    Route::patch('admin/finance/cost-centers/{costCenter}', [CostCenterController::class, 'update'])
        ->middleware('permission:finance_manage');
});
