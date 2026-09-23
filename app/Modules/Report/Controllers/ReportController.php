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

    /**
     * `from`/`to` are optional 'YYYY-MM-DD' query params, shared by every
     * period-based report endpoint below — omit both for the report's
     * all-time figure.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateRange(Request $request): array
    {
        return [$request->query('from'), $request->query('to')];
    }

    public function salesByChannel(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return $this->ok($this->reportService->salesByChannel($from, $to));
    }

    public function lowStock()
    {
        return $this->ok($this->reportService->lowStockAlerts());
    }

    public function revenueSummary(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return $this->ok($this->reportService->revenueSummary($from, $to));
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

    public function purchaseSummary(Request $request)
    {
        [$from, $to] = $this->dateRange($request);

        return $this->ok($this->reportService->purchaseSummary($from, $to));
    }

    public function storeStock(int $outletId)
    {
        return $this->ok($this->reportService->storeStockList($outletId));
    }
}
