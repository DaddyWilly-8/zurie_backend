<?php

use App\Modules\Wishlist\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('account/wishlist', [WishlistController::class, 'index']);
    Route::post('account/wishlist', [WishlistController::class, 'store']);
    Route::delete('account/wishlist/{productId}', [WishlistController::class, 'destroy']);
});
