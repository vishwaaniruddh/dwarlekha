<?php
namespace App\Models;

use PDO;

class Notice extends BaseModel {
    public function getAll(?int $societyId = null, string $sortOrder = 'DESC'): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $order = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        
        $sql = "SELECT n.*, s.name AS society_name, s.society_code AS society_code 
                FROM notices n 
                LEFT JOIN societies s ON n.society_id = s.id 
                WHERE n.is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND n.society_id = ?";
            $params[] = $societyId;
        }

        $sql .= " ORDER BY n.is_pinned DESC, n.created_at {$order}, n.id {$order}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'formatRow'], $rows);
    }

    public function findById(string $codeOrId): ?array {
        $stmt = $this->db->prepare("SELECT n.*, s.name AS society_name, s.society_code AS society_code 
            FROM notices n 
            LEFT JOIN societies s ON n.society_id = s.id 
            WHERE (n.notice_code = ? OR n.id = ?) AND n.is_deleted = 0 LIMIT 1");
        $stmt->execute([$codeOrId, is_numeric($codeOrId) ? (int)$codeOrId : 0]);
        $row = $stmt->fetch();
        return $row ? $this->formatRow($row) : null;
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: ($data['society_id'] ?? ($data['societyId'] ?? $this->getSocietyId()));
        $dateLabel = date('d M, h:i A');
        
        $priority = $this->mapPriority($data['priority'] ?? ($data['urgency'] ?? 'Normal'));
        $targetScope = !empty($data['target_scope']) ? $data['target_scope'] : 'ALL';
        $targetUnits = !empty($data['target_units']) 
            ? (is_array($data['target_units']) ? json_encode(array_values($data['target_units'])) : $data['target_units']) 
            : null;

        $stmt = $this->db->prepare("INSERT INTO notices 
           (notice_code, society_id, title, category, priority, content, target_scope, target_units, date_label, is_pinned, author_name, is_deleted) 
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

        $stmt->execute([
            $data['notice_code'],
            $societyId,
            $data['title'],
            $data['category'] ?? 'General',
            $priority,
            $data['content'],
            $targetScope,
            $targetUnits,
            $dateLabel,
            !empty($data['is_pinned']) || !empty($data['pinned']) ? 1 : 0,
            $data['author'] ?? ($data['author_name'] ?? 'Estate Management')
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(string $codeOrId, array $data): bool {
        $priority = $this->mapPriority($data['priority'] ?? ($data['urgency'] ?? 'Normal'));
        $societyId = !empty($data['society_id']) ? (int)$data['society_id'] : (!empty($data['societyId']) ? (int)$data['societyId'] : null);

        $targetScope = $data['target_scope'] ?? null;
        $targetUnits = isset($data['target_units']) 
            ? (is_array($data['target_units']) ? json_encode(array_values($data['target_units'])) : $data['target_units']) 
            : null;

        $sql = "UPDATE notices SET 
            title = ?, 
            category = ?, 
            priority = ?, 
            content = ?, 
            is_pinned = ?, 
            author_name = ?";
        
        $params = [
            $data['title'],
            $data['category'] ?? 'General',
            $priority,
            $data['content'],
            !empty($data['is_pinned']) || !empty($data['pinned']) ? 1 : 0,
            $data['author'] ?? ($data['author_name'] ?? 'Estate Management')
        ];

        if ($targetScope !== null) {
            $sql .= ", target_scope = ?";
            $params[] = $targetScope;
        }

        if (isset($data['target_units'])) {
            $sql .= ", target_units = ?";
            $params[] = $targetUnits;
        }

        if ($societyId !== null && $societyId > 0) {
            $sql .= ", society_id = ?";
            $params[] = $societyId;
        }

        $sql .= " WHERE (notice_code = ? OR id = ?) AND is_deleted = 0";
        $params[] = $codeOrId;
        $params[] = is_numeric($codeOrId) ? (int)$codeOrId : 0;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(string $codeOrId): bool {
        // Strict Soft Delete per AGENTS.md Directive 4
        $stmt = $this->db->prepare("UPDATE notices SET is_deleted = 1, deleted_at = NOW() WHERE (notice_code = ? OR id = ?) AND is_deleted = 0");
        return $stmt->execute([$codeOrId, is_numeric($codeOrId) ? (int)$codeOrId : 0]);
    }

    public function togglePin(string $codeOrId): bool {
        $stmt = $this->db->prepare("UPDATE notices SET is_pinned = IF(is_pinned = 1, 0, 1) WHERE (notice_code = ? OR id = ?) AND is_deleted = 0");
        return $stmt->execute([$codeOrId, is_numeric($codeOrId) ? (int)$codeOrId : 0]);
    }

    public function setPinStatus(string $codeOrId, int $isPinned): bool {
        $stmt = $this->db->prepare("UPDATE notices SET is_pinned = ? WHERE (notice_code = ? OR id = ?) AND is_deleted = 0");
        return $stmt->execute([$isPinned ? 1 : 0, $codeOrId, is_numeric($codeOrId) ? (int)$codeOrId : 0]);
    }

    public function getPinnedCount(int $societyId, ?int $excludeId = null): int {
        $sql = "SELECT COUNT(*) FROM notices WHERE is_pinned = 1 AND is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function mapPriority(string $raw): string {
        $rawP = strtolower($raw);
        if (str_contains($rawP, 'urgent')) return 'Urgent';
        if (str_contains($rawP, 'high') || str_contains($rawP, 'important')) return 'High';
        return 'Normal';
    }

    private function formatRow(array $n): array {
        $p = $n['priority'] ?: 'Normal';
        $urgency = ($p === 'Urgent') ? 'Urgent' : (($p === 'High') ? 'Important' : 'General');

        $targetUnitsRaw = $n['target_units'] ?? null;
        $targetUnits = [];
        if (!empty($targetUnitsRaw)) {
            if (is_array($targetUnitsRaw)) {
                $targetUnits = $targetUnitsRaw;
            } else {
                $decoded = json_decode($targetUnitsRaw, true);
                if (is_array($decoded)) {
                    $targetUnits = $decoded;
                } else {
                    $targetUnits = array_values(array_filter(array_map('trim', explode(',', $targetUnitsRaw))));
                }
            }
        }

        return [
            'id' => $n['notice_code'],
            'notice_code' => $n['notice_code'],
            'dbId' => (int)$n['id'],
            'societyId' => (int)$n['society_id'],
            'society_id' => (int)$n['society_id'],
            'societyName' => $n['society_name'] ?: 'Society Tenant',
            'title' => $n['title'],
            'category' => $n['category'] ?: 'General',
            'priority' => $p,
            'urgency' => $urgency,
            'content' => $n['content'],
            'target_scope' => $n['target_scope'] ?? 'ALL',
            'target_units' => $targetUnits,
            'targetUnits' => $targetUnits,
            'date' => $n['date_label'] ?: date('d M, h:i A', strtotime($n['created_at'])),
            'date_label' => $n['date_label'] ?: date('d M, h:i A', strtotime($n['created_at'])),
            'author' => $n['author_name'] ?: 'Estate Management',
            'author_name' => $n['author_name'] ?: 'Estate Management',
            'readCount' => 12,
            'pinned' => (bool)$n['is_pinned'],
            'is_pinned' => (bool)$n['is_pinned'],
            'createdAt' => $n['created_at'],
            'created_at' => $n['created_at']
        ];
    }
}
