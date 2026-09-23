<?php

use App\Modules\Account\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

// No permission gating — self-service, customer-only ('customer' guard),
// scoped to the authenticated account's own linked Customer record only
// (see AccountController's docblock).
Route::middleware('auth:customer')->group(function (): void {
    Route::get('account/profile', [AccountController::class, 'profile']);
    Route::get('account/orders', [AccountController::class, 'orders']);
});
