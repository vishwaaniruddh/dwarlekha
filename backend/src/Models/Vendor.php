<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Vendor extends BaseModel {
    protected string $table = 'vendors';

    public function getAllBySociety(int $societyId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM {$this->table} WHERE society_id = ? AND is_deleted = 0 ORDER BY company_name ASC");
        $stmt->execute([$societyId]);
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
