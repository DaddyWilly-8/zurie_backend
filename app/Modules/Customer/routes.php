<?php

use App\Modules\Customer\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

// No POST /customers — a customer record is only ever created as a side
// effect of order placement (Order module, not yet built), per
// zurie-backend-implementation-spec.md §6.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/customers', [CustomerController::class, 'index'])->middleware('permission:customer_view');
    Route::get('admin/customers/{id}', [CustomerController::class, 'show'])->middleware('permission:customer_view');
});
