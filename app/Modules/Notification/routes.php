<?php

use App\Modules\Notification\Controllers\NewsletterController;
use App\Modules\Notification\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::post('newsletter', [NewsletterController::class, 'subscribe'])->middleware('throttle:public-form');

Route::middleware('auth:customer')->group(function (): void {
    Route::get('account/notifications', [NotificationController::class, 'index']);
    Route::post('account/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('account/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});

// Staff: every signed-in staff user can read their own notifications —
// which ones they receive is already decided by permission when they're
// sent (NotificationService::notifyStaff()).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/notifications', [NotificationController::class, 'staffIndex']);
    Route::post('admin/notifications/read-all', [NotificationController::class, 'staffMarkAllRead']);
    Route::patch('admin/notifications/{notification}/read', [NotificationController::class, 'staffMarkRead']);

    // Subscribers are customer contact data — same permission as the
    // customer list.
    Route::get('admin/newsletter-subscribers', [NewsletterController::class, 'index'])->middleware('permission:customer_view');
});
