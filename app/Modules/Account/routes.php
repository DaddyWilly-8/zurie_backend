<?php

use App\Modules\Account\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

// No permission gating — self-service, scoped to the authenticated user's
// own linked Customer record only (see AccountController's docblock).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('account/profile', [AccountController::class, 'profile']);
    Route::get('account/orders', [AccountController::class, 'orders']);
});
