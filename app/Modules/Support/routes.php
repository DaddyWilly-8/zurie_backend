<?php

use App\Modules\Support\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

// Customer-facing — auth:customer (Laravel's plain Authenticate, not
// Sanctum), same pattern as Review/Wishlist/Account. A customer only ever
// sees/creates their own tickets.
Route::middleware('auth:customer')->group(function (): void {
    Route::post('support/tickets', [SupportTicketController::class, 'store']);
    Route::get('support/tickets', [SupportTicketController::class, 'indexForCustomer']);
    Route::get('support/tickets/{id}', [SupportTicketController::class, 'showForCustomer']);
});

// Staff-facing — auth:sanctum + permission, same pattern as every other
// admin-gated module.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/support/tickets', [SupportTicketController::class, 'indexForStaff'])->middleware('permission:support_ticket_view');
    Route::get('admin/support/tickets/{id}', [SupportTicketController::class, 'showForStaff'])->middleware('permission:support_ticket_view');
    Route::get('admin/support/tickets/{id}/reassignments', [SupportTicketController::class, 'reassignments'])->middleware('permission:support_ticket_view');

    Route::post('admin/support/tickets/{id}/activate', [SupportTicketController::class, 'activate'])->middleware('permission:support_ticket_manage');
    Route::post('admin/support/tickets/{id}/reassign', [SupportTicketController::class, 'reassign'])->middleware('permission:support_ticket_manage');
    Route::post('admin/support/tickets/{id}/close', [SupportTicketController::class, 'close'])->middleware('permission:support_ticket_manage');
});
