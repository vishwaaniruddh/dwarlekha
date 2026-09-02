<?php
namespace App\Models;

use PDO;

class Invoice extends BaseModel {
    public function getAll(?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        if ($societyId === 0) {
            $stmt = $this->db->query("SELECT * FROM invoices ORDER BY society_id ASC, id DESC");
        } else {
            $stmt = $this->db->prepare("SELECT * FROM invoices WHERE society_id = ? ORDER BY id DESC");
            $stmt->execute([$societyId]);
        }
        $rows = $stmt->fetchAll();

        return array_map(function($inv) {
            return [
                'id' => $inv['invoice_number'],
                'flatNumber' => $inv['flat_number'],
                'resident' => $inv['resident_name'],
                'month' => $inv['month_period'],
                'amount' => (float)$inv['amount'],
                'breakdown' => [
                    'base' => (float)$inv['base_maintenance'],
                    'water' => (float)$inv['water_charges'],
                    'sinkingFund' => (float)$inv['sinking_fund'],
                    'clubhouse' => (float)$inv['clubhouse_fee']
                ],
                'dueDate' => $inv['due_date'],
                'paidDate' => $inv['paid_date'],
                'status' => $inv['status'],
                'paymentMethod' => $inv['payment_method']
            ];
        }, $rows);
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("INSERT INTO invoices 
            (invoice_number, society_id, flat_number, resident_name, month_period, amount, base_maintenance, water_charges, sinking_fund, clubhouse_fee, due_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

        $stmt->execute([
            $data['invoice_number'],
            $societyId,
            $data['flat_number'],
            $data['resident_name'] ?? 'Resident',
            $data['month_period'] ?? date('F Y'),
            $data['amount'],
            $data['base_maintenance'] ?? round($data['amount'] * 0.7),
            $data['water_charges'] ?? 450,
            $data['sinking_fund'] ?? 500,
            $data['clubhouse_fee'] ?? 350,
            $data['due_date'] ?? '30th of Month'
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function markPaid(string $invoiceNumber, ?int $societyId = null): bool {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("UPDATE invoices SET status = 'Paid', paid_date = 'Today', payment_method = 'UPI (AutoPay)' WHERE invoice_number = ? AND society_id = ?");
        return $stmt->execute([$invoiceNumber, $societyId]);
    }

    public function getFinancialTotals(?int $societyId = null): array {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmt = $this->db->prepare("SELECT 
            COALESCE(SUM(CASE WHEN status = 'Paid' THEN amount ELSE 0 END), 0) AS total_collected,
            COALESCE(SUM(CASE WHEN status != 'Paid' THEN amount ELSE 0 END), 0) AS total_pending
            FROM invoices WHERE society_id = ?");
        $stmt->execute([$societyId]);
        $res = $stmt->fetch();

        $collected = (float)$res['total_collected'];
        $pending = (float)$res['total_pending'];
        $total = $collected + $pending;
        $rate = $total > 0 ? round(($collected / $total) * 100, 1) : 100.0;

        return [
            'collected' => $collected,
            'pending' => $pending,
            'collectionRate' => $rate
        ];
    }
}
