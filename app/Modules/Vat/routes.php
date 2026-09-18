<?php

use App\Modules\Vat\Controllers\VatController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/vat-transactions', [VatController::class, 'index'])->middleware('permission:vat_view');
    Route::get('admin/vat-transactions/summary', [VatController::class, 'summary'])->middleware('permission:vat_view');
});
