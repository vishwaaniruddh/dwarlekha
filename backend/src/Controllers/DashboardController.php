<?php
namespace App\Controllers;

use App\Services\DashboardService;

class DashboardController extends BaseController {
    private DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null) {
        $this->dashboardService = $dashboardService ?: new DashboardService();
    }

    public function metrics(): void {
        $metrics = $this->dashboardService->getMetrics();
        $this->success($metrics);
    }
}
