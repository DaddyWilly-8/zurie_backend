<?php

use App\Modules\InventoryTransfer\Controllers\InventoryTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/inventory-transfers', [InventoryTransferController::class, 'index'])->middleware('permission:inventory_transfer_view');
    Route::post('admin/inventory-transfers', [InventoryTransferController::class, 'store'])->middleware('permission:inventory_transfer_create')->middleware('idempotent');
    Route::get('admin/inventory-transfers/{id}', [InventoryTransferController::class, 'show'])->middleware('permission:inventory_transfer_view');
});
