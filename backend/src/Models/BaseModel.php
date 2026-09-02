<?php
namespace App\Models;

use App\Config\Database;
use App\Config\TenantContext;
use PDO;

abstract class BaseModel {
    protected PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    protected function getSocietyId(): int {
        return TenantContext::getSocietyId();
    }
}
