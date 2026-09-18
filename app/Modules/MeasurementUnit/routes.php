<?php

use App\Modules\MeasurementUnit\Controllers\MeasurementUnitController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/measurement-units', [MeasurementUnitController::class, 'index'])
        ->middleware('permission:measurement_unit_view');
    Route::get('admin/measurement-units/{measurementUnit}', [MeasurementUnitController::class, 'show'])
        ->middleware('permission:measurement_unit_view');
    Route::post('admin/measurement-units', [MeasurementUnitController::class, 'store'])
        ->middleware('permission:measurement_unit_manage');
    Route::patch('admin/measurement-units/{measurementUnit}', [MeasurementUnitController::class, 'update'])
        ->middleware('permission:measurement_unit_manage');
});
