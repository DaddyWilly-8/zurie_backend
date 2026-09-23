<?php

use App\Modules\Notification\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:customer')->group(function (): void {
    Route::get('account/notifications', [NotificationController::class, 'index']);
    Route::patch('account/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});
