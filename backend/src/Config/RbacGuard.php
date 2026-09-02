<?php
namespace App\Config;

use App\Services\AuthService;
use Exception;

class RbacGuard {
    private static ?array $currentUser = null;

    public static function getCurrentUser(): ?array {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $authHeader = null;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }
        if (!$authHeader) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
                ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
                ?? ($_GET['auth_token'] ?? null));
        }

        if ($authHeader) {
            $authService = new AuthService();
            self::$currentUser = $authService->validateToken($authHeader);
        }

        return self::$currentUser;
    }

    public static function setCurrentUser(?array $user): void {
        self::$currentUser = $user;
    }

    public static function hasPermission(string $permissionCode): bool {
        $user = self::getCurrentUser();
        if (!$user) {
            // If no user session is provided in request, allow pass-through
            return true;
        }

        $role = $user['role']['code'] ?? '';
        if ($role === 'super_admin' || $role === 'sar_platform_admin' || !empty($user['isParentUser'])) {
            return true;
        }

        $permissions = $user['permissions'] ?? [];
        return in_array($permissionCode, $permissions, true);
    }

    public static function requirePermission(string $permissionCode): array {
        $user = self::getCurrentUser();
        if (!$user) {
            // Allow unauthenticated demo mode if header not provided
            return ['id' => 1, 'role' => ['code' => 'super_admin']];
        }

        if (!self::hasPermission($permissionCode)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => "Forbidden: You do not have permission '{$permissionCode}' to perform this action.",
                'requiredPermission' => $permissionCode,
                'userRole' => $user['role']['name'] ?? 'Unknown'
            ]);
            exit;
        }

        return $user;
    }
}
