<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Expense extends BaseModel {
    protected string $table = 'expenses';

    public function getAllBySociety(int $societyId, array $filters = []): array {
        $db = Database::getConnection();
        $sql = "SELECT e.*, v.company_name as vendor_name, v.category as vendor_category, 
            coa.account_name as expense_account_name, coa.account_code as expense_account_code,
            app.full_name as approved_by_name 
            FROM {$this->table} e 
            LEFT JOIN vendors v ON e.vendor_id = v.id 
            LEFT JOIN chart_of_accounts coa ON e.expense_account_id = coa.id 
            LEFT JOIN users app ON e.approved_by_user_id = app.id";

        if ($societyId > 0) {
            $sql .= " WHERE e.society_id = ? AND e.is_deleted = 0";
            $params = [$societyId];
        } else {
            $sql .= " WHERE e.is_deleted = 0";
            $params = [];
        }

        if (!empty($filters['approval_status']) && $filters['approval_status'] !== 'ALL') {
            $sql .= " AND e.approval_status = ?";
            $params[] = $filters['approval_status'];
        }
        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'ALL') {
            $sql .= " AND e.payment_status = ?";
            $params[] = $filters['payment_status'];
        }
        if (!empty($filters['from'])) {
            $sql .= " AND e.expense_date >= ?";
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= " AND e.expense_date <= ?";
            $params[] = $filters['to'];
        }

        $sql .= " ORDER BY e.expense_date DESC, e.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createExpense(array $data): array {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $voucherNumber = 'EXP-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
            $amount = (float)$data['amount'];
            $taxAmount = (float)($data['tax_amount'] ?? 0.00);
            $totalAmount = $amount + $taxAmount;

            $stmt = $db->prepare("INSERT INTO {$this->table} 
                (society_id, vendor_id, expense_account_id, voucher_number, invoice_number, amount, tax_amount, total_amount, expense_date, payment_status, approval_status, notes, receipt_attachment_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Unpaid', 'Pending', ?, ?)");
            $stmt->execute([
                $data['society_id'],
                $data['vendor_id'] ?? null,
                $data['expense_account_id'] ?? null,
                $voucherNumber,
                $data['invoice_number'] ?? null,
                $amount,
                $taxAmount,
                $totalAmount,
                $data['expense_date'] ?? date('Y-m-d'),
                $data['notes'] ?? null,
                $data['receipt_attachment_url'] ?? null
            ]);
            $expenseId = (int)$db->lastInsertId();

            if ($manageTx) $db->commit();
            return [
                'id' => $expenseId,
                'voucher_number' => $voucherNumber,
                'total_amount' => $totalAmount,
                'approval_status' => 'Pending'
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function approveExpense(int $expenseId, int $userId): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table} WHERE id = ? AND is_deleted = 0 LIMIT 1");
            $stmt->execute([$expenseId]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp) throw new \RuntimeException("Expense voucher not found.");

            $stmtUp = $db->prepare("UPDATE {$this->table} SET approval_status = 'Approved', approved_by_user_id = ? WHERE id = ?");
            $stmtUp->execute([$userId, $expenseId]);

            // Post Double-Entry Journal: Debit Expense Account, Credit Accounts Payable
            $societyId = (int)$exp['society_id'];
            $coaModel = new ChartOfAccount();
            $apAccount = $coaModel->findByCode($societyId, '2010'); // Accounts Payable
            $expenseAccount = $exp['expense_account_id'] ? ['id' => $exp['expense_account_id']] : $coaModel->findByCode($societyId, '5010'); // Repair & Maintenance

            if ($apAccount && $expenseAccount) {
                $jeModel = new JournalEntry();
                $jeModel->createWithItems([
                    'society_id' => $societyId,
                    'entry_date' => date('Y-m-d'),
                    'narration' => "Approved Expense Voucher #{$exp['voucher_number']} (Invoice #{$exp['invoice_number']})",
                    'source_module' => 'Expense',
                    'created_by_user_id' => $userId
                ], [
                    [
                        'account_id' => $expenseAccount['id'],
                        'debit_amount' => (float)$exp['total_amount'],
                        'credit_amount' => 0.00
                    ],
                    [
                        'account_id' => $apAccount['id'],
                        'debit_amount' => 0.00,
                        'credit_amount' => (float)$exp['total_amount']
                    ]
                ]);
            }

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function payExpense(int $expenseId, string $paymentMode = 'Bank_Transfer', ?int $userId = null): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table} WHERE id = ? AND approval_status = 'Approved' AND is_deleted = 0 LIMIT 1");
            $stmt->execute([$expenseId]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp) throw new \RuntimeException("Approved expense voucher not found.");

            $stmtUp = $db->prepare("UPDATE {$this->table} SET payment_status = 'Paid' WHERE id = ?");
            $stmtUp->execute([$expenseId]);

            // Post Double-Entry Journal: Debit Accounts Payable, Credit Bank/Cash
            $societyId = (int)$exp['society_id'];
            $coaModel = new ChartOfAccount();
            $apAccount = $coaModel->findByCode($societyId, '2010'); // Accounts Payable
            $bankAccount = ($paymentMode === 'Cash')
                ? $coaModel->findByCode($societyId, '1030') // Cash
                : $coaModel->findByCode($societyId, '1010'); // Bank - HDFC

            if ($apAccount && $bankAccount) {
                $jeModel = new JournalEntry();
                $jeModel->createWithItems([
                    'society_id' => $societyId,
                    'entry_date' => date('Y-m-d'),
                    'narration' => "Vendor Payment for Voucher #{$exp['voucher_number']} via {$paymentMode}",
                    'source_module' => 'Payment',
                    'created_by_user_id' => $userId
                ], [
                    [
                        'account_id' => $apAccount['id'],
                        'debit_amount' => (float)$exp['total_amount'],
                        'credit_amount' => 0.00
                    ],
                    [
                        'account_id' => $bankAccount['id'],
                        'debit_amount' => 0.00,
                        'credit_amount' => (float)$exp['total_amount']
                    ]
                ]);
            }

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
