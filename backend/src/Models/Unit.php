<?php
namespace App\Models;

use PDO;

class Unit extends BaseModel {
    public function getUnits(array $filters = [], ?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();

        $sql = "SELECT u.*, t.name AS tower_name, t.tower_code,
                       COALESCE(
                           (SELECT usr.full_name FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Owner' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                           u.owner_name
                       ) AS resolved_owner_name,
                       COALESCE(
                           (SELECT usr.phone FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Owner' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                           u.contact_phone
                       ) AS resolved_contact_phone,
                       COALESCE(
                           (SELECT usr.email FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Owner' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                           u.contact_email
                       ) AS resolved_contact_email,
                       COALESCE(
                           (SELECT usr.full_name FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Tenant' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                           u.tenant_name
                       ) AS resolved_tenant_name
                FROM units u 
                JOIN towers t ON u.tower_id = t.id";
        $params = [];
        $where = ['u.is_deleted = 0'];

        if ($societyId > 0) {
            $where[] = "u.society_id = ?";
            $params[] = $societyId;
        }

        if (!empty($filters['tower']) && $filters['tower'] !== 'ALL') {
            $where[] = "t.name = ?";
            $params[] = $filters['tower'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'ALL') {
            $where[] = "u.occupancy_status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['floor']) && $filters['floor'] !== 'ALL') {
            $where[] = "u.floor_number = ?";
            $params[] = (int)$filters['floor'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(u.unit_code LIKE ? OR u.owner_name LIKE ? OR u.tenant_name LIKE ? OR u.contact_phone LIKE ?)";
            $term = "%{$filters['search']}%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY u.society_id ASC, t.id ASC, u.floor_number DESC, u.unit_code ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $units = $stmt->fetchAll();

        // Get vehicles for this society
        if ($societyId > 0) {
            $vehiclesStmt = $this->db->prepare("SELECT v.* FROM vehicles v JOIN units u ON v.unit_id = u.id WHERE u.society_id = ? AND v.is_deleted = 0");
            $vehiclesStmt->execute([$societyId]);
        } else {
            $vehiclesStmt = $this->db->query("SELECT v.* FROM vehicles v JOIN units u ON v.unit_id = u.id WHERE v.is_deleted = 0");
        }
        $vehiclesByUnit = [];
        while ($v = $vehiclesStmt->fetch()) {
            $label = ($v['make_model'] ?? $v['vehicle_type'] ?? 'Car') . (!empty($v['vehicle_number']) ? " ({$v['vehicle_number']})" : "");
            $vehiclesByUnit[$v['unit_id']][] = $label;
        }

        return array_map(function($u) use ($vehiclesByUnit) {
            $finalOwner = $u['resolved_owner_name'] ?? $u['owner_name'];
            $finalTenant = $u['resolved_tenant_name'] ?? $u['tenant_name'];
            $finalPhone = $u['resolved_contact_phone'] ?? $u['contact_phone'];
            $finalEmail = $u['resolved_contact_email'] ?? $u['contact_email'];

            return [
                'id' => "FLAT-{$u['unit_code']}",
                'unitDbId' => (int)$u['id'],
                'flatNumber' => $u['unit_code'],
                'tower' => $u['tower_name'],
                'floor' => (int)$u['floor_number'],
                'type' => $u['unit_type'],
                'status' => $u['occupancy_status'],
                'ownerName' => $finalOwner,
                'tenantName' => $finalTenant,
                'phone' => $finalPhone,
                'email' => $finalEmail,
                'maintenanceStatus' => $u['maintenance_status'],
                'vehicles' => $vehiclesByUnit[$u['id']] ?? [],
                'moveInDate' => $u['occupancy_status'] !== 'Vacant' ? '2023-2024' : null,
                'emergencyContact' => $u['occupancy_status'] !== 'Vacant' ? [
                    'name' => 'Family / Concierge',
                    'phone' => '+91 98200-91100',
                    'relation' => 'Support'
                ] : null
            ];
        }, $units);
    }

    public function findByCode(string $code, ?int $societyId = null): ?array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $sql = "SELECT u.*, t.name AS tower_name,
            COALESCE(
                (SELECT usr.full_name FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Owner' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                u.owner_name
            ) AS resolved_owner_name,
            COALESCE(
                (SELECT usr.phone FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Owner' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                u.contact_phone
            ) AS resolved_contact_phone,
            COALESCE(
                (SELECT usr.email FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Owner' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                u.contact_email
            ) AS resolved_contact_email,
            COALESCE(
                (SELECT usr.full_name FROM residents r JOIN users usr ON r.user_id = usr.id WHERE r.unit_id = u.id AND r.resident_type = 'Tenant' AND r.is_deleted = 0 ORDER BY r.id DESC LIMIT 1),
                u.tenant_name
            ) AS resolved_tenant_name
            FROM units u 
            JOIN towers t ON u.tower_id = t.id 
            WHERE (
                u.unit_code = ? 
                OR UPPER(REPLACE(REPLACE(REPLACE(u.unit_code, '-', ''), ' ', ''), '_', '')) = UPPER(REPLACE(REPLACE(REPLACE(?, '-', ''), ' ', ''), '_', ''))
            )
            AND u.is_deleted = 0";

        $params = [$code, $code];
        if ($societyId > 0) {
            $sql .= " AND u.society_id = ?";
            $params[] = $societyId;
        }
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['resolved_owner_name'])) $row['owner_name'] = $row['resolved_owner_name'];
            if (!empty($row['resolved_tenant_name'])) $row['tenant_name'] = $row['resolved_tenant_name'];
            if (!empty($row['resolved_contact_phone'])) $row['contact_phone'] = $row['resolved_contact_phone'];
            if (!empty($row['resolved_contact_email'])) $row['contact_email'] = $row['resolved_contact_email'];
        }
        return $row ?: null;
    }

    public function updateOccupancy(int $unitId, array $data, ?int $societyId = null): bool {
        $societyId = $societyId ?: $this->getSocietyId();
        $fields = [];
        $params = [];

        if (isset($data['occupancy_status'])) {
            $fields[] = "occupancy_status = ?";
            $params[] = $data['occupancy_status'];
        }
        if (array_key_exists('owner_name', $data)) {
            $fields[] = "owner_name = ?";
            $params[] = $data['owner_name'];
        }
        if (array_key_exists('tenant_name', $data)) {
            $fields[] = "tenant_name = ?";
            $params[] = $data['tenant_name'];
        }
        if (array_key_exists('contact_phone', $data)) {
            $fields[] = "contact_phone = ?";
            $params[] = $data['contact_phone'];
        }
        if (array_key_exists('contact_email', $data)) {
            $fields[] = "contact_email = ?";
            $params[] = $data['contact_email'];
        }
        if (isset($data['maintenance_status'])) {
            $fields[] = "maintenance_status = ?";
            $params[] = $data['maintenance_status'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $unitId;
        $params[] = $societyId;

        $sql = "UPDATE units SET " . implode(', ', $fields) . " WHERE id = ? AND society_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function countByStatus(?int $societyId = null): array {
        $societyId = $societyId ?: $this->getSocietyId();
        $total = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND is_deleted = 0")->fetchColumn();
        $occupied = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND occupancy_status != 'Vacant' AND is_deleted = 0")->fetchColumn();
        $vacant = $total - $occupied;
        $owners = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND occupancy_status = 'Occupied (Owner)' AND is_deleted = 0")->fetchColumn();
        $tenants = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND occupancy_status = 'Occupied (Tenant)' AND is_deleted = 0")->fetchColumn();

        return [
            'total' => $total,
            'occupied' => $occupied,
            'vacant' => $vacant,
            'owners' => $owners,
            'tenants' => $tenants
        ];
    }

    public function create(array $data): int {
        $societyId = $data['society_id'] ?? $this->getSocietyId();
        $stmt = $this->db->prepare("INSERT INTO units 
            (society_id, tower_id, unit_code, floor_number, unit_type, sqft_area, occupancy_status, maintenance_status, owner_name, contact_phone, contact_email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $societyId,
            $data['tower_id'],
            $data['unit_code'],
            (int)($data['floor_number'] ?? 1),
            $data['unit_type'] ?? '2BHK',
            (int)($data['sqft_area'] ?? 1000),
            $data['occupancy_status'] ?? 'Vacant',
            $data['maintenance_status'] ?? 'Paid',
            $data['owner_name'] ?? null,
            $data['contact_phone'] ?? null,
            $data['contact_email'] ?? null
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function bulkCreate(array $unitsList, ?int $societyId = null): int {
        $societyId = $societyId ?: $this->getSocietyId();
        $manageTx = !$this->db->inTransaction();
        if ($manageTx) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO units 
                (society_id, tower_id, unit_code, floor_number, unit_type, sqft_area, occupancy_status, maintenance_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $createdCount = 0;
            foreach ($unitsList as $u) {
                // Check if unit already exists
                $check = $this->db->prepare("SELECT id FROM units WHERE society_id = ? AND unit_code = ? LIMIT 1");
                $check->execute([$societyId, $u['unit_code']]);
                if (!$check->fetch()) {
                    $stmt->execute([
                        $societyId,
                        $u['tower_id'],
                        $u['unit_code'],
                        (int)$u['floor_number'],
                        $u['unit_type'] ?? '2BHK',
                        (int)($u['sqft_area'] ?? 1000),
                        $u['occupancy_status'] ?? 'Vacant',
                        'Paid'
                    ]);
                    $createdCount++;
                }
            }
            if ($manageTx) {
                $this->db->commit();
            }
            return $createdCount;
        } catch (\Exception $e) {
            if ($manageTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
