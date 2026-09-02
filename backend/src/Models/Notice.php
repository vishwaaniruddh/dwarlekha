<?php
namespace App\Models;

use PDO;

class Notice extends BaseModel {
    public function getAll(?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        
        $sql = "SELECT n.*, s.name AS society_name, s.society_code AS society_code 
                FROM notices n 
                LEFT JOIN societies s ON n.society_id = s.id 
                WHERE n.is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND n.society_id = ?";
            $params[] = $societyId;
        }

        $sql .= " ORDER BY n.is_pinned DESC, n.id DESC";

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

        $stmt = $this->db->prepare("INSERT INTO notices 
           (notice_code, society_id, title, category, priority, content, date_label, is_pinned, author_name, is_deleted) 
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

        $stmt->execute([
            $data['notice_code'],
            $societyId,
            $data['title'],
            $data['category'] ?? 'General',
            $priority,
            $data['content'],
            $dateLabel,
            !empty($data['is_pinned']) || !empty($data['pinned']) ? 1 : 0,
            $data['author'] ?? ($data['author_name'] ?? 'Estate Management')
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(string $codeOrId, array $data): bool {
        $priority = $this->mapPriority($data['priority'] ?? ($data['urgency'] ?? 'Normal'));
        $societyId = !empty($data['society_id']) ? (int)$data['society_id'] : (!empty($data['societyId']) ? (int)$data['societyId'] : null);

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

    private function mapPriority(string $raw): string {
        $rawP = strtolower($raw);
        if (str_contains($rawP, 'urgent')) return 'Urgent';
        if (str_contains($rawP, 'high') || str_contains($rawP, 'important')) return 'High';
        return 'Normal';
    }

    private function formatRow(array $n): array {
        $p = $n['priority'] ?: 'Normal';
        $urgency = ($p === 'Urgent') ? 'Urgent' : (($p === 'High') ? 'Important' : 'General');

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
            'date' => $n['date_label'] ?: date('d M, h:i A', strtotime($n['created_at'])),
            'date_label' => $n['date_label'] ?: date('d M, h:i A', strtotime($n['created_at'])),
            'author' => $n['author_name'] ?: 'Estate Management',
            'author_name' => $n['author_name'] ?: 'Estate Management',
            'readCount' => 12,
            'pinned' => (bool)$n['is_pinned'],
            'is_pinned' => (bool)$n['is_pinned'],
            'createdAt' => $n['created_at']
        ];
    }
}
