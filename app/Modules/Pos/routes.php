<?php

use App\Modules\Pos\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('admin/pos/sale', [PosController::class, 'store'])->middleware('permission:pos_sale');
});
