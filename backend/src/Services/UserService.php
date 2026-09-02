<?php
namespace App\Services;

use App\Models\User;
use App\Models\Role;
use App\Config\TenantContext;
use App\Config\Database;
use Exception;
use InvalidArgumentException;

class UserService {
    private User $userModel;
    private Role $roleModel;

    public function __construct(?User $userModel = null, ?Role $roleModel = null) {
        $this->userModel = $userModel ?: new User();
        $this->roleModel = $roleModel ?: new Role();
    }

    public function getUsers(array $filters = []): array {
        $currentUser = \App\Config\RbacGuard::getCurrentUser();
        $isParentUser = !empty($currentUser['isParentUser']);

        $societyId = TenantContext::getSocietyId();

        // If requester is a Parent User, or explicitly requesting all users, return all child societies + parent users
        if ($isParentUser || !empty($filters['all_users']) || (isset($filters['include_all']) && $filters['include_all'])) {
            $users = $this->userModel->getAll(null, $filters);
        } elseif ($currentUser !== null && !$isParentUser) {
            // When a client society user is logged in, isolate strictly to their society
            $filters['include_parent'] = false;
            $users = $this->userModel->getAll($societyId, $filters);
        } else {
            // Default unauthenticated / test context: return society users & parent staff
            $users = $this->userModel->getAll($societyId, $filters);
        }
        
        $formatted = [];
        foreach ($users as $u) {
            $perms = $this->roleModel->getPermissionCodesForRole((int)$u['role_id']);
            $formatted[] = [
                'id' => (int)$u['id'],
                'userCode' => $u['user_code'],
                'societyId' => $u['society_id'] ? (int)$u['society_id'] : null,
                'societyName' => $u['society_name'] ?? ($u['is_parent_user'] ? 'SAR Platform HQ (Global / All Societies)' : 'Tenant Society'),
                'societyCode' => $u['is_parent_user'] ? 'GLOBAL' : ($u['society_code'] ?? 'EMR-01'),
                'isParentUser' => (bool)$u['is_parent_user'],
                'fullName' => $u['full_name'],
                'email' => $u['email'],
                'phone' => $u['phone'],
                'unitCode' => $u['unit_code'],
                'status' => $u['status'],
                'avatarUrl' => $u['avatar_url'],
                'lastLoginAt' => $u['last_login_at'],
                'createdAt' => $u['created_at'],
                'role' => [
                    'id' => (int)$u['role_id'],
                    'code' => $u['role_code'],
                    'name' => $u['role_name'],
                    'badgeColor' => $u['role_badge_color'] ?? ($u['is_parent_user'] ? 'purple' : 'blue')
                ],
                'permissions' => $perms
            ];
        }

        return $formatted;
    }

    public function getUserById(int $id): ?array {
        $societyId = TenantContext::getSocietyId();
        $user = $this->userModel->findById($id, $societyId);
        if (!$user) {
            $user = $this->userModel->findById($id, null);
        }
        if (!$user) {
            return null;
        }

        $perms = $this->roleModel->getPermissionCodesForRole((int)$user['role_id']);
        return [
            'id' => (int)$user['id'],
            'userCode' => $user['user_code'],
            'societyId' => $user['society_id'] ? (int)$user['society_id'] : null,
            'societyName' => $user['society_name'] ?? ($user['is_parent_user'] ? 'SAR Platform HQ (Global / All Societies)' : 'Tenant Society'),
            'societyCode' => $user['is_parent_user'] ? 'GLOBAL' : ($user['society_code'] ?? 'EMR-01'),
            'isParentUser' => (bool)$user['is_parent_user'],
            'fullName' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'unitCode' => $user['unit_code'],
            'status' => $user['status'],
            'avatarUrl' => $user['avatar_url'],
            'lastLoginAt' => $user['last_login_at'],
            'createdAt' => $user['created_at'],
            'role' => [
                'id' => (int)$user['role_id'],
                'code' => $user['role_code'],
                'name' => $user['role_name'],
                'badgeColor' => $user['role_badge_color'] ?? ($user['is_parent_user'] ? 'purple' : 'blue')
            ],
            'permissions' => $perms
        ];
    }

