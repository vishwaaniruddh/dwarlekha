<?php
namespace App\Models;

use PDO;

class User extends BaseModel {
    public function getAll(?int $societyId = null, array $filters = []): array {
        $params = [];
        $conditions = ['u.is_deleted = 0'];

        $sql = "SELECT u.id, u.user_code, u.society_id, u.is_parent_user, u.full_name, u.email, u.email_verified_at, u.role_id, 
                       u.phone, u.unit_code, u.resident_id, u.status, u.avatar_url, 
                       u.last_login_at, u.created_at, u.updated_at,
                       r.role_code, r.name as role_name, r.badge_color as role_badge_color,
                       s.name as society_name, s.society_code
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN societies s ON u.society_id = s.id";

        if (isset($filters['is_parent_user'])) {
            $conditions[] = "u.is_parent_user = ?";
            $params[] = (int)$filters['is_parent_user'];
        } elseif ($societyId !== null && $societyId > 0) {
            if (isset($filters['include_parent']) && $filters['include_parent'] === false) {
                $conditions[] = "u.society_id = ? AND u.is_parent_user = 0";
                $params[] = $societyId;
            } else {
                $conditions[] = "(u.society_id = ? OR u.is_parent_user = 1)";
                $params[] = $societyId;
            }
        }

        if (!empty($filters['role'])) {
            $conditions[] = "r.role_code = ?";
            $params[] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = "u.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.unit_code LIKE ? OR u.user_code LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY u.is_parent_user DESC, u.id ASC";

        if (!empty($filters['limit']) && (int)$filters['limit'] > 0) {
            $limit = (int)$filters['limit'];
            $page = max(1, (int)($filters['page'] ?? 1));
            $offset = ($page - 1) * $limit;
            $sql .= " LIMIT $limit OFFSET $offset";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getCount(?int $societyId = null, array $filters = []): int {
        $params = [];
        $conditions = [];

        $sql = "SELECT COUNT(*) as total
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN societies s ON u.society_id = s.id";

        if (isset($filters['is_parent_user'])) {
            $conditions[] = "u.is_parent_user = ?";
            $params[] = (int)$filters['is_parent_user'];
        } elseif ($societyId !== null && $societyId > 0) {
            if (isset($filters['include_parent']) && $filters['include_parent'] === false) {
                $conditions[] = "u.society_id = ? AND u.is_parent_user = 0";
                $params[] = $societyId;
            } else {
                $conditions[] = "(u.society_id = ? OR u.is_parent_user = 1)";
                $params[] = $societyId;
            }
        }

        if (!empty($filters['role'])) {
            $conditions[] = "r.role_code = ?";
            $params[] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = "u.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR u.unit_code LIKE ? OR u.user_code LIKE ?)";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch();
        return (int)($res['total'] ?? 0);
    }


    public function findById(int $id, ?int $societyId = null): ?array {
        $sql = "SELECT u.id, u.user_code, u.society_id, u.is_parent_user, u.full_name, u.email, u.role_id, 
                       u.phone, u.unit_code, u.resident_id, u.status, u.avatar_url, 
                       u.last_login_at, u.created_at, u.updated_at,
                       r.role_code, r.name as role_name, r.badge_color as role_badge_color,
                       s.name as society_name, s.society_code
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN societies s ON u.society_id = s.id
                WHERE u.id = ? AND u.is_deleted = 0";
        $params = [$id];

        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND (u.society_id = ? OR u.is_parent_user = 1)";
            $params[] = $societyId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findByEmail(string $email, ?int $societyId = null): ?array {
        return $this->findByIdentifier($email, $societyId);
    }

    public function findByIdentifier(string $identifier, ?int $societyId = null): ?array {
        $clean = trim($identifier);
        $sql = "SELECT u.*, 
                       r.role_code, r.name as role_name, r.badge_color as role_badge_color,
                       s.name as society_name, s.society_code
                FROM users u
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN societies s ON u.society_id = s.id
                WHERE (u.email = ? OR u.phone = ? OR u.user_code = ?) AND u.is_deleted = 0";
        $params = [$clean, $clean, $clean];

        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND (u.society_id = ? OR u.is_parent_user = 1)";
            $params[] = $societyId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findByCode(string $code): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE user_code = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$code]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(array $data, ?int $societyId = null): int {
        $isParentUser = !empty($data['is_parent_user']) ? 1 : 0;
        $finalSocietyId = $isParentUser ? null : ($data['society_id'] ?? $societyId);

        $stmt = $this->db->prepare("INSERT INTO users 
            (user_code, society_id, is_parent_user, full_name, email, password_hash, role_id, phone, unit_code, resident_id, status, avatar_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $data['user_code'],
            $finalSocietyId,
            $isParentUser,
            $data['full_name'],
            $data['email'],
            $data['password_hash'],
            $data['role_id'],
            $data['phone'] ?? null,
            $data['unit_code'] ?? null,
            $data['resident_id'] ?? null,
            $data['status'] ?? 'Active',
            $data['avatar_url'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, ?int $societyId = null): bool {
        $fields = [];
        $params = [];

        if (isset($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = $data['full_name'];
        }
        if (isset($data['email'])) {
            $fields[] = "email = ?";
            $params[] = $data['email'];
        }
        if (array_key_exists('email_verified_at', $data)) {
            $fields[] = "email_verified_at = ?";
            $params[] = $data['email_verified_at'];
        }
        if (isset($data['role_id'])) {
            $fields[] = "role_id = ?";
            $params[] = $data['role_id'];
        }
        if (isset($data['phone'])) {
            $fields[] = "phone = ?";
            $params[] = $data['phone'];
        }
        if (isset($data['unit_code'])) {
            $fields[] = "unit_code = ?";
            $params[] = $data['unit_code'];
        }
        if (isset($data['status'])) {
            $fields[] = "status = ?";
            $params[] = $data['status'];
        }
        if (isset($data['password_hash'])) {
            $fields[] = "password_hash = ?";
            $params[] = $data['password_hash'];
        }
        if (isset($data['avatar_url'])) {
            $fields[] = "avatar_url = ?";
            $params[] = $data['avatar_url'];
        }
        if (isset($data['is_parent_user'])) {
            $fields[] = "is_parent_user = ?";
            $params[] = (int)$data['is_parent_user'];
        }
        if (array_key_exists('society_id', $data)) {
            $fields[] = "society_id = ?";
            $params[] = $data['society_id'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND (society_id = ? OR is_parent_user = 1)";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateLastLogin(int $userId): bool {
        $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    public function delete(int $id, ?int $societyId = null): bool {
        $sql = "UPDATE users SET is_deleted = 1, deleted_at = NOW() WHERE id = ?";
        $params = [$id];

        if ($societyId !== null && $societyId > 0) {
            $sql .= " AND (society_id = ? OR is_parent_user = 1)";
            $params[] = $societyId;
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
