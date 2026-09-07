<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Vendor extends BaseModel {
    protected string $table = 'vendors';

    public function getAllBySociety(int $societyId): array {
        $db = Database::getConnection();
        $sql = "SELECT v.*, s.name as society_name, s.society_code 
            FROM {$this->table} v 
            JOIN societies s ON v.society_id = s.id 
            WHERE v.is_deleted = 0";
        if ($societyId > 0) {
            $sql .= " AND v.society_id = ? ORDER BY s.id ASC, v.company_name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$societyId]);
        } else {
            $stmt = $db->query($sql . " ORDER BY s.id ASC, v.company_name ASC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("INSERT INTO {$this->table} 
                (society_id, company_name, contact_person, phone, email, gstin, pan, bank_name, account_number, ifsc_code, category) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['society_id'],
                $data['company_name'],
                $data['contact_person'] ?? null,
                $data['phone'] ?? null,
                $data['email'] ?? null,
                $data['gstin'] ?? null,
                $data['pan'] ?? null,
                $data['bank_name'] ?? null,
                $data['account_number'] ?? null,
                $data['ifsc_code'] ?? null,
                $data['category'] ?? 'General Services'
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
