<?php

use App\Modules\Stakeholder\Controllers\StakeholderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/stakeholders', [StakeholderController::class, 'index'])
        ->middleware('permission:stakeholder_view');
    Route::get('admin/stakeholders/{id}', [StakeholderController::class, 'show'])
        ->middleware('permission:stakeholder_view');
});
