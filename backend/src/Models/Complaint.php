<?php
namespace App\Models;

use PDO;

class Complaint extends BaseModel {
    public function getAll(?int $societyId = null, array $filters = []): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        
        $sql = "SELECT * FROM complaints WHERE is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        if (!empty($filters['unit_id'])) {
            $sql .= " AND (unit_id = ? OR flat_number = ?)";
            $params[] = (int)$filters['unit_id'];
            $params[] = $filters['flat_number'] ?? '';
        } elseif (!empty($filters['flat_number'])) {
            $sql .= " AND (flat_number = ? OR flat_number LIKE ?)";
            $params[] = $filters['flat_number'];
            $params[] = '%' . $filters['flat_number'] . '%';
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function($c) {
            $attachments = [];
            if (!empty($c['attachments'])) {
                $decoded = json_decode($c['attachments'], true);
                $attachments = is_array($decoded) ? $decoded : [$c['attachments']];
            }

            return [
                'id' => $c['ticket_code'],
                'ticket_code' => $c['ticket_code'],
                'ticketNumber' => $c['ticket_code'],
                'title' => $c['title'],
                'category' => $c['category'],
                'flatNumber' => $c['flat_number'],
                'flat_number' => $c['flat_number'],
                'reportedBy' => $c['reported_by'],
                'reported_by' => $c['reported_by'],
                'priority' => $c['priority'],
                'status' => $c['status'],
                'assignedTo' => $c['assigned_to'] ?: 'Duty Facility Engineer',
                'assigned_to' => $c['assigned_to'] ?: 'Duty Facility Engineer',
                'createdAt' => $c['created_at_label'] ?: 'Recent',
                'created_at_label' => $c['created_at_label'] ?: 'Recent',
                'description' => $c['description'],
                'attachments' => $attachments,
                'resolutionNotes' => $c['resolution_notes'] ?? null,
                'resolution_notes' => $c['resolution_notes'] ?? null,
                'actionTaken' => $c['action_taken'] ?? null,
                'action_taken' => $c['action_taken'] ?? null,
                'resolvedAt' => $c['resolved_at'] ?? null,
                'resolved_at' => $c['resolved_at'] ?? null
            ];
        }, $rows);
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: $this->getSocietyId();
        $createdAtLabel = date('d M, h:i A');
        $attachments = !empty($data['attachments']) ? (is_string($data['attachments']) ? $data['attachments'] : json_encode($data['attachments'])) : null;

        $stmt = $this->db->prepare("INSERT INTO complaints 
            (ticket_code, society_id, title, category, flat_number, reported_by, priority, status, assigned_to, description, attachments, created_at_label, is_deleted) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Assigned', 'Facility Duty Tech', ?, ?, ?, 0)");

        $stmt->execute([
            $data['ticket_code'],
            $societyId,
            $data['title'],
            $data['category'] ?? 'Plumbing',
            $data['flat_number'] ?? 'B3-1601',
            $data['reported_by'] ?? 'Resident',
            $data['priority'] ?? 'High',
            $data['description'] ?? 'Reported via resident portal.',
            $attachments,
            $createdAtLabel
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateStatus(string $ticketCode, string $status, array $extra = [], ?int $societyId = null): bool {
        $societyId = $societyId ?: $this->getSocietyId();
        $assignedTo = $extra['assigned_to'] ?? ($extra['assignedTo'] ?? null);
        $resolutionNotes = $extra['resolution_notes'] ?? ($extra['resolutionNotes'] ?? null);
        $actionTaken = $extra['action_taken'] ?? ($extra['actionTaken'] ?? null);
        $resolvedAt = ($status === 'Resolved' || $status === 'Closed') ? date('d M, h:i A') : null;

        $sql = "UPDATE complaints SET status = ?, 
            assigned_to = COALESCE(?, assigned_to), 
            resolution_notes = COALESCE(?, resolution_notes), 
            action_taken = COALESCE(?, action_taken), 
            resolved_at = COALESCE(?, resolved_at) 
            WHERE ticket_code = ?";
        
        $params = [$status, $assignedTo, $resolutionNotes, $actionTaken, $resolvedAt, $ticketCode];

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
