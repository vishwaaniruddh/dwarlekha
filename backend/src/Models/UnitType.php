<?php
namespace App\Models;

use PDO;
use Exception;
use InvalidArgumentException;

class UnitType extends BaseModel {
    public function getAll(?int $societyId = null): array {
        if ($societyId !== null && $societyId > 0) {
            $stmt = $this->db->prepare("SELECT * FROM `unit_types` 
                                        WHERE (`is_system_standard` = 1 OR `society_id` = ?) AND `is_deleted` = 0
                                        ORDER BY `is_system_standard` DESC, `id` ASC");
            $stmt->execute([$societyId]);
        } else {
            $stmt = $this->db->query("SELECT * FROM `unit_types` WHERE `is_deleted` = 0 ORDER BY `is_system_standard` DESC, `id` ASC");
        }
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `unit_types` WHERE `id` = ? AND `is_deleted` = 0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data, ?int $societyId = null): int {
        $typeName = trim($data['type_name'] ?? ($data['typeName'] ?? ''));
        if (empty($typeName)) {
            throw new InvalidArgumentException("Unit type name is required.");
        }

        $typicalArea = trim($data['typical_area'] ?? ($data['typicalArea'] ?? '400 – 600 sq.ft'));
        $standardSqft = (int)($data['standard_sqft'] ?? ($data['standardSqft'] ?? 500));
        $useCase = trim($data['use_case'] ?? ($data['useCase'] ?? 'Custom Society Floorplan'));
        $badgeColor = trim($data['badge_color'] ?? ($data['badgeColor'] ?? 'blue'));

        $stmt = $this->db->prepare("INSERT INTO `unit_types` 
            (`society_id`, `type_name`, `badge_color`, `typical_area`, `standard_sqft`, `use_case`, `is_system_standard`) 
            VALUES (?, ?, ?, ?, ?, ?, 0)");
        
        $stmt->execute([
            $societyId,
            $typeName,
            $badgeColor,
            $typicalArea,
            $standardSqft,
            $useCase
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id, ?int $societyId = null): bool {
        $existing = $this->findById($id);
        if (!$existing) {
            throw new Exception("Unit type not found.");
        }

        if (!empty($existing['is_system_standard'])) {
            throw new Exception("Core system standard unit types cannot be deleted.");
        }

        if ($societyId !== null && (int)$existing['society_id'] !== (int)$societyId) {
            throw new Exception("Unauthorized to delete unit type belonging to another society.");
        }

        $stmt = $this->db->prepare("UPDATE `unit_types` SET `is_deleted` = 1, `deleted_at` = NOW() WHERE `id` = ?");
        return $stmt->execute([$id]);
    }
}