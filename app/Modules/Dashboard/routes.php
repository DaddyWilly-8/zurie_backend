<?php

use App\Modules\Dashboard\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Not nested under /settings/ — Dashboard is its own module. admin/ prefix
// per the standard from §2.4.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/dashboard-overview', [DashboardController::class, 'overview'])
        ->middleware('permission:dashboard_view');
});
