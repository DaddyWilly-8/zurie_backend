<?php

use App\Modules\Report\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/reports/sales-by-channel', [ReportController::class, 'salesByChannel'])->middleware('permission:report_view');
    Route::get('admin/reports/low-stock', [ReportController::class, 'lowStock'])->middleware('permission:report_view');
    Route::get('admin/reports/revenue-summary', [ReportController::class, 'revenueSummary'])->middleware('permission:report_view');
    Route::get('admin/reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('permission:report_view');
    Route::get('admin/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('permission:report_view');
    Route::get('admin/reports/inventory-value', [ReportController::class, 'inventoryValue'])->middleware('permission:report_view');
    Route::get('admin/reports/debtors', [ReportController::class, 'debtors'])->middleware('permission:report_view');
    Route::get('admin/reports/creditors', [ReportController::class, 'creditors'])->middleware('permission:report_view');
    Route::get('admin/reports/purchase-summary', [ReportController::class, 'purchaseSummary'])->middleware('permission:report_view');
    Route::get('admin/reports/store-stock/{outletId}', [ReportController::class, 'storeStock'])->middleware('permission:report_view');
});
