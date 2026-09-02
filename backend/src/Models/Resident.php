<?php
namespace App\Models;

use PDO;

class Resident extends BaseModel {
    public function getAll(?int $societyId = null, array $filters = []): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $params = [];
        $conditions = ['r.is_deleted = 0'];

        $sql = "SELECT r.*, 
                       u.full_name, u.email, u.phone, u.avatar_url, u.user_code,
                       un.unit_code, un.floor_number, un.unit_type, un.sqft_area, un.maintenance_status,
                       un.owner_name, un.contact_phone as unit_owner_phone, un.contact_email as unit_owner_email,
                       t.name as tower_name, t.tower_code,
                       vb.full_name as verified_by_name
                FROM residents r
                LEFT JOIN users u ON r.user_id = u.id
                JOIN units un ON r.unit_id = un.id
                JOIN towers t ON un.tower_id = t.id
                LEFT JOIN users vb ON r.verified_by_user_id = vb.id";

        if ($societyId > 0) {
            $conditions[] = "r.society_id = ?";
            $params[] = $societyId;
        }

        if (!empty($filters['tower']) && $filters['tower'] !== 'ALL') {
            $conditions[] = "t.name = ?";
            $params[] = $filters['tower'];
        }

        if (!empty($filters['resident_type']) && $filters['resident_type'] !== 'ALL') {
            $conditions[] = "r.resident_type = ?";
            $params[] = $filters['resident_type'];
        }

