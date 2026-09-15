<?php
namespace App\Config;

class TenantContext {
    private static int $societyId = 1;
    private static ?string $societyCode = 'EMR-01';

    public static function resolve(): int {
        $pdo = Database::getConnection();

        // 0. Security Isolation: If user is authenticated and is a tenant user (non-SAR admin),
        // enforce their assigned society strictly to prevent cross-tenant parameter tampering
        $currUser = RbacGuard::getCurrentUser();
        $isSarAdmin = $currUser && (!empty($currUser['isParentUser']) || 
                      in_array($currUser['role']['code'] ?? '', ['super_admin', 'sar_platform_admin', 'sar_support'], true));

        if ($currUser && !$isSarAdmin) {
            $userSocId = (int)($currUser['societyId'] ?? ($currUser['society_id'] ?? 0));
            if ($userSocId > 0) {
                self::$societyId = $userSocId;
                self::$societyCode = $currUser['societyCode'] ?? ($currUser['society_code'] ?? null);
                return self::$societyId;
            }
        }

        // 1. Check HTTP Header (X-Society-ID or X-Tenant-ID)
        $headerCode = $_SERVER['HTTP_X_SOCIETY_ID'] ?? ($_SERVER['HTTP_X_TENANT_ID'] ?? null);

        // 2. Check Query String
        $queryCode = $_GET['society_id'] ?? ($_GET['society_code'] ?? null);

        $target = $headerCode ?: $queryCode;

        if ($target) {
            if (strtoupper($target) === 'GLOBAL' || strtoupper($target) === 'ALL' || $target === '0' || strtoupper($target) === 'SAR-HQ') {
                self::$societyId = 0;
                self::$societyCode = 'GLOBAL';
                return 0;
            }

            if (is_numeric($target)) {
                self::$societyId = (int)$target;
                $stmt = $pdo->prepare("SELECT society_code FROM societies WHERE id = ? LIMIT 1");
                $stmt->execute([self::$societyId]);
                self::$societyCode = $stmt->fetchColumn() ?: null;
            } else {
                $stmt = $pdo->prepare("SELECT id, society_code FROM societies WHERE society_code = ? OR name = ? LIMIT 1");
                $stmt->execute([$target, $target]);
                $soc = $stmt->fetch();
                if ($soc) {
                    self::$societyId = (int)$soc['id'];
                    self::$societyCode = $soc['society_code'];
                }
            }
        }

        return self::$societyId;
    }

    public static function getSocietyId(): int {
        return self::$societyId;
    }

    public static function setSocietyId(int $id, ?string $code = null): void {
        self::$societyId = $id;
        self::$societyCode = $code;
    }

    public static function getSocietyCode(): ?string {
        return self::$societyCode;
    }
}
