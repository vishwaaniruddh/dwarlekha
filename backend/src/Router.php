<?php
namespace App;

use App\Config\Cors;
use App\Config\TenantContext;
use App\Controllers\DashboardController;
use App\Controllers\UnitController;
use App\Controllers\ResidentController;
use App\Controllers\VisitorController;
use App\Controllers\BillingController;
use App\Controllers\PaymentController;
use App\Controllers\AccountingController;
use App\Controllers\ExpenseController;
use App\Controllers\HelpdeskController;
use App\Controllers\AmenityController;
use App\Controllers\NoticeController;
use App\Controllers\TenantController;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\RoleController;
use App\Controllers\TowerController;
use App\Controllers\UnitTypeController;
use App\Controllers\BootstrapController;
use App\Controllers\AuditLogController;
use App\Controllers\UploadController;
use App\Controllers\PushNotificationController;
use App\Controllers\VehicleController;
use App\Controllers\SyncController;

class Router {
    public function dispatch(): void {
        if (!defined('APP_START_TIME')) {
            define('APP_START_TIME', microtime(true));
        }
        Cors::handle();
        $societyId = TenantContext::resolve();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Extract route from query parameter or URL path
        $route = $_GET['route'] ?? '';
        if (str_contains($route, '?')) {
            [$cleanRoute, $queryPart] = explode('?', $route, 2);
            $route = $cleanRoute;
            parse_str($queryPart, $parsedParams);
            $_GET = array_merge($parsedParams, $_GET);
        }
        if (empty($route)) {
            $path = parse_url($uri, PHP_URL_PATH);
            $path = preg_replace('#^/society-management/backend/(public/)?(index\.php)?#', '', $path);
            $path = preg_replace('#^/backend/(public/)?(index\.php)?#', '', $path);
            $path = preg_replace('#^/api/(index\.php)?#', '', $path);
            $route = trim($path, '/');
        }

        $segments = explode('/', $route);
        $resource = $segments[0] ?? '';
        $action = $segments[1] ?? '';
        $subAction = $segments[2] ?? '';
        $param = $segments[2] ?? ($segments[1] ?? '');

        try {
            switch ($resource) {
                // 00. High-Performance Unified Bootstrap Telemetry (1 Single HTTP Payload)
                case 'bootstrap':
                case 'initial-data':
                    $ctrl = new BootstrapController();
                    $ctrl->index();
                    break;

                // 0. Authentication & Personas
                case 'auth':
                    $ctrl = new AuthController();
                    if ($method === 'POST' && $action === 'login') {
                        $ctrl->login();
                    } elseif ($method === 'POST' && $action === 'logout') {
                        $ctrl->logout();
                    } elseif ($method === 'GET' && $action === 'me') {
                        $ctrl->me();
                    } elseif ($method === 'GET' && $action === 'personas') {
                        $ctrl->personas();
                    } else {
                        // Default to login if POST to /auth
                        if ($method === 'POST') {
                            $ctrl->login();
                        } else {
                            $ctrl->me();
                        }
                    }
                    break;

                // 1. User Management (RBAC)
                case 'users':
                    $ctrl = new UserController();
                    if ($method === 'GET' && empty($action)) {
                        $ctrl->index();
                    } elseif ($method === 'GET' && !empty($action)) {
                        $ctrl->show($action);
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'PUT' && !empty($action)) {
                        $ctrl->update($action);
                    } elseif ($method === 'DELETE' && !empty($action)) {
                        $ctrl->delete($action);
                    }
                    break;

                // 2. Roles & Permissions (RBAC)
                case 'roles':
                    $ctrl = new RoleController();
                    if ($method === 'GET' && $action === 'matrix') {
                        $ctrl->matrix();
                    } elseif ($method === 'GET' && empty($action)) {
                        $ctrl->index();
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'PUT' && !empty($action)) {
                        $ctrl->updatePermissions($action);
                    }
                    break;

                case 'permissions':
                    $ctrl = new RoleController();
                    if ($method === 'GET') {
                        $ctrl->permissions();
                    }
                    break;

                // 3. Societies (Tenant Master)
                case 'societies':
                case 'tenants':
                    $ctrl = new TenantController();
                    if ($method === 'GET') {
                        $ctrl->listSocieties();
                    } elseif ($method === 'POST') {
                        $ctrl->createSociety();
                    } elseif ($method === 'PUT' && !empty($action)) {
                        $ctrl->updateSociety((int)$action);
                    }
                    break;

                // 4. Dashboard Metrics
                case 'dashboard':
                case 'metrics':
                    $ctrl = new DashboardController();
                    if ($method === 'GET') {
                        $ctrl->metrics();
                    }
                    break;

                // 4b. Towers & Blocks Master
                case 'towers':
                case 'blocks':
                    $ctrl = new TowerController();
                    if ($method === 'GET') {
                        $ctrl->index();
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'PUT' && !empty($action)) {
                        $ctrl->update((int)$action);
                    } elseif ($method === 'DELETE' && !empty($action)) {
                        $ctrl->delete((int)$action);
                    }
                    break;

                // 4c. Standard & Custom Unit Types
                case 'unit-types':
                    $ctrl = new UnitTypeController();
                    if ($method === 'GET') {
                        $ctrl->index();
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'DELETE' && !empty($action)) {
                        $ctrl->delete((int)$action);
                    }
                    break;

                // 5. Units / Flats (240 Units & Floor Matrix)
                case 'units':
                case 'flats':
                    $ctrl = new UnitController();
                    if ($method === 'GET' && empty($action)) {
                        $ctrl->index();
                    } elseif ($method === 'GET' && !empty($action)) {
                        $ctrl->show($action);
                    } elseif ($method === 'POST' && ($action === 'bulk' || $action === 'generate')) {
                        $ctrl->bulkGenerate();
                    } elseif ($method === 'POST' && $action === 'batch') {
                        $ctrl->batchCreate();
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    }
                    break;

                // 6. Residents
                case 'residents':
                    $ctrl = new ResidentController();
                    $subAction = $segments[2] ?? '';
                    $subParam = $segments[3] ?? '';

                    $cleanAction = is_string($action) ? preg_replace('/^RES-/i', '', $action) : $action;
                    $cleanSubParam = is_string($subParam) ? preg_replace('/^RES-/i', '', $subParam) : $subParam;

                    if ($method === 'GET' && empty($action)) {
                        $ctrl->index();
                    } elseif ($method === 'GET' && is_numeric($cleanAction) && $subAction === 'credentials') {
                        $ctrl->credentials((int)$cleanAction);
                    } elseif ($method === 'POST' && is_numeric($cleanAction) && $subAction === 'credentials') {
                        $ctrl->credentials((int)$cleanAction);
                    } elseif ($method === 'GET' && is_numeric($cleanAction)) {
                        $ctrl->show((int)$cleanAction);
                    } elseif ($method === 'POST' && (empty($action) || $action === 'onboard')) {
                        $ctrl->onboard();
                    } elseif ($method === 'PUT' && is_numeric($cleanAction) && ($subAction === 'verify' || $subAction === 'status')) {
                        $ctrl->verify((int)$cleanAction);
                    } elseif ($method === 'POST' && is_numeric($cleanAction) && $subAction === 'family') {
                        $ctrl->addFamily((int)$cleanAction);
                    } elseif ($method === 'DELETE' && is_numeric($cleanAction) && $subAction === 'family') {
                        $ctrl->deleteFamily((int)$cleanAction, (int)$cleanSubParam);
                    } elseif ($method === 'POST' && is_numeric($cleanAction) && $subAction === 'documents') {
                        $ctrl->addDocument((int)$cleanAction);
                    } elseif ($method === 'DELETE' && is_numeric($cleanAction) && $subAction === 'documents') {
                        $ctrl->deleteDocument((int)$cleanAction, (int)$cleanSubParam);
                    } elseif ($method === 'POST' && is_numeric($cleanAction) && $subAction === 'vehicles') {
                        $ctrl->addVehicle((int)$cleanAction);
                    } elseif ($method === 'DELETE' && is_numeric($cleanAction) && $subAction === 'vehicles') {
                        $ctrl->deleteVehicle((int)$cleanAction, (int)$cleanSubParam);
                    } elseif ($method === 'DELETE' && is_numeric($cleanAction) && empty($subAction)) {
                        $ctrl->delete((int)$cleanAction);
                    } elseif ($method === 'PUT' && is_numeric($cleanAction) && $subAction === 'type') {
                        $ctrl->updateType((int)$cleanAction);
                    } else {
                        $ctrl->index();
                    }
                    break;

                // 6b. Vehicles & Parking Registry
                case 'vehicles':
                    $ctrl = new VehicleController();
                    if ($method === 'GET' && is_numeric($action)) {
                        $ctrl->show((int)$action);
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'PUT' && is_numeric($action)) {
                        $ctrl->update((int)$action);
                    } elseif ($method === 'DELETE' && is_numeric($action)) {
                        $ctrl->delete((int)$action);
                    } else {
                        $ctrl->index();
                    }
                    break;

                // 7. Visitors & Gate Passes
                case 'visitors':
                    $ctrl = new VisitorController();
                    if (($method === 'GET' || $method === 'POST') && $action === 'validate') {
                        $ctrl->validate();
                    } elseif ($method === 'GET') {
                        $ctrl->index();
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'PUT') {
                        if ($action === 'approve') {
                            $ctrl->approve($subAction ?: $param);
                        } elseif ($action === 'deny') {
                            $ctrl->deny($subAction ?: $param);
                        } elseif ($subAction === 'approve') {
                            $ctrl->approve($action);
                        } elseif ($subAction === 'deny') {
                            $ctrl->deny($action);
                        } elseif ($subAction === 'status' || $action === 'status') {
                            $input = json_decode(file_get_contents('php://input'), true) ?? [];
                            $status = $input['status'] ?? ($input['approvalStatus'] ?? 'Approved');
                            $targetCode = ($subAction === 'status') ? $action : $param;
                            if (strtolower($status) === 'denied' || strtolower($status) === 'deny') {
                                $ctrl->deny($targetCode);
                            } else {
                                $ctrl->approve($targetCode);
                            }
                        } elseif ($action === 'admit' || $action === 'allow-inside') {
                            $ctrl->admit($subAction ?: $param);
                        } elseif ($subAction === 'admit' || $subAction === 'allow-inside') {
                            $ctrl->admit($action);
                        } elseif ($action === 'checkout') {
                            $ctrl->checkout($subAction ?: $param);
                        } elseif ($subAction === 'checkout') {
                            $ctrl->checkout($action);
                        } else {
                            $ctrl->approve($action ?: $param);
                        }
                    }
                    break;

                // 8. Billing & Invoices (Module 04)
                case 'billing':
                case 'invoices':
                    $ctrl = new BillingController();
                    if ($action === 'charge-masters') {
                        $ctrl->chargeMasters();
                    } elseif ($action === 'generate' && $method === 'POST') {
                        $ctrl->generate();
                    } elseif ($action === 'summary' && $method === 'GET') {
                        $ctrl->summary();
                    } elseif ($method === 'GET' && is_numeric($action)) {
                        $ctrl->show((int)$action);
                    } else {
                        $ctrl->index();
                    }
                    break;

                // 8b. Payment Collection & Banking (Module 05)
                case 'payments':
                    $ctrl = new PaymentController();
                    if (($action === 'online' && $subAction === 'initiate') || $action === 'create-order') {
                        $ctrl->initiateOnline();
                    } elseif (($action === 'online' && $subAction === 'verify') || $action === 'verify') {
                        $ctrl->verifyOnline();
                    } elseif ($action === 'offline' && $method === 'POST') {
                        $ctrl->recordOffline();
                    } elseif (is_numeric($action) && $subAction === 'clear') {
                        $ctrl->clearCheque((int)$action);
                    } else {
                        $ctrl->index();
                    }
                    break;

                // 8c. Accounting & General Ledger (Module 06)
                case 'accounting':
                    $ctrl = new AccountingController();
                    if ($action === 'chart-of-accounts') {
                        $ctrl->chartOfAccounts();
                    } elseif ($action === 'journal-entries') {
                        $ctrl->journalEntries();
                    } elseif ($action === 'trial-balance') {
                        $ctrl->trialBalance();
                    } elseif ($action === 'pnl') {
                        $ctrl->pnl();
                    } elseif ($action === 'balance-sheet') {
                        $ctrl->balanceSheet();
                    } elseif ($action === 'ledger' && !empty($subAction)) {
                        $ctrl->residentLedger($subAction);
                    } else {
                        $ctrl->chartOfAccounts();
                    }
                    break;

                // 8d. Expense & Procurement (Module 07)
                case 'expenses':
                    $ctrl = new ExpenseController();
                    if ($action === 'vendors') {
                        $ctrl->vendors();
                    } elseif (is_numeric($action) && $subAction === 'approve') {
                        $ctrl->approve((int)$action);
                    } elseif (is_numeric($action) && $subAction === 'pay') {
                        $ctrl->pay((int)$action);
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } else {
                        $ctrl->index();
                    }
                    break;

                case 'vendors':
                    $ctrl = new ExpenseController();
                    $ctrl->vendors();
                    break;

                // 9. Helpdesk & Tickets
                case 'helpdesk':
                case 'complaints':
                case 'tickets':
                    $ctrl = new HelpdeskController();
                    if ($method === 'GET') {
                        $ctrl->index();
                    } elseif ($method === 'POST') {
                        $ctrl->create();
                    } elseif ($method === 'PUT' && ($action === 'status' || $action === 'update-status' || !empty($action))) {
                        $code = ($action === 'status' || $action === 'update-status') ? ($subAction ?: $param) : $action;
                        $ctrl->updateStatus((string)$code);
                    }
                    break;

                // Media & Attachment Uploads
                case 'upload':
                case 'uploads':
                    $ctrl = new UploadController();
                    $ctrl->upload();
                    break;

                // Push Notifications Token Registration & Testing
                case 'push-token':
                case 'push-tokens':
                case 'push-notifications':
                    $ctrl = new PushNotificationController();
                    if ($action === 'test' || $subAction === 'test') {
                        $ctrl->testPush();
                    } else {
                        $ctrl->registerToken();
                    }
                    break;

                // 10. Amenities & Bookings (Full CRUD)
                case 'amenities':
                case 'facilities':
                    $ctrl = new AmenityController();
                    if ($method === 'GET' && empty($action)) {
                        $ctrl->index();
                    } elseif ($method === 'GET' && !empty($action)) {
                        $ctrl->show($action);
                    } elseif ($method === 'POST' && $action === 'book') {
                        $ctrl->book();
                    } elseif ($method === 'POST' && empty($action)) {
                        $ctrl->create();
                    } elseif (($method === 'PUT' || $method === 'POST') && $action === 'update' && !empty($subAction)) {
                        $ctrl->update($subAction);
                    } elseif ($method === 'PUT' && !empty($action)) {
                        $ctrl->update($action);
                    } elseif (($method === 'DELETE' || $method === 'POST') && ($action === 'delete' || $method === 'DELETE') && (!empty($action) || !empty($subAction))) {
                        $targetId = ($action === 'delete') ? $subAction : $action;
                        $ctrl->delete($targetId);
                    }
                    break;

                // 11. Notices & Broadcasts (Full CRUD)
                case 'notices':
                case 'broadcasts':
                    $ctrl = new NoticeController();
                    if ($method === 'GET' && empty($action)) {
                        $ctrl->index();
                    } elseif ($method === 'GET' && !empty($action)) {
                        $ctrl->show($action);
                    } elseif ($method === 'POST' && empty($action)) {
                        $ctrl->create();
                    } elseif (($method === 'PUT' || $method === 'POST') && $action === 'update' && !empty($subAction)) {
                        $ctrl->update($subAction);
                    } elseif ($method === 'PUT' && !empty($action)) {
                        $ctrl->update($action);
                    } elseif (($method === 'DELETE' || $method === 'POST') && ($action === 'delete' || $method === 'DELETE') && (!empty($action) || !empty($subAction))) {
                        $targetId = ($action === 'delete') ? $subAction : $action;
                        $ctrl->delete($targetId);
                    } elseif (($method === 'PUT' || $method === 'POST') && $action === 'pin' && !empty($subAction)) {
                        $ctrl->togglePin($subAction);
                    }
                    break;

                // 11b. In-App Notifications
                case 'notifications':
                    $ctrl = new \App\Controllers\NotificationController();
                    if ($action === 'read' && $method === 'POST') {
                        if (!empty($subAction)) {
                            $ctrl->markAsRead((int)$subAction);
                        } else {
                            $ctrl->markAllAsRead();
                        }
                    } else {
                        $ctrl->index();
                    }
                    break;

                // 12. Audit & Activity Logs
                case 'audit-logs':
                case 'audit':
                case 'logs':
                    $ctrl = new AuditLogController();
                    if ($action === 'file') {
                        $ctrl->fileLogs();
                    } else {
                        $ctrl->index();
                    }
                    break;

                // 13. Geographic Cascading Masters (Countries, Zones, States, Cities)
                case 'geo':
                    $ctrl = new \App\Controllers\GeoController();
                    if ($action === 'countries') {
                        $ctrl->getCountries();
                    } elseif ($action === 'zones') {
                        $ctrl->getZones();
                    } elseif ($action === 'states') {
                        $ctrl->getStates();
                    } elseif ($action === 'cities') {
                        $ctrl->getCities();
                    } else {
                        $ctrl->getGeoLookup();
                    }
                    break;

                // 13. High-Speed Database Data Sync Operation (Private Endpoint)
                case 'sync':
                case 'sync-operation':
                    $ctrl = new SyncController();
                    if ($action === 'status') {
                        $ctrl->status();
                    } elseif ($action === 'export') {
                        $ctrl->export();
                    } elseif ($action === 'import') {
                        $ctrl->import();
                    } elseif ($action === 'push' || $action === 'push-remote') {
                        $ctrl->pushRemote();
                    } elseif ($action === 'pull' || $action === 'pull-remote') {
                        $ctrl->pullRemote();
                    } else {
                        $ctrl->status();
                    }
                    break;

                default:
                    // Health check & endpoint directory
                    http_response_code(200);
                    echo json_encode([
                        'status' => 'online',
                        'architecture' => 'SAR Enterprise Multi-Tenant MCS with RBAC User Management',
                        'activeTenant' => [
                            'society_id' => TenantContext::getSocietyId(),
                            'society_code' => TenantContext::getSocietyCode()
                        ],
                        'endpoints' => [
                            'POST /auth/login' => 'Authenticate user session',
                            'GET /auth/me' => 'Retrieve logged-in user profile & permissions',
                            'GET /auth/personas' => 'List switchable role personas',
                            'GET /users' => 'Directory of users with roles and status',
                            'POST /users' => 'Provision new user account',
                            'PUT /users/{id}' => 'Update user details / role assignment',
                            'GET /roles' => 'List RBAC roles and permissions',
                            'GET /roles/matrix' => 'Visual Role-Permission capability matrix',
                            'GET /permissions' => 'Catalog of granular system permissions',
                            'GET /dashboard' => 'Tenant KPI Telemetry & Overview',
                            'GET /units' => '240 Flats Directory & Matrix for Active Society',
                            'POST /residents/onboard' => 'Allot Unit / Onboard Resident',
                            'GET /visitors' => 'Gate Security Passes',
                            'POST /visitors' => 'Issue Gate Pass',
                            'PUT /visitors/checkout/{code}' => 'Checkout Visitor',
                            'GET /billing' => 'Maintenance Invoices in ₹',
                            'PUT /billing/pay/{num}' => 'Mark Invoice Paid',
                            'GET /helpdesk' => 'Maintenance Complaints & SLA',
                            'POST /helpdesk' => 'Raise Ticket',
                            'GET /amenities' => 'Clubhouse Facilities & Bookings',
                            'POST /amenities/book' => 'Reserve Facility Slot',
                            'GET /notices' => 'Announcements & Broadcasts',
                            'POST /notices' => 'Post Broadcast',
                            'GET /societies' => 'List all registered tenant societies'
                        ]
                    ]);
                    break;
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}
