<?php
namespace App\Models;

use PDO;

class Tower extends BaseModel {
    public function getBySocietyId(?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        if ($societyId === 0) {
            $stmt = $this->db->prepare("
                SELECT t.*, 
                       COUNT(u.id) AS total_units_count,
                       SUM(CASE WHEN u.occupancy_status != 'Vacant' THEN 1 ELSE 0 END) AS occupied_units_count
                FROM towers t
                LEFT JOIN units u ON t.id = u.tower_id AND u.society_id = t.society_id AND u.is_deleted = 0
                WHERE t.is_deleted = 0
                GROUP BY t.id
                ORDER BY t.society_id ASC, t.id ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $this->db->prepare("
            SELECT t.*, 
                   COUNT(u.id) AS total_units_count,
                   SUM(CASE WHEN u.occupancy_status != 'Vacant' THEN 1 ELSE 0 END) AS occupied_units_count
            FROM towers t
            LEFT JOIN units u ON t.id = u.tower_id AND u.society_id = t.society_id AND u.is_deleted = 0
            WHERE t.society_id = ? AND t.is_deleted = 0
            GROUP BY t.id
            ORDER BY t.id ASC
        ");
        $stmt->execute([$societyId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM towers WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByName(string $name, ?int $societyId = null): ?array {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("SELECT * FROM towers WHERE society_id = ? AND name = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$societyId, $name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int {
        $societyId = $data['society_id'] ?? $this->getSocietyId();
        $stmt = $this->db->prepare("INSERT INTO towers (society_id, tower_code, name, total_floors, total_units) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $societyId,
            $data['tower_code'] ?? ('T-' . substr($data['name'] ?? 'T', 0, 3)),
            $data['name'],
            (int)($data['total_floors'] ?? 10),
            (int)($data['total_units'] ?? 0)
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = trim($data['name']);
        }
        if (isset($data['tower_code'])) {
            $fields[] = "tower_code = ?";
            $params[] = strtoupper(trim($data['tower_code']));
        }
        if (isset($data['total_floors'])) {
            $fields[] = "total_floors = ?";
            $params[] = (int)$data['total_floors'];
        }
        if (isset($data['total_units'])) {
            $fields[] = "total_units = ?";
            $params[] = (int)$data['total_units'];
        }
        if (isset($data['description'])) {
            $fields[] = "description = ?";
            $params[] = $data['description'];
        }

        if (empty($fields)) return false;

        $params[] = $id;
        $sql = "UPDATE towers SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("UPDATE towers SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
