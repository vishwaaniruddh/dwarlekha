<?php
namespace App\Services;

use App\Models\User;
use App\Models\Role;
use App\Models\UserSession;
use App\Config\TenantContext;
use Exception;
use InvalidArgumentException;

class AuthService {
    private User $userModel;
    private Role $roleModel;
    private UserSession $sessionModel;

    public function __construct(
        ?User $userModel = null,
        ?Role $roleModel = null,
        ?UserSession $sessionModel = null
    ) {
        $this->userModel = $userModel ?: new User();
        $this->roleModel = $roleModel ?: new Role();
        $this->sessionModel = $sessionModel ?: new UserSession();
    }

    public function login(string $email, string $password): array {
        if (empty($email) || empty($password)) {
            throw new InvalidArgumentException("Email and password are required.");
        }

        // Global search by email (supporting SAR parent users and society users)
        $user = $this->userModel->findByEmail(trim($email));

        if (!$user) {
            throw new Exception("Invalid email or password.");
        }

        if ($user['status'] !== 'Active') {
            throw new Exception("Account is {$user['status']}. Please contact administration.");
        }

        if (!password_verify($password, $user['password_hash'])) {
            throw new Exception("Invalid email or password.");
        }

        // Generate session token (72 hours)
        $ttlHours = 72;
        $token = $this->sessionModel->createToken((int)$user['id'], $ttlHours);
        $this->userModel->updateLastLogin((int)$user['id']);

        // Fetch permissions
        $permissions = $this->roleModel->getPermissionCodesForRole((int)$user['role_id']);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttlHours * 3600,
            'user' => [
                'id' => (int)$user['id'],
                'userCode' => $user['user_code'],
                'societyId' => !empty($user['society_id']) ? (int)$user['society_id'] : null,
                'societyName' => $user['society_name'] ?? (!empty($user['is_parent_user']) ? 'SAR Platform HQ (Global / All Societies)' : 'Tenant Society'),
                'societyCode' => !empty($user['is_parent_user']) ? 'GLOBAL' : ($user['society_code'] ?? 'EMR-01'),
                'isParentUser' => !empty($user['is_parent_user']),
                'fullName' => $user['full_name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'unitCode' => $user['unit_code'],
                'status' => $user['status'],
                'avatarUrl' => $user['avatar_url'],
                'role' => [
                    'id' => (int)$user['role_id'],
                    'code' => $user['role_code'],
                    'name' => $user['role_name'],
                    'badgeColor' => $user['role_badge_color'] ?? (!empty($user['is_parent_user']) ? 'purple' : 'blue')
                ],
                'permissions' => $permissions
            ]
        ];
    }

    public function validateToken(?string $token): ?array {
        if (empty($token)) {
            return null;
        }

        // Clean "Bearer " prefix if present
        $cleanToken = trim(preg_replace('/^Bearer\s+/i', '', $token));
        if (empty($cleanToken)) {
            return null;
        }

        $user = $this->sessionModel->findUserByToken($cleanToken);
        if (!$user) {
            return null;
        }

        $permissions = $this->roleModel->getPermissionCodesForRole((int)$user['role_id']);

        return [
            'id' => (int)$user['id'],
            'userCode' => $user['user_code'],
            'societyId' => !empty($user['society_id']) ? (int)$user['society_id'] : null,
            'societyName' => $user['society_name'] ?? (!empty($user['is_parent_user']) ? 'SAR Platform HQ (Global / All Societies)' : 'Tenant Society'),
            'societyCode' => !empty($user['is_parent_user']) ? 'GLOBAL' : ($user['society_code'] ?? 'EMR-01'),
            'isParentUser' => !empty($user['is_parent_user']),
            'fullName' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'unitCode' => $user['unit_code'],
            'status' => $user['status'],
            'avatarUrl' => $user['avatar_url'],
            'role' => [
                'id' => (int)$user['role_id'],
                'code' => $user['role_code'],
                'name' => $user['role_name'],
                'badgeColor' => $user['role_badge_color'] ?? (!empty($user['is_parent_user']) ? 'purple' : 'blue')
            ],
            'permissions' => $permissions
        ];
    }

    public function logout(string $token): bool {
        $cleanToken = trim(preg_replace('/^Bearer\s+/i', '', $token));
        if (!empty($cleanToken)) {
            return $this->sessionModel->revokeToken($cleanToken);
        }
        return false;
    }

    public function getPersonas(): array {
        $societyId = TenantContext::getSocietyId();
        $users = $this->userModel->getAll($societyId, ['include_parent' => 1]);
        
        $personas = [];
        foreach ($users as $u) {
            $perms = $this->roleModel->getPermissionCodesForRole((int)$u['role_id']);
            $personas[] = [
                'id' => (int)$u['id'],
                'userCode' => $u['user_code'],
                'societyId' => $u['society_id'] ? (int)$u['society_id'] : null,
                'societyName' => $u['society_name'] ?? ($u['is_parent_user'] ? 'SAR Platform HQ (Global / All Societies)' : 'Tenant Society'),
                'societyCode' => $u['is_parent_user'] ? 'GLOBAL' : ($u['society_code'] ?? 'EMR-01'),
                'isParentUser' => (bool)$u['is_parent_user'],
                'fullName' => $u['full_name'],
                'email' => $u['email'],
                'roleCode' => $u['role_code'],
                'roleName' => $u['role_name'],
                'badgeColor' => $u['role_badge_color'] ?? ($u['is_parent_user'] ? 'purple' : 'blue'),
                'unitCode' => $u['unit_code'],
                'avatarUrl' => $u['avatar_url'],
                'permissions' => $perms
            ];
        }

        return $personas;
    }
}
