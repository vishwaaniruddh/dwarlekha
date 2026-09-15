<?php
namespace App\Controllers;

use App\Config\RbacGuard;
use App\Config\TenantContext;
use App\Services\AuditService;
use Exception;

class AuditLogController extends BaseController {
    public function index(): void {
        $currentUser = RbacGuard::getCurrentUser();
        $isSarAdmin = !empty($currentUser['isParentUser']) || 
                      in_array($currentUser['role']['code'] ?? '', ['super_admin', 'sar_platform_admin', 'sar_support'], true);

        $activeResolvedSocId = TenantContext::resolve();

        // Non-SAR admins can strictly only see their own society
        if (!$isSarAdmin) {
            $userSocId = $currentUser['societyId'] ?? ($currentUser['society_id'] ?? null);
            $societyId = !empty($userSocId) ? (int)$userSocId : ($activeResolvedSocId > 0 ? $activeResolvedSocId : 1);
        } else {
            // SAR platform admin
            if (isset($_GET['society_id']) && $_GET['society_id'] !== '') {
                $rawSoc = strtoupper(trim((string)$_GET['society_id']));
                if ($rawSoc === 'GLOBAL' || $rawSoc === 'ALL' || $rawSoc === '0') {
                    $societyId = null; // Explicitly selected Global Stream
                } else {
                    $societyId = (int)$_GET['society_id'];
                }
            } else {
                // If society_id is not passed in GET, strictly adhere to active workspace society
                $societyId = ($activeResolvedSocId > 0) ? $activeResolvedSocId : null;
            }
        }

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(5, (int)$_GET['limit'])) : 25;

        $filters = [
            'action' => $_GET['action'] ?? null,
            'actor' => $_GET['actor'] ?? null,
            'search' => $_GET['search'] ?? null,
            'entity_type' => $_GET['entity_type'] ?? null,
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
        ];

        try {
            $result = AuditService::getAuditStream($societyId, $filters, $page, $limit);
            $this->success($result);
        } catch (\Throwable $e) {
            $this->error("Failed to retrieve audit logs: " . $e->getMessage(), 500);
        }
    }

    /**
     * Log Page Access or User Interaction from Frontend SPA
     * POST /audit/log
     */
    public function logEvent(): void {
        $currentUser = RbacGuard::getCurrentUser();
        $isSarAdmin = !empty($currentUser['isParentUser']) || 
                      in_array($currentUser['role']['code'] ?? '', ['super_admin', 'sar_platform_admin', 'sar_support'], true);

        $input = $this->getJsonInput();
        $action = trim($input['action'] ?? 'PAGE_VIEW');
        $entityType = $input['entity_type'] ?? 'ui';
        $entityId = $input['entity_id'] ?? null;
        $details = $input['details'] ?? null;

        $societyId = null;
        if (!empty($input['society_id']) && $isSarAdmin) {
            $societyId = (int)$input['society_id'];
        } elseif (!$isSarAdmin && !empty($currentUser['societyId'] ?? ($currentUser['society_id'] ?? null))) {
            $societyId = (int)($currentUser['societyId'] ?? $currentUser['society_id']);
        } else {
            $resolved = TenantContext::resolve();
            $societyId = $resolved > 0 ? $resolved : null;
        }

        $success = AuditService::log($action, $entityType, $entityId, $details, $societyId);
        $this->success(['logged' => $success], 'Activity logged successfully', 201);
    }

    /**
     * Get distinct action types for UI filter pills
     * GET /audit/actions
     */
    public function actions(): void {
        $db = \App\Config\Database::getConnection();
        $stmt = $db->query("SELECT DISTINCT action FROM audit_logs WHERE is_deleted = 0 ORDER BY action ASC");
        $actions = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->success($actions ?: []);
    }
}
