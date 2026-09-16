<?php

use App\Modules\Report\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/reports/sales-by-channel', [ReportController::class, 'salesByChannel'])->middleware('permission:report_view');
    Route::get('admin/reports/low-stock', [ReportController::class, 'lowStock'])->middleware('permission:report_view');
    Route::get('admin/reports/revenue-summary', [ReportController::class, 'revenueSummary'])->middleware('permission:report_view');
});
