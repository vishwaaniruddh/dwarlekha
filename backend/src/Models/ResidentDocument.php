<?php
namespace App\Models;

use PDO;

class ResidentDocument extends BaseModel {
    public function getByResidentId(int $residentId): array {
        $stmt = $this->db->prepare("SELECT * FROM resident_documents WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $stmt->execute([$residentId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM resident_documents WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO resident_documents 
            (resident_id, document_type, document_number, file_url) 
            VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $data['resident_id'],
            $data['document_type'] ?? 'Aadhaar',
            $data['document_number'] ?? null,
            $data['file_url']
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("UPDATE resident_documents SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }
}