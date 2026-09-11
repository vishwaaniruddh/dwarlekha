<?php
namespace App\Services;

use App\Config\Database;
use App\Config\RbacGuard;
use App\Config\TenantContext;
use PDO;

class AuditService {
    public static function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        mixed $details = null,
        ?int $societyId = null,
        ?string $actorName = null,
        bool $forceGlobal = false
    ): bool {
        try {
            $currentUser = RbacGuard::getCurrentUser();

            // Resolve Actor Name
            if (empty($actorName)) {
                $actorName = $currentUser['fullName'] ?? ($currentUser['name'] ?? ($currentUser['email'] ?? 'System / Anonymous'));
            }

            // Resolve Society ID: 0 or forceGlobal means Global SAR Platform HQ
            if ($forceGlobal || $societyId === 0) {
                $societyId = null;
            } elseif ($societyId === null) {
                $contextId = TenantContext::getSocietyId();
                if ($contextId > 0) {
                    $societyId = $contextId;
                } elseif (!empty($currentUser['societyId'])) {
                    $societyId = (int)$currentUser['societyId'];
                }
            }

            // Resolve Client IP
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }

            // Format Details
            $detailsJson = null;
            if ($details !== null) {
                if (is_string($details)) {
                    $detailsJson = $details;
                } else {
                    $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO `audit_logs` 
                (`society_id`, `action`, `entity_type`, `entity_id`, `actor_name`, `ip_address`, `details`, `is_deleted`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");

            return $stmt->execute([
                $societyId,
                strtoupper(trim($action)),
                $entityType,
                $entityId !== null ? (string)$entityId : null,
                $actorName,
                $ip,
                $detailsJson
            ]);
        } catch (\Throwable $e) {
            // Non-blocking audit failure
            error_log("AuditService::log error: " . $e->getMessage());
            return false;
        }
    }

    public static function getAuditStream(?int $societyId, array $filters = [], int $page = 1, int $limit = 25): array {
        $db = Database::getConnection();

        $page = max(1, $page);
        $limit = min(100, max(5, $limit));
        $offset = ($page - 1) * $limit;

        $conditions = ["a.is_deleted = 0"];
        $params = [];

        // Scoping: If societyId is provided and > 0, filter strictly
        // If societyId is null or 0 (SAR Platform HQ), allow all or optional filter
        if ($societyId !== null && $societyId > 0) {
            $conditions[] = "a.society_id = ?";
            $params[] = $societyId;
        } elseif (!empty($filters['society_id'])) {
            $conditions[] = "a.society_id = ?";
            $params[] = (int)$filters['society_id'];
        }

        if (!empty($filters['action'])) {
            $conditions[] = "a.action LIKE ?";
            $params[] = '%' . trim($filters['action']) . '%';
        }

        if (!empty($filters['actor'])) {
            $conditions[] = "a.actor_name LIKE ?";
            $params[] = '%' . trim($filters['actor']) . '%';
        }

        if (!empty($filters['entity_type'])) {
            $conditions[] = "a.entity_type = ?";
            $params[] = trim($filters['entity_type']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $conditions[] = "(a.action LIKE ? OR a.actor_name LIKE ? OR a.entity_id LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['from_date'])) {
            $conditions[] = "a.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $conditions[] = "a.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $conditions);

        // 1. Total count
        $countSql = "SELECT COUNT(*) FROM `audit_logs` a WHERE {$whereClause}";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // 2. Paginated rows with Society join
        $sql = "
            SELECT 
                a.id,
                a.society_id,
                s.name AS society_name,
                s.society_code,
                a.action,
                a.entity_type,
                a.entity_id,
                a.actor_name,
                a.ip_address,
                a.details,
                a.created_at
            FROM `audit_logs` a
            LEFT JOIN `societies` s ON a.society_id = s.id
            WHERE {$whereClause}
            ORDER BY a.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format and decode JSON details if applicable
        $formatted = [];
        foreach ($rows as $row) {
            $parsedDetails = null;
            if (!empty($row['details'])) {
                $decoded = json_decode($row['details'], true);
                $parsedDetails = $decoded !== null ? $decoded : $row['details'];
            }

            $formatted[] = [
                'id' => (int)$row['id'],
                'societyId' => !empty($row['society_id']) ? (int)$row['society_id'] : null,
                'societyName' => $row['society_name'] ?: 'Global Platform HQ',
                'societyCode' => $row['society_code'] ?: 'GLOBAL',
                'action' => $row['action'],
                'entityType' => $row['entity_type'],
                'entityId' => $row['entity_id'],
                'actorName' => $row['actor_name'] ?: 'System',
                'ipAddress' => $row['ip_address'] ?: '127.0.0.1',
                'details' => $parsedDetails,
                'createdAt' => $row['created_at'],
                'timestamp' => strtotime($row['created_at'])
            ];
        }

        return [
            'data' => $formatted,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / max(1, $limit)) ?: 1,
                'has_next' => ($page * $limit) < $total,
                'has_prev' => $page > 1
            ]
        ];
    }
}
