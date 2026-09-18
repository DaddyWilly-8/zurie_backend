<?php

use App\Modules\Transaction\Controllers\FundTransferController;
use App\Modules\Transaction\Controllers\JournalVoucherController;
use App\Modules\Transaction\Controllers\PaymentController;
use App\Modules\Transaction\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/payments', [PaymentController::class, 'index'])->middleware('permission:transaction_view');
    Route::post('admin/payments', [PaymentController::class, 'store'])->middleware('permission:transaction_manage');
    Route::get('admin/payments/{id}', [PaymentController::class, 'show'])->middleware('permission:transaction_view');
    Route::delete('admin/payments/{id}', [PaymentController::class, 'destroy'])->middleware('permission:transaction_manage');

    Route::get('admin/receipts', [ReceiptController::class, 'index'])->middleware('permission:transaction_view');
    Route::post('admin/receipts', [ReceiptController::class, 'store'])->middleware('permission:transaction_manage');
    Route::get('admin/receipts/{id}', [ReceiptController::class, 'show'])->middleware('permission:transaction_view');
    Route::delete('admin/receipts/{id}', [ReceiptController::class, 'destroy'])->middleware('permission:transaction_manage');

    Route::get('admin/journal-vouchers', [JournalVoucherController::class, 'index'])->middleware('permission:transaction_view');
    Route::post('admin/journal-vouchers', [JournalVoucherController::class, 'store'])->middleware('permission:transaction_manage');
    Route::get('admin/journal-vouchers/{id}', [JournalVoucherController::class, 'show'])->middleware('permission:transaction_view');
    Route::delete('admin/journal-vouchers/{id}', [JournalVoucherController::class, 'destroy'])->middleware('permission:transaction_manage');

    Route::get('admin/fund-transfers', [FundTransferController::class, 'index'])->middleware('permission:transaction_view');
    Route::post('admin/fund-transfers', [FundTransferController::class, 'store'])->middleware('permission:transaction_manage');
    Route::get('admin/fund-transfers/{id}', [FundTransferController::class, 'show'])->middleware('permission:transaction_view');
    Route::delete('admin/fund-transfers/{id}', [FundTransferController::class, 'destroy'])->middleware('permission:transaction_manage');
});
