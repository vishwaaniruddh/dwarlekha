<?php
namespace App\Models;

use PDO;

class Role extends BaseModel {
    public function getAll(?int $societyId = null): array {
        $societyId = $societyId ?: $this->getSocietyId();
        
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE society_id = ? OR is_system = 1 ORDER BY id ASC");
        $stmt->execute([$societyId]);
        $roles = $stmt->fetchAll();

        // Attach permissions to each role
        foreach ($roles as &$role) {
            $role['permissions'] = $this->getPermissionsForRole((int)$role['id']);
        }

        return $roles;
    }

    public function findById(int $id, ?int $societyId = null): ?array {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE id = ? AND (society_id = ? OR is_system = 1) LIMIT 1");
        $stmt->execute([$id, $societyId]);
        $role = $stmt->fetch();
        if ($role) {
            $role['permissions'] = $this->getPermissionsForRole($id);
            return $role;
        }
        return null;
    }

    public function findByCode(string $code, ?int $societyId = null): ?array {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE role_code = ? AND (society_id = ? OR is_system = 1) LIMIT 1");
        $stmt->execute([$code, $societyId]);
        $role = $stmt->fetch();
        if ($role) {
            $role['permissions'] = $this->getPermissionsForRole((int)$role['id']);
            return $role;
        }
        return null;
    }

    public function getPermissionsForRole(int $roleId): array {
        $stmt = $this->db->prepare("SELECT p.id, p.permission_code, p.module, p.action, p.description
                                    FROM permissions p
                                    JOIN role_permissions rp ON p.id = rp.permission_id
                                    WHERE rp.role_id = ?
                                    ORDER BY p.module, p.id");
        $stmt->execute([$roleId]);
        return $stmt->fetchAll();
    }

    public function getPermissionCodesForRole(int $roleId): array {
        $perms = $this->getPermissionsForRole($roleId);
        return array_column($perms, 'permission_code');
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("INSERT INTO roles (society_id, role_code, name, description, badge_color, is_system) 
                                    VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $societyId,
            $data['role_code'],
            $data['name'],
            $data['description'] ?? null,
            $data['badge_color'] ?? 'blue',
            $data['is_system'] ?? 0
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function syncPermissions(int $roleId, array $permissionIds): bool {
        $this->db->beginTransaction();
        try {
            // Delete existing
            $stmtDel = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmtDel->execute([$roleId]);

            // Insert new
            if (!empty($permissionIds)) {
                $stmtIns = $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissionIds as $permId) {
                    $stmtIns->execute([$roleId, $permId]);
                }
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
