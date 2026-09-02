<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class ChartOfAccount extends BaseModel {
    protected string $table = 'chart_of_accounts';

    public function getAllBySociety(int $societyId): array {
        $db = Database::getConnection();
        if ($societyId > 0) {
            $stmt = $db->prepare("SELECT * FROM {$this->table} WHERE society_id = ? AND is_deleted = 0 ORDER BY account_code ASC");
            $stmt->execute([$societyId]);
        } else {
            $stmt = $db->query("SELECT * FROM {$this->table} WHERE is_deleted = 0 ORDER BY account_code ASC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByCode(int $societyId, string $code): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM {$this->table} WHERE society_id = ? AND account_code = ? AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$societyId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("INSERT INTO {$this->table} 
                (society_id, account_code, account_name, account_type, parent_account_id, is_system_account, is_active, balance) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['society_id'],
                $data['account_code'],
                $data['account_name'],
                $data['account_type'],
                $data['parent_account_id'] ?? null,
                !empty($data['is_system_account']) ? 1 : 0,
                isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
                $data['balance'] ?? 0.00
            ]);
            $id = (int)$db->lastInsertId();
            if ($manageTx) $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function updateBalance(int $accountId, float $debitDelta, float $creditDelta): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT account_type, balance FROM {$this->table} WHERE id = ? LIMIT 1");
        $stmt->execute([$accountId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) return;

        $type = $acc['account_type'];
        // For Assets & Expenses: Balance increases with Debit, decreases with Credit
        // For Liabilities, Equity, Income: Balance increases with Credit, decreases with Debit
        if (in_array($type, ['Asset', 'Expense'])) {
            $netChange = $debitDelta - $creditDelta;
        } else {
            $netChange = $creditDelta - $debitDelta;
        }

        $stmtUpdate = $db->prepare("UPDATE {$this->table} SET balance = balance + ? WHERE id = ?");
        $stmtUpdate->execute([$netChange, $accountId]);
    }
}
