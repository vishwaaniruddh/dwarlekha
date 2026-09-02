<?php
namespace App\Models;

use PDO;

class Vehicle extends BaseModel {
    public function getAll(?int $societyId = null, array $filters = []): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $params = [];
        $conditions = ['v.is_deleted = 0'];

        $sql = "SELECT v.*, u.unit_code, u.floor_number, t.name as tower_name, 
                       r.resident_type, usr.full_name as resident_name, usr.phone as resident_phone
                FROM vehicles v
                JOIN units u ON v.unit_id = u.id
                JOIN towers t ON u.tower_id = t.id
                LEFT JOIN residents r ON v.resident_id = r.id
                LEFT JOIN users usr ON r.user_id = usr.id";

        if ($societyId > 0) {
            $conditions[] = "v.society_id = ?";
            $params[] = $societyId;
        }

        if (!empty($filters['unit_id'])) {
            $conditions[] = "v.unit_id = ?";
            $params[] = (int)$filters['unit_id'];
        }

        if (!empty($filters['resident_id'])) {
            $conditions[] = "v.resident_id = ?";
            $params[] = (int)$filters['resident_id'];
        }

        if (!empty($filters['vehicle_type']) && $filters['vehicle_type'] !== 'ALL') {
            $conditions[] = "v.vehicle_type = ?";
            $params[] = $filters['vehicle_type'];
        }

        if (!empty($filters['pass_status']) && $filters['pass_status'] !== 'ALL') {
            $conditions[] = "v.pass_status = ?";
            $params[] = $filters['pass_status'];
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY v.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getByResidentId(int $residentId): array {
        $stmt = $this->db->prepare("SELECT v.*, u.unit_code FROM vehicles v JOIN units u ON v.unit_id = u.id WHERE v.resident_id = ? AND v.is_deleted = 0 ORDER BY v.id ASC");
        $stmt->execute([$residentId]);
        return $stmt->fetchAll();
    }

    public function getByUnitId(int $unitId): array {
        $stmt = $this->db->prepare("SELECT * FROM vehicles WHERE unit_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $stmt->execute([$unitId]);
        return $stmt->fetchAll();
    }

    public function findById(int $id, ?int $societyId = null): ?array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $sql = "SELECT v.*, u.unit_code FROM vehicles v JOIN units u ON v.unit_id = u.id WHERE v.id = ? AND v.is_deleted = 0";
        $params = [$id];
        if ($societyId > 0) {
            $sql .= " AND v.society_id = ?";
            $params[] = $societyId;
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: ($data['society_id'] ?? $this->getSocietyId());
        $stmt = $this->db->prepare("INSERT INTO vehicles 
            (society_id, unit_id, resident_id, vehicle_number, vehicle_type, make_model, parking_slot_number, rfid_sticker_tag, pass_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $societyId,
            $data['unit_id'],
            $data['resident_id'] ?? null,
            $data['vehicle_number'],
            $data['vehicle_type'] ?? 'Car',
            $data['make_model'] ?? null,
            $data['parking_slot_number'] ?? null,
            !empty($data['rfid_sticker_tag']) ? $data['rfid_sticker_tag'] : null,
            $data['pass_status'] ?? 'Valid'
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, ?int $societyId = null): bool {
        $fields = [];
        $params = [];

        if (isset($data['vehicle_number'])) {
            $fields[] = "vehicle_number = ?";
            $params[] = $data['vehicle_number'];
        }
        if (isset($data['vehicle_type'])) {
            $fields[] = "vehicle_type = ?";
            $params[] = $data['vehicle_type'];
        }
        if (array_key_exists('make_model', $data)) {
            $fields[] = "make_model = ?";
            $params[] = $data['make_model'];
        }
        if (array_key_exists('parking_slot_number', $data)) {
            $fields[] = "parking_slot_number = ?";
            $params[] = $data['parking_slot_number'];
        }
        if (array_key_exists('rfid_sticker_tag', $data)) {
            $fields[] = "rfid_sticker_tag = ?";
            $params[] = !empty($data['rfid_sticker_tag']) ? $data['rfid_sticker_tag'] : null;
        }
        if (isset($data['pass_status'])) {
            $fields[] = "pass_status = ?";
            $params[] = $data['pass_status'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE vehicles SET " . implode(', ', $fields) . " WHERE id = ?";
        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id, ?int $societyId = null): bool {
        $sql = "UPDATE vehicles SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
        $params = [$id];
        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}