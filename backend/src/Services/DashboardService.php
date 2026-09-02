<?php
namespace App\Services;

use App\Models\Dashboard;

class DashboardService {
    private Dashboard $dashboardModel;

    public function __construct(?Dashboard $dashboardModel = null) {
        $this->dashboardModel = $dashboardModel ?: new Dashboard();
    }

    public function getMetrics(): array {
        return $this->dashboardModel->getOverview();
    }
}
