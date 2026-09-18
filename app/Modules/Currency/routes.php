<?php

use App\Modules\Currency\Controllers\CurrencyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/currencies', [CurrencyController::class, 'index'])
        ->middleware('permission:currency_view');
    Route::get('admin/currencies/{currency}', [CurrencyController::class, 'show'])
        ->middleware('permission:currency_view');
    Route::post('admin/currencies', [CurrencyController::class, 'store'])
        ->middleware('permission:currency_manage');
    Route::patch('admin/currencies/{currency}', [CurrencyController::class, 'update'])
        ->middleware('permission:currency_manage');
    Route::post('admin/currencies/{currency}/designate-base', [CurrencyController::class, 'designateBase'])
        ->middleware('permission:currency_manage');

    Route::get('admin/currencies/{currency}/exchange-rates', [CurrencyController::class, 'exchangeRates'])
        ->middleware('permission:currency_view');
    Route::post('admin/currencies/{currency}/exchange-rates', [CurrencyController::class, 'storeExchangeRate'])
        ->middleware('permission:currency_manage');
});
