<?php

use App\Modules\Faq\Controllers\FaqController;
use Illuminate\Support\Facades\Route;

// Public — storefront FAQ page.
Route::get('faq', [FaqController::class, 'index']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/faq', [FaqController::class, 'adminIndex'])->middleware('permission:faq_view');
    Route::post('admin/faq', [FaqController::class, 'store'])->middleware('permission:faq_create');
    Route::patch('admin/faq/{faq}', [FaqController::class, 'update'])->middleware('permission:faq_update');
    Route::delete('admin/faq/{faq}', [FaqController::class, 'destroy'])->middleware('permission:faq_delete');
});
