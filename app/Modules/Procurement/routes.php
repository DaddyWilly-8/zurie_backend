<?php

use App\Modules\Procurement\Controllers\GrnController;
use App\Modules\Procurement\Controllers\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_order_view');
    Route::post('admin/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_order_create');
    Route::get('admin/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_order_view');
    Route::patch('admin/purchase-orders/{id}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase_order_update');
    Route::delete('admin/purchase-orders/{id}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchase_order_update');
    Route::post('admin/purchase-orders/{id}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase_order_update');
    Route::post('admin/purchase-orders/{id}/reopen', [PurchaseOrderController::class, 'reopen'])->middleware('permission:purchase_order_update');
    Route::post('admin/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_order_update');

    Route::get('admin/grns', [GrnController::class, 'index'])->middleware('permission:grn_view');
    Route::post('admin/grns', [GrnController::class, 'store'])->middleware('permission:grn_create');
    Route::get('admin/grns/{id}', [GrnController::class, 'show'])->middleware('permission:grn_view');
});
