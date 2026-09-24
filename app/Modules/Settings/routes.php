<?php

use App\Modules\Settings\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Public — the storefront homepage's one call for brand/contact/homepage/
// policies all at once.
Route::get('settings', [SettingsController::class, 'overview']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/settings/brand', [SettingsController::class, 'showBrand'])->middleware('permission:settings_manage');
    Route::put('admin/settings/brand', [SettingsController::class, 'updateBrand'])->middleware('permission:settings_manage');
    Route::post('admin/settings/brand/logo', [SettingsController::class, 'uploadBrandLogo'])->middleware('permission:settings_manage');

    Route::get('admin/settings/contact', [SettingsController::class, 'showContact'])->middleware('permission:settings_manage');
    Route::put('admin/settings/contact', [SettingsController::class, 'updateContact'])->middleware('permission:settings_manage');

    Route::get('admin/settings/homepage', [SettingsController::class, 'showHomepage'])->middleware('permission:settings_manage');
    Route::put('admin/settings/homepage', [SettingsController::class, 'updateHomepage'])->middleware('permission:settings_manage');
    Route::post('admin/settings/homepage/hero-image', [SettingsController::class, 'uploadHomepageHeroImage'])->middleware('permission:settings_manage');

    Route::get('admin/settings/policies', [SettingsController::class, 'showPolicies'])->middleware('permission:settings_manage');
    Route::put('admin/settings/policies', [SettingsController::class, 'updatePolicies'])->middleware('permission:settings_manage');

    Route::get('admin/settings/tax', [SettingsController::class, 'showTax'])->middleware('permission:settings_manage');
    Route::put('admin/settings/tax', [SettingsController::class, 'updateTax'])->middleware('permission:settings_manage');
});
