<?php

use App\Modules\Inventory\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

// Endpoint path kept under /products/{id}/inventory for frontend continuity
// (per zurie-api-contract.md) even though it's served entirely by the
// Inventory module internally.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('products/{id}/inventory', [InventoryController::class, 'show'])->middleware('permission:inventory_view');
    Route::patch('products/{id}/inventory', [InventoryController::class, 'update'])->middleware('permission:inventory_update');
});
