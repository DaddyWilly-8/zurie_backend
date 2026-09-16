<?php

use App\Modules\Expense\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/expenses', [ExpenseController::class, 'index'])->middleware('permission:expense_view');
    Route::post('admin/expenses', [ExpenseController::class, 'store'])->middleware('permission:expense_create');
});
