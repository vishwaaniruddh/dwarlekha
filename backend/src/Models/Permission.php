<?php
namespace App\Models;

use PDO;

class Permission extends BaseModel {
    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM permissions ORDER BY module ASC, id ASC");
        return $stmt->fetchAll();
    }

    public function getGroupedByModule(): array {
        $all = $this->getAll();
        $grouped = [];
        foreach ($all as $perm) {
            $module = $perm['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $perm;
        }
        return $grouped;
    }

    public function findByCode(string $code): ?array {
        $stmt = $this->db->prepare("SELECT * FROM permissions WHERE permission_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $perm = $stmt->fetch();
        return $perm ?: null;
    }
}