        if (!empty($filters['verification_status']) && $filters['verification_status'] !== 'ALL') {
            $conditions[] = "r.verification_status = ?";
            $params[] = $filters['verification_status'];
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR un.unit_code LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY r.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $residents = $stmt->fetchAll();

        // Attach family members, documents, vehicles, and linked owner
        $famStmt = $this->db->prepare("SELECT * FROM family_members WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $docStmt = $this->db->prepare("SELECT * FROM resident_documents WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $vehStmt = $this->db->prepare("SELECT * FROM vehicles WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $ownerStmt = $this->db->prepare("SELECT u.full_name, u.phone, u.email 
                                         FROM residents r2 
                                         JOIN users u ON r2.user_id = u.id 
                                         WHERE r2.unit_id = ? AND r2.resident_type = 'Owner' AND r2.is_deleted = 0
                                         LIMIT 1");

        return array_map(function($r) use ($famStmt, $docStmt, $vehStmt, $ownerStmt) {
            $famStmt->execute([$r['id']]);
            $family = $famStmt->fetchAll();

            $docStmt->execute([$r['id']]);
            $documents = $docStmt->fetchAll();

            $vehStmt->execute([$r['id']]);
            $vehicles = $vehStmt->fetchAll();

            $ownerName = null;
            $ownerPhone = null;
            $ownerEmail = null;

            if ($r['resident_type'] === 'Tenant') {
                $ownerStmt->execute([$r['unit_id']]);
                $ownerRow = $ownerStmt->fetch();
                if ($ownerRow) {
                    $ownerName = $ownerRow['full_name'];
                    $ownerPhone = $ownerRow['phone'];
                    $ownerEmail = $ownerRow['email'];
                } else if (!empty($r['owner_name'])) {
                    $ownerName = $r['owner_name'];
                    $ownerPhone = $r['unit_owner_phone'] ?? null;
                    $ownerEmail = $r['unit_owner_email'] ?? null;
                }
            }

            return [
                'id' => "RES-" . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
                'resident_id' => (int)$r['id'],
                'residentDbId' => (int)$r['id'],
                'society_id' => (int)$r['society_id'],
                'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                'unit_id' => (int)$r['unit_id'],
                'flatNumber' => $r['unit_code'],
                'tower' => $r['tower_name'],
                'floor' => (int)$r['floor_number'],
                'unit_type' => $r['unit_type'],
                'name' => $r['full_name'] ?? 'Resident #' . $r['id'],
                'full_name' => $r['full_name'] ?? 'Resident #' . $r['id'],
                'email' => $r['email'] ?? '',
                'phone' => $r['phone'] ?? '',
                'avatar' => $r['avatar_url'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80',
                'avatar_url' => $r['avatar_url'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80',
                'role' => $r['resident_type'],
                'resident_type' => $r['resident_type'],
                'owner_name' => $ownerName,
                'ownerName' => $ownerName,
                'owner_phone' => $ownerPhone,
                'ownerPhone' => $ownerPhone,
                'owner_email' => $ownerEmail,
                'ownerEmail' => $ownerEmail,
                'is_primary_contact' => (bool)$r['is_primary_contact'],
                'move_in_date' => $r['move_in_date'],
                'moveInDate' => $r['move_in_date'],
                'move_out_date' => $r['move_out_date'],
                'verification_status' => $r['verification_status'],
                'verificationStatus' => $r['verification_status'],
                'verified_by_user_id' => $r['verified_by_user_id'] ? (int)$r['verified_by_user_id'] : null,
                'verified_by_name' => $r['verified_by_name'],
                'rejection_reason' => $r['rejection_reason'],
                'rejectionReason' => $r['rejection_reason'],
                'status' => $r['maintenance_status'] ?? 'Paid',
                'maintenanceStatus' => $r['maintenance_status'] ?? 'Paid',
                'members_count' => count($family) + 1,
                'membersCount' => count($family) + 1,
                'family_members' => $family,
                'documents' => $documents,
                'vehicles' => $vehicles,
                'created_at' => $r['created_at']
            ];
        }, $residents);
    }

    public function findById(int $id, ?int $societyId = null): ?array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $sql = "SELECT r.*, 
                       u.full_name, u.email, u.phone, u.avatar_url, u.user_code,
                       un.unit_code, un.floor_number, un.unit_type, un.sqft_area, un.maintenance_status,
                       un.owner_name, un.contact_phone as unit_owner_phone, un.contact_email as unit_owner_email,
                       t.name as tower_name, t.tower_code,
                       vb.full_name as verified_by_name
                FROM residents r
                LEFT JOIN users u ON r.user_id = u.id
                JOIN units un ON r.unit_id = un.id
                JOIN towers t ON un.tower_id = t.id
                LEFT JOIN users vb ON r.verified_by_user_id = vb.id
                WHERE r.id = ? AND r.is_deleted = 0";

        if ($societyId > 0) {
            $scopedSql = $sql . " AND r.society_id = ? LIMIT 1";
            $stmt = $this->db->prepare($scopedSql);
            $stmt->execute([$id, $societyId]);
            $r = $stmt->fetch();
            if ($r) {
                return $this->hydrateResidentRecord($r);
            }
        }

        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) return null;

        return $this->hydrateResidentRecord($r);
    }

    private function hydrateResidentRecord(array $r): array {
        $id = (int)$r['id'];
        $famStmt = $this->db->prepare("SELECT * FROM family_members WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $famStmt->execute([$id]);
        $family = $famStmt->fetchAll();

        $docStmt = $this->db->prepare("SELECT * FROM resident_documents WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $docStmt->execute([$id]);
        $documents = $docStmt->fetchAll();

        $vehStmt = $this->db->prepare("SELECT * FROM vehicles WHERE resident_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $vehStmt->execute([$id]);
        $vehicles = $vehStmt->fetchAll();

        $ownerName = null;
        $ownerPhone = null;
        $ownerEmail = null;

        if ($r['resident_type'] === 'Tenant') {
            $ownerStmt = $this->db->prepare("SELECT u.full_name, u.phone, u.email 
                                             FROM residents r2 
                                             JOIN users u ON r2.user_id = u.id 
                                             WHERE r2.unit_id = ? AND r2.resident_type = 'Owner' AND r2.is_deleted = 0
                                             LIMIT 1");
            $ownerStmt->execute([$r['unit_id']]);
            $ownerRow = $ownerStmt->fetch();
            if ($ownerRow) {
                $ownerName = $ownerRow['full_name'];
                $ownerPhone = $ownerRow['phone'];
                $ownerEmail = $ownerRow['email'];
            } else if (!empty($r['owner_name'])) {
                $ownerName = $r['owner_name'];
                $ownerPhone = $r['unit_owner_phone'] ?? null;
                $ownerEmail = $r['unit_owner_email'] ?? null;
            }
        }

        return [
            'id' => "RES-" . str_pad($r['id'], 4, '0', STR_PAD_LEFT),
            'resident_id' => (int)$r['id'],
            'residentDbId' => (int)$r['id'],
            'society_id' => (int)$r['society_id'],
            'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
            'unit_id' => (int)$r['unit_id'],
            'flatNumber' => $r['unit_code'],
            'tower' => $r['tower_name'],
            'floor' => (int)$r['floor_number'],
            'unit_type' => $r['unit_type'],
            'sqft_area' => $r['sqft_area'],
            'name' => $r['full_name'] ?? 'Resident #' . $r['id'],
            'full_name' => $r['full_name'] ?? 'Resident #' . $r['id'],
            'email' => $r['email'] ?? '',
            'phone' => $r['phone'] ?? '',
            'avatar' => $r['avatar_url'] ?? '',
            'avatar_url' => $r['avatar_url'] ?? '',
            'role' => $r['resident_type'],
            'resident_type' => $r['resident_type'],
            'owner_name' => $ownerName,
            'ownerName' => $ownerName,
            'owner_phone' => $ownerPhone,
            'ownerPhone' => $ownerPhone,
            'owner_email' => $ownerEmail,
            'ownerEmail' => $ownerEmail,
            'is_primary_contact' => (bool)$r['is_primary_contact'],
            'move_in_date' => $r['move_in_date'],
            'moveInDate' => $r['move_in_date'],
            'move_out_date' => $r['move_out_date'],
            'verification_status' => $r['verification_status'],
            'verificationStatus' => $r['verification_status'],
            'verified_by_user_id' => $r['verified_by_user_id'] ? (int)$r['verified_by_user_id'] : null,
            'verified_by_name' => $r['verified_by_name'],
            'rejection_reason' => $r['rejection_reason'],
            'rejectionReason' => $r['rejection_reason'],
            'status' => $r['maintenance_status'] ?? 'Paid',
            'maintenanceStatus' => $r['maintenance_status'] ?? 'Paid',
            'members_count' => count($family) + 1,
            'membersCount' => count($family) + 1,
            'family_members' => $family,
            'documents' => $documents,
            'vehicles' => $vehicles,
            'created_at' => $r['created_at']
        ];
    }

    public function findByUnitId(int $unitId, ?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $sql = "SELECT r.id FROM residents r WHERE r.unit_id = ? AND r.is_deleted = 0";
        $params = [$unitId];
        if ($societyId > 0) {
            $sql .= " AND r.society_id = ?";
            $params[] = $societyId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $results = [];
        foreach ($rows as $row) {
            $details = $this->findById((int)$row['id'], $societyId);
            if ($details) $results[] = $details;
        }
        return $results;
    }

    public function getByUnitId(int $unitId, ?int $societyId = null): array {
        return $this->findByUnitId($unitId, $societyId);
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: ($data['society_id'] ?? $this->getSocietyId());
        $stmt = $this->db->prepare("INSERT INTO residents 
            (society_id, user_id, unit_id, resident_type, is_primary_contact, move_in_date, move_out_date, verification_status, verified_by_user_id, rejection_reason) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $moveInDate = !empty($data['move_in_date']) ? $data['move_in_date'] : date('Y-m-d');
        if (strtotime($moveInDate) === false) {
            $moveInDate = date('Y-m-d');
        }

        $stmt->execute([
            $societyId,
            $data['user_id'] ?? null,
            $data['unit_id'],
            $data['resident_type'] ?? ($data['role'] ?? 'Owner'),
            isset($data['is_primary_contact']) ? (int)$data['is_primary_contact'] : 1,
            $moveInDate,
            $data['move_out_date'] ?? null,
            $data['verification_status'] ?? 'Pending',
            $data['verified_by_user_id'] ?? null,
            $data['rejection_reason'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, ?int $societyId = null): bool {
        $fields = [];
        $params = [];

        if (isset($data['resident_type'])) {
            $fields[] = "resident_type = ?";
            $params[] = $data['resident_type'];
        }
        if (isset($data['is_primary_contact'])) {
            $fields[] = "is_primary_contact = ?";
            $params[] = (int)$data['is_primary_contact'];
        }
        if (isset($data['move_in_date'])) {
            $fields[] = "move_in_date = ?";
            $params[] = $data['move_in_date'];
        }
        if (array_key_exists('move_out_date', $data)) {
            $fields[] = "move_out_date = ?";
            $params[] = $data['move_out_date'];
        }
        if (isset($data['verification_status'])) {
            $fields[] = "verification_status = ?";
            $params[] = $data['verification_status'];
        }
        if (array_key_exists('verified_by_user_id', $data)) {
            $fields[] = "verified_by_user_id = ?";
            $params[] = $data['verified_by_user_id'];
        }
        if (array_key_exists('rejection_reason', $data)) {
            $fields[] = "rejection_reason = ?";
            $params[] = $data['rejection_reason'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE residents SET " . implode(', ', $fields) . " WHERE id = ?";
        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateVerificationStatus(int $id, string $status, ?int $verifiedByUserId = null, ?string $rejectionReason = null, ?int $societyId = null): bool {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        $sql = "UPDATE residents SET 
                verification_status = ?, 
                verified_by_user_id = ?, 
                rejection_reason = ? 
                WHERE id = ?";
        $params = [$status, $verifiedByUserId, $rejectionReason, $id];
        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id, ?int $societyId = null): bool {
        $sql = "UPDATE residents SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
        $params = [$id];
        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function linkOccupancy(int $unitId, int $residentId, string $type = 'Owner'): bool {
        $stmt = $this->db->prepare("INSERT INTO unit_occupancies (unit_id, resident_id, occupancy_type, is_primary) VALUES (?, ?, ?, 1)");
        return $stmt->execute([$unitId, $residentId, $type]);
    }

    public function registerVehicle(int $unitId, ?int $residentId, string $type, string $tag, ?int $societyId = null): bool {
        $vehicleModel = new Vehicle($this->db);
        $vehId = $vehicleModel->create([
            'unit_id' => $unitId,
            'resident_id' => $residentId,
            'vehicle_number' => $tag,
            'vehicle_type' => $type,
            'make_model' => $type,
            'society_id' => $societyId ?: $this->getSocietyId()
        ]);
        return $vehId > 0;
    }
}