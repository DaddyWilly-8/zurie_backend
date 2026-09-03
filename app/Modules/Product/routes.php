<?php

use App\Modules\Product\Controllers\CategoryController;
use App\Modules\Product\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

// Public — storefront browsing.
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{category}', [CategoryController::class, 'show']);

Route::get('products', [ProductController::class, 'index']);
Route::get('products/{slug}', [ProductController::class, 'show']);

// Admin — permission-gated, see PermissionSeeder for the full catalog.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/categories', [CategoryController::class, 'adminIndex'])->middleware('permission:category_view');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:category_create');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:category_update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:category_delete');
    Route::post('categories/{category}/image', [CategoryController::class, 'uploadImage'])->middleware('permission:category_update');
    Route::delete('categories/{category}/image', [CategoryController::class, 'deleteImage'])->middleware('permission:category_update');

    Route::get('admin/products', [ProductController::class, 'adminIndex'])->middleware('permission:product_view');
    Route::get('admin/products/{id}', [ProductController::class, 'adminShow'])->middleware('permission:product_view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:product_create');
    Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('permission:product_update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:product_delete');
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->middleware('permission:product_create');
    Route::post('products/{product}/images', [ProductController::class, 'uploadImages'])->middleware('permission:product_update');
    Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage'])->middleware('permission:product_update');
});
