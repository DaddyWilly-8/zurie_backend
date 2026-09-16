<?php

use App\Modules\CashierSession\Controllers\CashierSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/cashier-sessions', [CashierSessionController::class, 'index'])->middleware('permission:cashier_session_view');
    Route::get('admin/cashier-sessions/current', [CashierSessionController::class, 'current'])->middleware('permission:cashier_session_view');
    Route::post('admin/cashier-sessions/open', [CashierSessionController::class, 'open'])->middleware('permission:cashier_session_manage');
    Route::post('admin/cashier-sessions/{cashierSession}/close', [CashierSessionController::class, 'close'])->middleware('permission:cashier_session_manage');
});
