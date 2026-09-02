<?php
namespace App\Models;

use PDO;

class FamilyMember extends BaseModel {
    public function getByResidentId(int $residentId): array {
        $stmt = $this->db->prepare("SELECT * FROM family_members WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $stmt->execute([$residentId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM family_members WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO family_members 
            (resident_id, full_name, relation, phone, age, gender, photo_url) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['resident_id'],
            $data['full_name'],
            $data['relation'],
            $data['phone'] ?? null,
            isset($data['age']) && $data['age'] !== '' ? (int)$data['age'] : null,
            $data['gender'] ?? 'Other',
            $data['photo_url'] ?? null
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        if (isset($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = $data['full_name'];
        }
        if (isset($data['relation'])) {
            $fields[] = "relation = ?";
            $params[] = $data['relation'];
        }
        if (array_key_exists('phone', $data)) {
            $fields[] = "phone = ?";
            $params[] = $data['phone'];
        }
        if (array_key_exists('age', $data)) {
            $fields[] = "age = ?";
            $params[] = $data['age'] !== null && $data['age'] !== '' ? (int)$data['age'] : null;
        }
        if (isset($data['gender'])) {
            $fields[] = "gender = ?";
            $params[] = $data['gender'];
        }
        if (array_key_exists('photo_url', $data)) {
            $fields[] = "photo_url = ?";
            $params[] = $data['photo_url'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE family_members SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("UPDATE family_members SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }
}