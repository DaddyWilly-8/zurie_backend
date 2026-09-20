<?php

use App\Modules\Delivery\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/orders/{orderNumber}/deliveries', [DeliveryController::class, 'index'])->middleware('permission:order_view');
    Route::post('admin/orders/{orderNumber}/deliveries', [DeliveryController::class, 'store'])->middleware('permission:order_update')->middleware('idempotent');
    Route::get('admin/orders/{orderNumber}/undispatched-items', [DeliveryController::class, 'undispatchedItems'])->middleware('permission:order_view');
});