    public function createUser(array $input): array {
        if (empty($input['fullName']) || empty($input['email'])) {
            throw new InvalidArgumentException("Full name and email are required.");
        }

        $isParentUser = !empty($input['isParent']) || !empty($input['is_parent_user']) || ($input['accountLevel'] ?? '') === 'parent';
        $targetSocietyId = $isParentUser ? null : (int)($input['societyId'] ?? ($input['society_id'] ?? TenantContext::getSocietyId()));
        $email = trim(strtolower($input['email']));

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            // Check if email already exists
            $existing = $this->userModel->findByEmail($email, $targetSocietyId);
            if ($existing) {
                throw new Exception("User with email {$email} already exists in this society.");
            }

            // Resolve role
            $roleId = null;
            if (!empty($input['roleId'])) {
                $roleId = (int)$input['roleId'];
            } elseif (!empty($input['roleCode'])) {
                $role = $this->roleModel->findByCode($input['roleCode'], $targetSocietyId);
                if ($role) {
                    $roleId = (int)$role['id'];
                }
            }

            if (!$roleId) {
                $defaultCode = $isParentUser ? 'sar_platform_admin' : 'resident';
                $defaultRole = $this->roleModel->findByCode($defaultCode, $targetSocietyId);
                $roleId = $defaultRole ? (int)$defaultRole['id'] : 1;
            }

            $rawPassword = !empty($input['password']) ? $input['password'] : 'Welcome@123';
            $passwordHash = password_hash($rawPassword, PASSWORD_BCRYPT);
            $prefix = $isParentUser ? 'SAR-' : 'USR-';
            $userCode = $prefix . mt_rand(10000, 99999);
            while ($this->userModel->findByCode($userCode)) {
                $userCode = $prefix . mt_rand(10000, 99999);
            }

            $userId = $this->userModel->create([
                'user_code' => $userCode,
                'society_id' => $targetSocietyId,
                'is_parent_user' => $isParentUser ? 1 : 0,
                'full_name' => trim($input['fullName']),
                'email' => $email,
                'password_hash' => $passwordHash,
                'role_id' => $roleId,
                'phone' => $input['phone'] ?? null,
                'unit_code' => $isParentUser ? null : ($input['unitCode'] ?? null),
                'resident_id' => $input['residentId'] ?? null,
                'status' => $input['status'] ?? 'Active',
                'avatar_url' => $input['avatarUrl'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'
            ], $targetSocietyId);

            $result = $this->getUserById($userId);

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

    public function updateUser(int $id, array $input): array {
        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $user = $this->userModel->findById($id, $societyId);
            if (!$user) {
                throw new Exception("User not found.");
            }

            $updateData = [];
            if (isset($input['fullName'])) $updateData['full_name'] = trim($input['fullName']);
            if (isset($input['email'])) $updateData['email'] = trim(strtolower($input['email']));
            if (isset($input['phone'])) $updateData['phone'] = $input['phone'];
            if (isset($input['unitCode'])) $updateData['unit_code'] = $input['unitCode'];
            if (isset($input['status'])) $updateData['status'] = $input['status'];
            if (isset($input['avatarUrl'])) $updateData['avatar_url'] = $input['avatarUrl'];
            if (isset($input['isParent'])) $updateData['is_parent_user'] = $input['isParent'] ? 1 : 0;
            if (array_key_exists('societyId', $input)) $updateData['society_id'] = $input['societyId'] ? (int)$input['societyId'] : null;

            if (!empty($input['roleId'])) {
                $updateData['role_id'] = (int)$input['roleId'];
            } elseif (!empty($input['roleCode'])) {
                $role = $this->roleModel->findByCode($input['roleCode'], $societyId);
                if ($role) {
                    $updateData['role_id'] = (int)$role['id'];
                }
            }

            if (!empty($input['password'])) {
                $updateData['password_hash'] = password_hash($input['password'], PASSWORD_BCRYPT);
            }

            $this->userModel->update($id, $updateData, $societyId);
            $result = $this->getUserById($id);

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

    public function deleteUser(int $id): bool {
        $societyId = TenantContext::getSocietyId();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $result = $this->userModel->delete($id, $societyId);
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
}
