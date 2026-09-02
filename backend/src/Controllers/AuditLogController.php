<?php
namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantContext;
use App\Utils\Logger;
use PDO;

class AuditLogController extends BaseController {
    public function index(): void {
        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(5, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;

        try {
            $countSql = "SELECT COUNT(*) FROM audit_logs WHERE society_id = ? OR ? = 0";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute([$societyId, $societyId]);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT id, society_id, action, entity_type, entity_id, actor_name, ip_address, details, created_at 
                    FROM audit_logs 
                    WHERE society_id = ? OR ? = 0 
                    ORDER BY id DESC 
                    LIMIT ? OFFSET ?";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(1, $societyId, PDO::PARAM_INT);
            $stmt->bindValue(2, $societyId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->bindValue(4, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->success([
                'logs' => $records,
                'file_logs' => Logger::getRecentLogs(30),
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / max(1, $limit))
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error("Failed to retrieve audit logs: " . $e->getMessage(), 500);
        }
    }

    public function fileLogs(): void {
        $logs = Logger::getRecentLogs(100);
        $this->success(['logs' => $logs]);
    }
}
