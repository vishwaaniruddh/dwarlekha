<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class JournalEntry extends BaseModel {
    protected string $table = 'journal_entries';

    public function getAllBySociety(int $societyId, ?string $from = null, ?string $to = null): array {
        $db = Database::getConnection();
        $sql = "SELECT je.*, u.full_name as created_by_name 
            FROM {$this->table} je 
            LEFT JOIN users u ON je.created_by_user_id = u.id";

        if ($societyId > 0) {
            $sql .= " WHERE je.society_id = ? AND je.is_deleted = 0";
            $params = [$societyId];
        } else {
            $sql .= " WHERE je.is_deleted = 0";
            $params = [];
        }

        if ($from) {
            $sql .= " AND je.entry_date >= ?";
            $params[] = $from;
        }
        if ($to) {
            $sql .= " AND je.entry_date <= ?";
            $params[] = $to;
        }
        $sql .= " ORDER BY je.entry_date DESC, je.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch line items for each journal entry
        $coaModel = new ChartOfAccount();
        foreach ($entries as &$entry) {
            $stmtItems = $db->prepare("SELECT ji.*, coa.account_code, coa.account_name, coa.account_type 
                FROM journal_items ji 
                JOIN chart_of_accounts coa ON ji.account_id = coa.id 
                WHERE ji.journal_entry_id = ?");
            $stmtItems->execute([$entry['id']]);
            $entry['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }
        return $entries;
    }

    /**
     * Strict Double-Entry Journal Creation
     * Rule: Sum of Debits MUST equal Sum of Credits (Golden Rule)
     */
    public function createWithItems(array $entryData, array $items): int {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            // 1. Validate Debit = Credit
            $totalDebit = 0.00;
            $totalCredit = 0.00;
            foreach ($items as $item) {
                $totalDebit += (float)($item['debit_amount'] ?? 0.00);
                $totalCredit += (float)($item['credit_amount'] ?? 0.00);
            }

            if (abs($totalDebit - $totalCredit) > 0.01) {
                throw new \InvalidArgumentException("Double-Entry Violation: Total Debits (₹$totalDebit) must equal Total Credits (₹$totalCredit).");
            }

            if (empty($entryData['entry_number'])) {
                $entryData['entry_number'] = 'JE-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
            }

            // 2. Insert Journal Header
            $stmt = $db->prepare("INSERT INTO {$this->table} 
                (society_id, entry_number, entry_date, narration, source_module, source_reference_id, created_by_user_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $entryData['society_id'],
                $entryData['entry_number'],
                $entryData['entry_date'] ?? date('Y-m-d'),
                $entryData['narration'],
                $entryData['source_module'] ?? 'Manual',
                $entryData['source_reference_id'] ?? null,
                $entryData['created_by_user_id'] ?? null
            ]);
            $journalId = (int)$db->lastInsertId();

            // 3. Insert Line Items & Update Account Balances
            $coaModel = new ChartOfAccount();
            $stmtItem = $db->prepare("INSERT INTO journal_items (journal_entry_id, account_id, debit_amount, credit_amount) VALUES (?, ?, ?, ?)");
            foreach ($items as $item) {
                $accId = (int)$item['account_id'];
                $deb = (float)($item['debit_amount'] ?? 0.00);
                $cred = (float)($item['credit_amount'] ?? 0.00);

                $stmtItem->execute([$journalId, $accId, $deb, $cred]);
                $coaModel->updateBalance($accId, $deb, $cred);
            }

            if ($manageTx) $db->commit();
            return $journalId;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
