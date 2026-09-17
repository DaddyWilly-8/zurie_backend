<?php

use App\Modules\Supplier\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/suppliers', [SupplierController::class, 'index'])->middleware('permission:supplier_view');
    Route::post('admin/suppliers', [SupplierController::class, 'store'])->middleware('permission:supplier_manage');
    Route::get('admin/suppliers/{id}', [SupplierController::class, 'show'])->middleware('permission:supplier_view');
    Route::patch('admin/suppliers/{id}', [SupplierController::class, 'update'])->middleware('permission:supplier_manage');
});
