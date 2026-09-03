<?php

use App\Modules\Media\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// Standalone media library — also used internally by Product/Category's
// image endpoints via MediaService, not just these direct HTTP routes.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('media/upload', [MediaController::class, 'store'])->middleware('permission:media_upload');
    Route::get('media', [MediaController::class, 'index'])->middleware('permission:media_view');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->middleware('permission:media_delete');
});
