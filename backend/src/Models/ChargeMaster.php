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
}
