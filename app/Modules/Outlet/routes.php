<?php

use App\Modules\Outlet\Controllers\SalesOutletController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/outlets', [SalesOutletController::class, 'index'])->middleware('permission:outlet_view');
    Route::get('admin/outlets/{outlet}', [SalesOutletController::class, 'show'])->middleware('permission:outlet_view');
    Route::post('admin/outlets', [SalesOutletController::class, 'store'])->middleware('permission:outlet_manage');
    Route::patch('admin/outlets/{outlet}', [SalesOutletController::class, 'update'])->middleware('permission:outlet_manage');
});
