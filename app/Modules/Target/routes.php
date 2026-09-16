<?php

use App\Modules\Target\Controllers\TargetController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/targets', [TargetController::class, 'index'])->middleware('permission:target_view');
    Route::post('admin/targets', [TargetController::class, 'store'])->middleware('permission:target_manage');
    Route::get('admin/targets/achievement', [TargetController::class, 'achievement'])->middleware('permission:target_view');
});
