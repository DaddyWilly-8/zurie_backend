<?php

namespace App\Modules\Report\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Report\Services\ReportService;
use App\Support\Http\ApiResponse;

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
}
