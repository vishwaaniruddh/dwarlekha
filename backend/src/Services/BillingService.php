<?php
namespace App\Services;

use App\Models\Invoice;
use App\Config\Database;
use InvalidArgumentException;
use Exception;

class BillingService {
    private Invoice $invoiceModel;

    public function __construct(?Invoice $invoiceModel = null) {
        $this->invoiceModel = $invoiceModel ?: new Invoice();
    }

    public function getInvoices(): array {
        return $this->invoiceModel->getAll();
    }

    public function createInvoice(array $input): array {
        if (empty($input['flatNumber'])) {
            throw new InvalidArgumentException("Flat number is required.");
        }

        $invNum = $input['id'] ?? ('INV-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6)));
        $amount = (float)($input['amount'] ?? 4200.00);

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $data = [
                'invoice_number' => $invNum,
                'flat_number' => $input['flatNumber'],
                'resident_name' => $input['resident'] ?? 'Flat Resident',
                'month_period' => $input['month'] ?? date('F Y'),
                'amount' => $amount,
                'base_maintenance' => round($amount * 0.7),
                'water_charges' => 450.00,
                'sinking_fund' => 500.00,
                'clubhouse_fee' => 350.00,
                'due_date' => $input['dueDate'] ?? '30th of Month'
            ];

            $this->invoiceModel->create($data);

            if ($manageTx) {
                $db->commit();
            }

            return array_merge($input, [
                'id' => $invNum,
                'amount' => $amount,
                'status' => 'Pending'
            ]);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function markAsPaid(string $invoiceNumber): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $result = $this->invoiceModel->markPaid($invoiceNumber);
            if ($manageTx) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function getFinancialSummary(): array {
        return $this->invoiceModel->getFinancialTotals();
    }
}
