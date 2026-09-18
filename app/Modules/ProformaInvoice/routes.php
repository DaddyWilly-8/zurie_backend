<?php

use App\Modules\ProformaInvoice\Controllers\ProformaInvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('admin/proforma-invoices', [ProformaInvoiceController::class, 'index'])->middleware('permission:proforma_invoice_view');
    Route::post('admin/proforma-invoices', [ProformaInvoiceController::class, 'store'])->middleware('permission:proforma_invoice_manage');
    Route::get('admin/proforma-invoices/{id}', [ProformaInvoiceController::class, 'show'])->middleware('permission:proforma_invoice_view');
    Route::patch('admin/proforma-invoices/{id}', [ProformaInvoiceController::class, 'update'])->middleware('permission:proforma_invoice_manage');
    Route::patch('admin/proforma-invoices/{id}/active', [ProformaInvoiceController::class, 'setActive'])->middleware('permission:proforma_invoice_manage');
});
