<?php

use App\Modules\Coupon\Controllers\CouponController;
use Illuminate\Support\Facades\Route;

// Public — checkout can preview a coupon before submitting the order.
Route::post('coupons/preview', [CouponController::class, 'preview']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/coupons', [CouponController::class, 'index'])->middleware('permission:coupon_view');
    Route::post('admin/coupons', [CouponController::class, 'store'])->middleware('permission:coupon_manage');
    Route::patch('admin/coupons/{coupon}', [CouponController::class, 'updateActive'])->middleware('permission:coupon_manage');
});
