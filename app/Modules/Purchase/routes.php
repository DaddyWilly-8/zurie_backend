<?php

use App\Modules\Purchase\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/purchases', [PurchaseController::class, 'index'])->middleware('permission:purchase_view');
    Route::post('admin/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchase_create');
    Route::get('admin/purchases/{purchaseNumber}', [PurchaseController::class, 'show'])->middleware('permission:purchase_view');
});
