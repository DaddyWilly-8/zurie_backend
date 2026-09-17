<?php

use App\Modules\PriceList\Controllers\PriceListController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/price-lists', [PriceListController::class, 'index'])->middleware('permission:price_list_view');
    Route::get('admin/price-lists/{priceList}', [PriceListController::class, 'show'])->middleware('permission:price_list_view');
    Route::post('admin/price-lists', [PriceListController::class, 'store'])->middleware('permission:price_list_manage');
    Route::patch('admin/price-lists/{priceList}', [PriceListController::class, 'update'])->middleware('permission:price_list_manage');
    Route::post('admin/price-lists/{priceList}/items', [PriceListController::class, 'setItem'])->middleware('permission:price_list_manage');
    Route::delete('admin/price-lists/{priceList}/items/{productId}', [PriceListController::class, 'removeItem'])->middleware('permission:price_list_manage');
});
