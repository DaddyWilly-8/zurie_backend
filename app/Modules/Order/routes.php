<?php

use App\Modules\Order\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// POST /orders is public checkout — no auth. Every admin-gated route is
// under admin/ — as of this module, that's the standard for ALL admin
// routes going forward, not just the ones that happen to collide with a
// public counterpart (see zurie-backend-implementation-spec.md §17).
// `throttle:checkout` (15/min, keyed by IP — see AppServiceProvider) is on
// top of the generic 60/min `api` limiter, not instead of it — see
// zurie-backend-security-audit.md, item #5.
Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:checkout')->middleware('idempotent');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/orders', [OrderController::class, 'index'])->middleware('permission:order_view');

    // {order} resolves by order_number, not the internal id — see
    // Order::getRouteKeyName(). Applies to all three routes below.
    Route::get('admin/orders/{order}', [OrderController::class, 'show'])->middleware('permission:order_view');
    Route::get('admin/orders/{order}/receipts', [OrderController::class, 'receipts'])->middleware('permission:order_view');
    Route::patch('admin/orders/{order}', [OrderController::class, 'update'])->middleware('permission:order_update');

    // Dedicated cancel action, not the generic PATCH above — cancellation
    // restocks inventory, a side effect the generic status/notes update
    // doesn't carry. Reuses order_update rather than a separate permission
    // key for now (per project decision) — revisit if cancellation ever
    // needs to be grantable independently of routine status updates.
    Route::post('admin/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:order_update');
});
