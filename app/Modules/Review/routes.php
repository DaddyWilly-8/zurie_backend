<?php

use App\Modules\Review\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Public — product page reviews.
Route::get('products/{productId}/reviews', [ReviewController::class, 'index']);

// Customer-only self-service.
Route::middleware('auth:customer')->group(function (): void {
    Route::post('account/reviews', [ReviewController::class, 'store']);
});

// Staff-only moderation — unaffected by the customer/staff split, still
// 'web' guard via auth:sanctum.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/reviews', [ReviewController::class, 'adminIndex'])->middleware('permission:review_view');
    Route::patch('admin/reviews/{review}', [ReviewController::class, 'updateStatus'])->middleware('permission:review_manage');
});
