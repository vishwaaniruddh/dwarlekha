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

    public static function isDemoMode(): bool {
        // Never allow unauthenticated super_admin in production
        if (getenv('APP_ENV') === 'production') {
            return false;
        }
        return getenv('APP_DEMO_MODE') === '1' || defined('APP_DEMO_MODE');
    }

    public static function hasPermission(string $permissionCode): bool {
        if (php_sapi_name() === 'cli') {
            return true;
        }

        $user = self::getCurrentUser();
        if (!$user) {
            return self::isDemoMode();
        }

        $role = $user['role']['code'] ?? '';
        if ($role === 'super_admin' || $role === 'sar_platform_admin' || !empty($user['isParentUser'])) {
            return true;
        }

        $permissions = $user['permissions'] ?? [];
        return in_array($permissionCode, $permissions, true);
    }

    public static function requirePermission(string $permissionCode): array {
        // Allow CLI execution (unit tests, migrations, seeders)
        if (php_sapi_name() === 'cli') {
            $user = self::getCurrentUser();
            return $user ?: ['id' => 1, 'name' => 'CLI Admin', 'role' => ['code' => 'super_admin', 'name' => 'CLI Super Admin']];
        }

        $user = self::getCurrentUser();
        if (!$user) {
            if (self::isDemoMode()) {
                return ['id' => 1, 'name' => 'Demo Admin', 'role' => ['code' => 'super_admin', 'name' => 'Demo Super Admin']];
            }

            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'error' => 'Unauthenticated. Valid Bearer authorization token required.',
                'code' => 'UNAUTHENTICATED'
            ]);
            exit;
        }

        if (!self::hasPermission($permissionCode)) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
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
