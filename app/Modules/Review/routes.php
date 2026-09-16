<?php

use App\Modules\Review\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Public — product page reviews.
Route::get('products/{productId}/reviews', [ReviewController::class, 'index']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('account/reviews', [ReviewController::class, 'store']);

    Route::get('admin/reviews', [ReviewController::class, 'adminIndex'])->middleware('permission:review_view');
    Route::patch('admin/reviews/{review}', [ReviewController::class, 'updateStatus'])->middleware('permission:review_manage');
});
