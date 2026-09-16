<?php

use App\Modules\Enquiry\Controllers\EnquiryController;
use Illuminate\Support\Facades\Route;

// Public — the storefront contact form.
Route::post('contact', [EnquiryController::class, 'store']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/enquiries', [EnquiryController::class, 'index'])->middleware('permission:enquiry_view');
    Route::patch('admin/enquiries/{enquiry}', [EnquiryController::class, 'update'])->middleware('permission:enquiry_update');
});
