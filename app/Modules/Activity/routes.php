<?php

use App\Modules\Activity\Controllers\ActivityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/activity', [ActivityController::class, 'index'])
        ->middleware('permission:activity_view');
});
