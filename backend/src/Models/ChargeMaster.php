<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class ChargeMaster extends BaseModel {
    protected string $table = 'charge_masters';

    public function getAllBySociety(int $societyId): array {
        $db = Database::getConnection();
        $sql = "SELECT cm.*, coa.account_name as gl_account_name, coa.account_code as gl_account_code, s.society_code, s.name as society_name 
            FROM {$this->table} cm 
            JOIN societies s ON cm.society_id = s.id 
            LEFT JOIN chart_of_accounts coa ON cm.gl_account_id = coa.id";
        if ($societyId > 0) {
            $sql .= " WHERE cm.society_id = ? AND cm.is_deleted = 0";
            $params = [$societyId];
        } else {
            $sql .= " WHERE cm.is_deleted = 0";
            $params = [];
        }
        $sql .= " ORDER BY s.id ASC, cm.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT cm.*, coa.account_name as gl_account_name, coa.account_code as gl_account_code, s.society_code, s.name as society_name 
            FROM {$this->table} cm 
            JOIN societies s ON cm.society_id = s.id 
            LEFT JOIN chart_of_accounts coa ON cm.gl_account_id = coa.id
            WHERE cm.id = ? AND cm.is_deleted = 0 
            LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("INSERT INTO {$this->table} 
                (society_id, charge_name, calculation_type, rate_amount, gl_account_id, gst_percentage, is_recurring, billing_cycle, description) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['society_id'],
                $data['charge_name'],
                $data['calculation_type'] ?? 'Fixed',
                $data['rate_amount'] ?? 0.00,
                $data['gl_account_id'] ?? null,
                $data['gst_percentage'] ?? 0.00,
                isset($data['is_recurring']) ? ($data['is_recurring'] ? 1 : 0) : 1,
                $data['billing_cycle'] ?? 'Monthly',
                $data['description'] ?? null
            ]);
            $id = (int)$db->lastInsertId();
            if ($manageTx) $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $fields = [];
            $params = [];

            if (isset($data['society_id'])) {
                $fields[] = "society_id = ?";
                $params[] = (int)$data['society_id'];
            }
            if (isset($data['charge_name'])) {
                $fields[] = "charge_name = ?";
                $params[] = trim($data['charge_name']);
            }
            if (isset($data['calculation_type'])) {
                $fields[] = "calculation_type = ?";
                $params[] = $data['calculation_type'];
            }
            if (isset($data['rate_amount'])) {
                $fields[] = "rate_amount = ?";
                $params[] = (float)$data['rate_amount'];
            }
            if (array_key_exists('gl_account_id', $data)) {
                $fields[] = "gl_account_id = ?";
                $params[] = $data['gl_account_id'] ? (int)$data['gl_account_id'] : null;
            }
            if (isset($data['gst_percentage'])) {
                $fields[] = "gst_percentage = ?";
                $params[] = (float)$data['gst_percentage'];
            }
            if (isset($data['is_recurring'])) {
                $fields[] = "is_recurring = ?";
                $params[] = $data['is_recurring'] ? 1 : 0;
            }
            if (isset($data['billing_cycle'])) {
                $fields[] = "billing_cycle = ?";
                $params[] = $data['billing_cycle'];
            }
            if (array_key_exists('description', $data)) {
                $fields[] = "description = ?";
                $params[] = $data['description'];
            }

            if (empty($fields)) return false;

            $params[] = $id;
            $sql = "UPDATE {$this->table} SET " . implode(", ", $fields) . " WHERE id = ? AND is_deleted = 0";
            $stmt = $db->prepare($sql);
            $res = $stmt->execute($params);

            if ($manageTx) $db->commit();
            return $res;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("UPDATE {$this->table} SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
            $res = $stmt->execute([$id]);
            if ($manageTx) $db->commit();
            return $res;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
