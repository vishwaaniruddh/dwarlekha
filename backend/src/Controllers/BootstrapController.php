<?php
namespace App\Controllers;

use App\Services\DashboardService;
use App\Services\UnitService;
use App\Services\ResidentService;
use App\Services\VisitorService;
use App\Services\BillingService;
use App\Services\HelpdeskService;
use App\Services\AmenityService;
use App\Services\NoticeService;
use App\Services\TenantService;
use App\Services\UserService;
use App\Services\RoleService;
use App\Services\UnitTypeService;
use App\Services\GeoService;
use App\Models\Tower;
use App\Config\TenantContext;
use Exception;

class BootstrapController extends BaseController {
    public function index(): void {
        try {
            $dashboardService = new DashboardService();
            $unitService = new UnitService();
            $residentService = new ResidentService();
            $visitorService = new VisitorService();
            $billingService = new BillingService();
            $helpdeskService = new HelpdeskService();
            $amenityService = new AmenityService();
            $noticeService = new NoticeService();
            $tenantService = new TenantService();
            $userService = new UserService();
            $roleService = new RoleService();
            $unitTypeService = new UnitTypeService();
            $geoService = new GeoService();
            $towerModel = new Tower();

            $currUser = \App\Config\RbacGuard::getCurrentUser();
            $canViewUsers = \App\Config\RbacGuard::hasPermission('users.view');
            $canViewRoles = \App\Config\RbacGuard::hasPermission('roles.view');
            $canViewSocieties = !empty($currUser['isParentUser']) || ($currUser['role']['code'] ?? '') === 'super_admin' || \App\Config\RbacGuard::hasPermission('societies.view');

            $activeSocietyId = TenantContext::getSocietyId();
            $activeSocietyCode = TenantContext::getSocietyCode();

            $metrics = $dashboardService->getMetrics();
            $units = $unitService->getFlats();
            $residents = $residentService->getResidents();
            $visitors = $visitorService->getVisitors();
            $invoices = $billingService->getInvoices();
            $tickets = $helpdeskService->getTickets();
            $amenities = $amenityService->getFacilities($activeSocietyId);
            $notices = $noticeService->getNotices($activeSocietyId);
            $societies = $tenantService->getAllSocieties();
            $users = $canViewUsers ? $userService->getUsers() : [];
            $roles = $canViewRoles ? $roleService->getRoles() : [];
            $permissions = $canViewRoles ? $roleService->getPermissionsCatalog() : [];
            $towers = $towerModel->getBySocietyId();
            $unitTypes = $unitTypeService->getUnitTypes();
            $geoLookup = $geoService->getGeoLookup();

            $activeSocietyId = TenantContext::getSocietyId();
            $activeSocietyCode = TenantContext::getSocietyCode();

            $payload = array_merge($metrics, [
                'activeTenant' => [
                    'society_id' => $activeSocietyId,
                    'society_code' => $activeSocietyCode
                ],
                'flats' => $units,
                'residents' => $residents,
                'visitors' => $visitors,
                'invoices' => $invoices,
                'bills' => $invoices,
                'complaints' => $tickets,
                'amenities' => $amenities,
                'notices' => $notices,
                'societies' => $societies,
                'users' => $users,
                'roles' => $roles,
                'permissions' => $permissions,
                'towers' => $towers,
                'unitTypes' => $unitTypes,
                'geo' => $geoLookup
            ]);

            $this->success($payload, 'Bootstrap telemetry aggregated successfully');
        } catch (Exception $e) {
            $this->error('Bootstrap failed: ' . $e->getMessage(), 500);
        }
    }
}

