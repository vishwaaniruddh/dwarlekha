<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class ChartOfAccount extends BaseModel {
    protected string $table = 'chart_of_accounts';

    public function getAllBySociety(int $societyId): array {
        $db = Database::getConnection();
        $sql = "SELECT coa.*, s.name as society_name, s.society_code 
            FROM {$this->table} coa 
            JOIN societies s ON coa.society_id = s.id 
            WHERE coa.is_deleted = 0";
        if ($societyId > 0) {
            $sql .= " AND coa.society_id = ? ORDER BY s.id ASC, coa.account_code ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$societyId]);
        } else {
            $stmt = $db->query($sql . " ORDER BY s.id ASC, coa.account_code ASC");
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

    public function findById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT coa.*, s.name as society_name, s.society_code 
            FROM {$this->table} coa 
            JOIN societies s ON coa.society_id = s.id 
            WHERE coa.id = ? AND coa.is_deleted = 0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function update(int $id, array $data): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $account = $this->findById($id);
            if (!$account) {
                throw new \Exception("General ledger account not found.");
            }

            // Check if code is being changed
            if (!empty($data['account_code']) && $data['account_code'] !== $account['account_code']) {
                if (!empty($account['is_system_account'])) {
                    throw new \Exception("Cannot modify account code of Core System Account '{$account['account_code']}'.");
                }
                $existing = $this->findByCode((int)$account['society_id'], $data['account_code']);
                if ($existing && (int)$existing['id'] !== $id) {
                    throw new \Exception("Account code '{$data['account_code']}' already exists in this society.");
                }
            }

            $fields = [];
            $params = [];

            if (isset($data['account_name'])) {
                $fields[] = "account_name = ?";
                $params[] = trim($data['account_name']);
            }
            if (isset($data['account_type'])) {
                $fields[] = "account_type = ?";
                $params[] = $data['account_type'];
            }
            if (array_key_exists('parent_account_id', $data)) {
                $fields[] = "parent_account_id = ?";
                $params[] = !empty($data['parent_account_id']) ? (int)$data['parent_account_id'] : null;
            }
            if (isset($data['is_active'])) {
                $fields[] = "is_active = ?";
                $params[] = $data['is_active'] ? 1 : 0;
            }
            if (!empty($data['account_code']) && empty($account['is_system_account'])) {
                $fields[] = "account_code = ?";
                $params[] = trim($data['account_code']);
            }

            if (empty($fields)) {
                if ($manageTx) $db->commit();
                return true;
            }

            $params[] = $id;
            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function checkDependencies(int $id): array {
        $db = Database::getConnection();
        $account = $this->findById($id);
        if (!$account) {
            throw new \Exception("General ledger account not found.");
        }

        $reasons = [];
        $dependencies = [
            'is_system' => false,
            'journal_items_count' => 0,
            'charge_rules_count' => 0,
            'expenses_count' => 0,
            'child_accounts_count' => 0,
            'balance' => (float)($account['balance'] ?? 0)
        ];

        // 1. Core System Account check
        if (!empty($account['is_system_account'])) {
            $dependencies['is_system'] = true;
            $reasons[] = "Account '{$account['account_code']} - {$account['account_name']}' is a Core System Account required for double-entry financial integrity and cannot be deleted.";
        }

        // 2. Foreign key check: journal_items (transactions)
        $stmtJi = $db->prepare("SELECT COUNT(*) FROM journal_items WHERE account_id = ?");
        $stmtJi->execute([$id]);
        $jiCount = (int)$stmtJi->fetchColumn();
        $dependencies['journal_items_count'] = $jiCount;
        if ($jiCount > 0) {
            $reasons[] = "Foreign key violation: Account is referenced in {$jiCount} posted journal entry transaction lines.";
        }

        // 3. Foreign key check: charge_masters
        $stmtCm = $db->prepare("SELECT COUNT(*) FROM charge_masters WHERE gl_account_id = ? AND is_deleted = 0");
        $stmtCm->execute([$id]);
        $cmCount = (int)$stmtCm->fetchColumn();
        $dependencies['charge_rules_count'] = $cmCount;
        if ($cmCount > 0) {
            $reasons[] = "Foreign key violation: Account is mapped to {$cmCount} active Society Charge Rules.";
        }

        // 4. Foreign key check: expenses
        $stmtExp = $db->prepare("SELECT COUNT(*) FROM expenses WHERE expense_account_id = ? AND is_deleted = 0");
        $stmtExp->execute([$id]);
        $expCount = (int)$stmtExp->fetchColumn();
        $dependencies['expenses_count'] = $expCount;
        if ($expCount > 0) {
            $reasons[] = "Foreign key violation: Account is mapped to {$expCount} expense vouchers.";
        }

        // 5. Foreign key check: child accounts
        $stmtChild = $db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE parent_account_id = ? AND is_deleted = 0");
        $stmtChild->execute([$id]);
        $childCount = (int)$stmtChild->fetchColumn();
        $dependencies['child_accounts_count'] = $childCount;
        if ($childCount > 0) {
            $reasons[] = "Foreign key violation: Account has {$childCount} sub-ledger child accounts linked to it.";
        }

        // 6. Non-zero balance check
        if (abs($dependencies['balance']) > 0.001) {
            $formattedBal = number_format($dependencies['balance'], 2);
            $reasons[] = "Financial rule violation: Account has a non-zero balance of ₹{$formattedBal}. Account must be reconciled to ₹0.00 before archiving.";
        }

        return [
            'can_delete' => empty($reasons),
            'reasons' => $reasons,
            'dependencies' => $dependencies,
            'account' => $account
        ];
    }

    public function delete(int $id): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $check = $this->checkDependencies($id);
            if (!$check['can_delete']) {
                $errorMsg = implode(" ", $check['reasons']);
                throw new \Exception($errorMsg);
            }

            // Perform strict soft delete adhering to Directive 4
            $stmt = $db->prepare("UPDATE {$this->table} SET is_deleted = 1, deleted_at = NOW(), is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
