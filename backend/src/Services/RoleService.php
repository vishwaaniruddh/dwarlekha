<?php
namespace App\Services;

use App\Models\Role;
use App\Models\Permission;
use App\Config\TenantContext;
use App\Config\Database;
use Exception;
use InvalidArgumentException;

class RoleService {
    private Role $roleModel;
    private Permission $permissionModel;

    public function __construct(?Role $roleModel = null, ?Permission $permissionModel = null) {
        $this->roleModel = $roleModel ?: new Role();
        $this->permissionModel = $permissionModel ?: new Permission();
    }

    public function getRoles(): array {
        $societyId = TenantContext::getSocietyId();
        $currentUser = \App\Config\RbacGuard::getCurrentUser();
        $isParentUser = !empty($currentUser['isParentUser']) || in_array($currentUser['role']['code'] ?? '', ['sar_platform_admin', 'sar_support']);

        $roles = $this->roleModel->getAll($societyId);

        // SAR platform HQ roles (sar_platform_admin, sar_support) belong strictly to SAR Parent HQ
        // They must NOT be visible to client society admins or tenant users
        if (!$isParentUser || ($societyId !== null && $societyId > 0)) {
            $roles = array_values(array_filter($roles, function($r) {
                return !str_starts_with($r['role_code'] ?? '', 'sar_');
            }));
        }

        return $roles;
    }

    public function getRoleById(int $id): ?array {
        $societyId = TenantContext::getSocietyId();
        return $this->roleModel->findById($id, $societyId);
    }

    public function getPermissionsCatalog(): array {
        return CacheService::remember('permissions_catalog', 86400, function() {
            return [
                'all' => $this->permissionModel->getAll(),
                'grouped' => $this->permissionModel->getGroupedByModule()
            ];
        });
    }

    public function getRolePermissionMatrix(): array {
        $societyId = TenantContext::getSocietyId();
        $cacheKey = 'role_perm_matrix_' . ($societyId ?? 'all');

        return CacheService::remember($cacheKey, 86400, function() {
            $roles = $this->getRoles();
            $permissions = $this->permissionModel->getAll();
            $groupedPerms = $this->permissionModel->getGroupedByModule();

            $matrix = [];
            foreach ($roles as $role) {
                $permCodes = array_column($role['permissions'], 'permission_code');
                $matrix[$role['role_code']] = [
                    'roleId' => (int)$role['id'],
                    'roleCode' => $role['role_code'],
                'roleName' => $role['name'],
                'badgeColor' => $role['badge_color'],
                'description' => $role['description'],
                'isSystem' => (bool)$role['is_system'],
                'permissionCodes' => $permCodes,
                'permissionCount' => count($permCodes)
            ];
        }

        return [
            'roles' => $matrix,
            'permissions' => $permissions,
            'groupedPermissions' => $groupedPerms
        ];
        });
    }

    public function createRole(array $input): array {
        if (empty($input['roleCode']) || empty($input['name'])) {
            throw new InvalidArgumentException("Role code and name are required.");
        }

        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $roleId = $this->roleModel->create([
                'role_code' => strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $input['roleCode']))),
                'name' => trim($input['name']),
                'description' => $input['description'] ?? null,
                'badge_color' => $input['badgeColor'] ?? 'purple',
                'is_system' => 0
            ], $societyId);

            if (!empty($input['permissionIds'])) {
                $this->roleModel->syncPermissions($roleId, $input['permissionIds']);
            }

            $result = $this->getRoleById($roleId);

            if ($manageTx) {
                $db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function updateRolePermissions(int $roleId, array $permissionIds): array {
        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $role = $this->roleModel->findById($roleId, $societyId);
            if (!$role) {
                throw new Exception("Role not found.");
            }

            $this->roleModel->syncPermissions($roleId, $permissionIds);
            $result = $this->getRoleById($roleId);

            if ($manageTx) {
                $db->commit();
            }

            CacheService::flush('role_');
            CacheService::flush('permissions_');

            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
