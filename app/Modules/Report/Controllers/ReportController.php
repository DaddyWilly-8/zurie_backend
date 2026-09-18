<?php

namespace App\Modules\Report\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Report\Services\ReportService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $reportService) {}

    public function salesByChannel()
    {
        return $this->ok($this->reportService->salesByChannel());
    }

    public function lowStock()
    {
        return $this->ok($this->reportService->lowStockAlerts());
    }

    public function revenueSummary()
    {
        return $this->ok($this->reportService->revenueSummary());
    }

    public function trialBalance()
    {
        return $this->ok($this->reportService->trialBalance());
    }

    public function balanceSheet()
    {
        return $this->ok($this->reportService->balanceSheet());
    }

    public function inventoryValue(Request $request)
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;

        return $this->ok($this->reportService->inventoryValue($outletId));
    }

    public function debtors()
    {
        return $this->ok($this->reportService->debtors());
    }

    public function creditors()
    {
        return $this->ok($this->reportService->creditors());
    }

    public function purchaseSummary()
    {
        return $this->ok($this->reportService->purchaseSummary());
    }

    public function storeStock(int $outletId)
    {
        return $this->ok($this->reportService->storeStockList($outletId));
    }
}
