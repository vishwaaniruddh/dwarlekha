<?php
namespace App\Models;

use App\Config\Database;
use App\Config\TenantContext;
use PDO;
use Exception;

class SocietyContact extends BaseModel {
    protected string $table = 'society_contacts';

    public function getAll(?int $societyId = null, array $filters = []): array {
        $db = Database::getConnection();
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();

        $sql = "SELECT c.*, c.designation as position, c.unit_or_office as address, c.availability_hours as availability, s.name as society_name, s.society_code 
                FROM {$this->table} c 
                JOIN societies s ON c.society_id = s.id 
                WHERE c.is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND c.society_id = ?";
            $params[] = $societyId;
        }

        if (!empty($filters['contact_type']) && $filters['contact_type'] !== 'all' && $filters['contact_type'] !== 'ALL') {
            $sql .= " AND c.contact_type = ?";
            $params[] = $filters['contact_type'];
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all' && $filters['category'] !== 'ALL') {
            $sql .= " AND c.category = ?";
            $params[] = $filters['category'];
        }

        if (isset($filters['is_emergency']) && $filters['is_emergency'] !== '' && $filters['is_emergency'] !== null) {
            $sql .= " AND c.is_emergency = ?";
            $params[] = (int)$filters['is_emergency'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $sql .= " AND c.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $q = '%' . trim($filters['search']) . '%';
            $sql .= " AND (c.name LIKE ? OR c.designation LIKE ? OR c.phone LIKE ? OR c.category LIKE ? OR c.remark LIKE ?)";
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        $sql .= " ORDER BY c.is_emergency DESC, c.display_order ASC, c.id ASC";

        // Check if pagination requested
        if (isset($filters['limit']) && isset($filters['offset'])) {
            $limit = (int)$filters['limit'];
            $offset = (int)$filters['offset'];
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countTotal(?int $societyId = null, array $filters = []): int {
        $db = Database::getConnection();
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }

        if (!empty($filters['contact_type']) && $filters['contact_type'] !== 'all' && $filters['contact_type'] !== 'ALL') {
            $sql .= " AND contact_type = ?";
            $params[] = $filters['contact_type'];
        }

        if (!empty($filters['category']) && $filters['category'] !== 'all' && $filters['category'] !== 'ALL') {
            $sql .= " AND category = ?";
            $params[] = $filters['category'];
        }

        if (isset($filters['is_emergency']) && $filters['is_emergency'] !== '' && $filters['is_emergency'] !== null) {
            $sql .= " AND is_emergency = ?";
            $params[] = (int)$filters['is_emergency'];
        }

        if (!empty($filters['search'])) {
            $q = '%' . trim($filters['search']) . '%';
            $sql .= " AND (name LIKE ? OR designation LIKE ? OR phone LIKE ? OR category LIKE ? OR remark LIKE ?)";
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function findById(int $id, ?int $societyId = null): ?array {
        $db = Database::getConnection();
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();

        $sql = "SELECT c.*, c.designation as position, c.unit_or_office as address, c.availability_hours as availability, s.name as society_name, s.society_code 
                FROM {$this->table} c 
                JOIN societies s ON c.society_id = s.id 
                WHERE c.id = ? AND c.is_deleted = 0";
        $params = [$id];

        if ($societyId > 0) {
            $sql .= " AND c.society_id = ?";
            $params[] = $societyId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public function create(array $data): int {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("INSERT INTO {$this->table} (
                society_id, contact_type, category, name, designation, phone, alternate_phone, email,
                avatar_url, unit_or_office, availability_hours, remark, is_emergency, is_verified, display_order, status, is_deleted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

            $stmt->execute([
                (int)($data['society_id'] ?? $this->getSocietyId()),
                $data['contact_type'] ?? 'management',
                $data['category'] ?? 'General',
                trim($data['name'] ?? ''),
                $data['designation'] ?? $data['position'] ?? null,
                trim($data['phone'] ?? ''),
                $data['alternate_phone'] ?? null,
                $data['email'] ?? null,
                $data['avatar_url'] ?? null,
                $data['unit_or_office'] ?? $data['address'] ?? null,
                $data['availability_hours'] ?? $data['availability'] ?? '24x7',
                $data['remark'] ?? null,
                !empty($data['is_emergency']) ? 1 : 0,
                isset($data['is_verified']) ? (int)$data['is_verified'] : 1,
                (int)($data['display_order'] ?? 0),
                $data['status'] ?? 'Active'
            ]);

            $id = (int)$db->lastInsertId();
            if ($manageTx) $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data, ?int $societyId = null): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $allowedMap = [
                'society_id' => fn($v) => (int)$v,
                'contact_type' => fn($v) => $v,
                'category' => fn($v) => $v,
                'name' => fn($v) => trim($v),
                'designation' => fn($v) => $v,
                'position' => fn($v) => $v,
                'phone' => fn($v) => trim($v),
                'alternate_phone' => fn($v) => $v,
                'email' => fn($v) => $v,
                'avatar_url' => fn($v) => $v,
                'unit_or_office' => fn($v) => $v,
                'address' => fn($v) => $v,
                'availability_hours' => fn($v) => $v,
                'availability' => fn($v) => $v,
                'remark' => fn($v) => $v,
                'is_emergency' => fn($v) => !empty($v) ? 1 : 0,
                'is_verified' => fn($v) => (int)$v,
                'display_order' => fn($v) => (int)$v,
                'status' => fn($v) => $v
            ];

            $setSql = [];
            $params = [];

            foreach ($data as $key => $val) {
                if (isset($allowedMap[$key])) {
                    $col = $key;
                    if ($key === 'position') $col = 'designation';
                    if ($key === 'address') $col = 'unit_or_office';
                    if ($key === 'availability') $col = 'availability_hours';

                    $setSql[] = "`{$col}` = ?";
                    $params[] = $allowedMap[$key]($val);
                }
            }

            if (empty($setSql)) {
                if ($manageTx) $db->commit();
                return true;
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $setSql) . " WHERE id = ? AND is_deleted = 0";
            $params[] = $id;

            if ($societyId !== null && $societyId > 0) {
                $sql .= " AND society_id = ?";
                $params[] = $societyId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function softDelete(int $id, ?int $societyId = null): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $sql = "UPDATE {$this->table} SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND is_deleted = 0";
            $params = [$id];

            if ($societyId !== null && $societyId > 0) {
                $sql .= " AND society_id = ?";
                $params[] = $societyId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function getCategories(?int $societyId = null): array {
        $db = Database::getConnection();
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();

        $sql = "SELECT DISTINCT contact_type, category FROM {$this->table} WHERE is_deleted = 0";
        $params = [];
        if ($societyId > 0) {
            $sql .= " AND society_id = ?";
            $params[] = $societyId;
        }
        $sql .= " ORDER BY contact_type ASC, category ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
