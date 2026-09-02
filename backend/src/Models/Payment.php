<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Payment extends BaseModel {
    protected string $table = 'payments';

    public function getAllBySociety(int $societyId, array $filters = []): array {
        $db = Database::getConnection();
        $sql = "SELECT p.*, u.unit_code, b.bill_number, 
            COALESCE(ru.full_name, u.owner_name, 'Resident') as resident_name, 
            rec.full_name as recorded_by_name 
            FROM {$this->table} p 
            JOIN units u ON p.unit_id = u.id 
            LEFT JOIN bills b ON p.bill_id = b.id 
            LEFT JOIN unit_occupancies uo ON u.id = uo.unit_id AND uo.is_primary = 1 
            LEFT JOIN residents r ON uo.resident_id = r.id 
            LEFT JOIN users ru ON r.user_id = ru.id 
            LEFT JOIN users rec ON p.recorded_by_user_id = rec.id";

        if ($societyId > 0) {
            $sql .= " WHERE p.society_id = ? AND p.is_deleted = 0";
            $params = [$societyId];
        } else {
            $sql .= " WHERE p.is_deleted = 0";
            $params = [];
        }

        if (!empty($filters['unit_id'])) {
            $sql .= " AND p.unit_id = ?";
            $params[] = $filters['unit_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'ALL') {
            $sql .= " AND p.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['payment_mode']) && $filters['payment_mode'] !== 'ALL') {
            $sql .= " AND p.payment_mode = ?";
            $params[] = $filters['payment_mode'];
        }
        if (!empty($filters['from'])) {
            $sql .= " AND DATE(p.payment_date) >= ?";
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= " AND DATE(p.payment_date) <= ?";
            $params[] = $filters['to'];
        }

        $sql .= " ORDER BY p.payment_date DESC, p.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Process & Record Payment (Online or Offline)
     * Updates Bill paid_amount & status + Posts Double-Entry Journal Entry
     */
    public function recordPayment(array $data): array {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $billId = !empty($data['bill_id']) ? (int)$data['bill_id'] : null;
            if ($billId && (empty($data['unit_id']) || empty($data['society_id']))) {
                $bStmt = $db->prepare("SELECT unit_id, society_id FROM bills WHERE id = ?");
                $bStmt->execute([$billId]);
                $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
                if ($bRow) {
                    if (empty($data['unit_id'])) $data['unit_id'] = $bRow['unit_id'];
                    if (empty($data['society_id'])) $data['society_id'] = $bRow['society_id'];
                }
            }

            $societyId = (int)($data['society_id'] ?? 1);
            $unitId = (int)($data['unit_id'] ?? 1);
            $amount = (float)($data['amount'] ?? 0);
            $paymentMode = $data['payment_mode'] ?? 'UPI';
            $status = $data['status'] ?? ($paymentMode === 'Cheque' ? 'Pending' : 'Success');

            $receiptNumber = 'REC-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));

            // 1. Insert Payment Record
            $stmt = $db->prepare("INSERT INTO {$this->table} 
                (society_id, unit_id, bill_id, receipt_number, payment_mode, gateway_transaction_id, cheque_number, cheque_date, bank_name, amount, payment_date, status, recorded_by_user_id, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $societyId,
                $unitId,
                $billId,
                $receiptNumber,
                $paymentMode,
                $data['gateway_transaction_id'] ?? null,
                $data['cheque_number'] ?? null,
                $data['cheque_date'] ?? null,
                $data['bank_name'] ?? null,
                $amount,
                $data['payment_date'] ?? date('Y-m-d H:i:s'),
                $status,
                $data['recorded_by_user_id'] ?? null,
                $data['notes'] ?? null
            ]);
            $paymentId = (int)$db->lastInsertId();

            // 2. If status is Success, update Bill balances & status + create Journal Entry
            if ($status === 'Success') {
                $this->applySuccessfulPaymentEffects($db, $societyId, $unitId, $billId, $amount, $paymentMode, $receiptNumber, $data['recorded_by_user_id'] ?? null);
            }

            if ($manageTx) $db->commit();
            return [
                'id' => $paymentId,
                'receipt_number' => $receiptNumber,
                'status' => $status,
                'amount' => $amount
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Clear Pending Cheque Payment
     */
    public function clearCheque(int $paymentId, string $outcome = 'Success'): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            $stmt = $db->prepare("SELECT * FROM {$this->table} WHERE id = ? AND status = 'Pending' LIMIT 1");
            $stmt->execute([$paymentId]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pay) throw new \RuntimeException("Pending cheque payment record not found.");

            $newStatus = ($outcome === 'Success') ? 'Success' : 'Bounced';
            $stmtUpdate = $db->prepare("UPDATE {$this->table} SET status = ? WHERE id = ?");
            $stmtUpdate->execute([$newStatus, $paymentId]);

            if ($newStatus === 'Success') {
                $this->applySuccessfulPaymentEffects(
                    $db,
                    (int)$pay['society_id'],
                    (int)$pay['unit_id'],
                    $pay['bill_id'] ? (int)$pay['bill_id'] : null,
                    (float)$pay['amount'],
                    'Cheque',
                    $pay['receipt_number'],
                    $pay['recorded_by_user_id']
                );
            }

            if ($manageTx) $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function applySuccessfulPaymentEffects(PDO $db, int $societyId, int $unitId, ?int $billId, float $amount, string $paymentMode, string $receiptNumber, ?int $userId): void {
        // Update specific bill or oldest unpaid bills
        if ($billId) {
            $stmtBill = $db->prepare("SELECT total_amount, paid_amount, outstanding_amount FROM bills WHERE id = ? LIMIT 1");
            $stmtBill->execute([$billId]);
            $bill = $stmtBill->fetch(PDO::FETCH_ASSOC);

            if ($bill) {
                $newPaid = (float)$bill['paid_amount'] + $amount;
                $newOutstanding = max(0.00, (float)$bill['total_amount'] - $newPaid);
                $newStatus = ($newOutstanding <= 0.01) ? 'Paid' : 'Partially_Paid';

                $db->prepare("UPDATE bills SET paid_amount = ?, outstanding_amount = ?, status = ? WHERE id = ?")
                    ->execute([$newPaid, $newOutstanding, $newStatus, $billId]);
            }
        }

        // Post Double-Entry Journal (Debit: Bank/Cash, Credit: Accounts Receivable)
        $coaModel = new ChartOfAccount();
        $bankAccount = ($paymentMode === 'Cash') 
            ? $coaModel->findByCode($societyId, '1030') // Cash in Hand
            : $coaModel->findByCode($societyId, '1010'); // Bank - HDFC
        $arAccount = $coaModel->findByCode($societyId, '1040'); // Accounts Receivable

        if ($bankAccount && $arAccount) {
            $jeModel = new JournalEntry();
            $jeModel->createWithItems([
                'society_id' => $societyId,
                'entry_date' => date('Y-m-d'),
                'narration' => "Payment received via {$paymentMode} (Receipt #{$receiptNumber})",
                'source_module' => 'Payment',
                'created_by_user_id' => $userId
            ], [
                [
                    'account_id' => $bankAccount['id'],
                    'debit_amount' => $amount,
                    'credit_amount' => 0.00
                ],
                [
                    'account_id' => $arAccount['id'],
                    'debit_amount' => 0.00,
                    'credit_amount' => $amount
                ]
            ]);
        }

        // Dispatch Payment Received Notifications (To Admin & Resident)
        try {
            $unitStmt = $db->prepare("SELECT u.unit_code, u.owner_name FROM units u WHERE u.id = ? LIMIT 1");
            $unitStmt->execute([$unitId]);
            $uInfo = $unitStmt->fetch(PDO::FETCH_ASSOC);

            $notifModel = new \App\Models\Notification();
            $notifModel->notifyPaymentReceived($societyId, [
                'unit_id' => $unitId,
                'unit_code' => $uInfo['unit_code'] ?? 'Unit',
                'resident_name' => $uInfo['owner_name'] ?? 'Resident',
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'receipt_number' => $receiptNumber,
                'bill_id' => $billId,
                'user_id' => $userId
            ]);
        } catch (\Throwable $ne) {
            // Non-blocking notification dispatch
        }
    }
}
