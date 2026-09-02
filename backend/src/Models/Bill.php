<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Bill extends BaseModel {
    protected string $table = 'bills';

    public function getAllBySociety(int $societyId, array $filters = []): array {
        $db = Database::getConnection();
        $sql = "SELECT b.*, u.unit_code, u.sqft_area, u.floor_number, t.name as tower_name, 
            COALESCE(
                (SELECT ru2.full_name FROM unit_occupancies uo2 JOIN residents r2 ON uo2.resident_id = r2.id JOIN users ru2 ON r2.user_id = ru2.id WHERE uo2.unit_id = u.id AND r2.is_deleted = 0 ORDER BY (CASE WHEN r2.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo2.is_primary DESC, uo2.id ASC LIMIT 1),
                u.tenant_name,
                u.owner_name,
                'Resident'
            ) as resident_name, 
            COALESCE(
                (SELECT r3.resident_type FROM unit_occupancies uo3 JOIN residents r3 ON uo3.resident_id = r3.id WHERE uo3.unit_id = u.id AND r3.is_deleted = 0 ORDER BY (CASE WHEN r3.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo3.is_primary DESC, uo3.id ASC LIMIT 1),
                u.occupancy_status,
                'Owner'
            ) as resident_type, 
            u.owner_name,
            u.tenant_name,
            (SELECT GROUP_CONCAT(CONCAT(ru4.full_name, ' (', r4.resident_type, ')') SEPARATOR ', ')
             FROM unit_occupancies uo4
             JOIN residents r4 ON uo4.resident_id = r4.id
             JOIN users ru4 ON r4.user_id = ru4.id
             WHERE uo4.unit_id = u.id AND r4.is_deleted = 0) as all_residents_summary,
            COALESCE(
                (SELECT ru5.phone FROM unit_occupancies uo5 JOIN residents r5 ON uo5.resident_id = r5.id JOIN users ru5 ON r5.user_id = ru5.id WHERE uo5.unit_id = u.id AND r5.is_deleted = 0 ORDER BY (CASE WHEN r5.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo5.is_primary DESC, uo5.id ASC LIMIT 1),
                u.contact_phone
            ) as resident_phone, 
            COALESCE(
                (SELECT ru6.email FROM unit_occupancies uo6 JOIN residents r6 ON uo6.resident_id = r6.id JOIN users ru6 ON r6.user_id = ru6.id WHERE uo6.unit_id = u.id AND r6.is_deleted = 0 ORDER BY (CASE WHEN r6.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo6.is_primary DESC, uo6.id ASC LIMIT 1),
                u.contact_email
            ) as resident_email 
            FROM {$this->table} b 
            JOIN units u ON b.unit_id = u.id 
            JOIN towers t ON u.tower_id = t.id";

        if ($societyId > 0) {
            $sql .= " WHERE b.society_id = ? AND b.is_deleted = 0";
            $params = [$societyId];
        } else {
            $sql .= " WHERE b.is_deleted = 0";
            $params = [];
        }

        if (!empty($filters['unit_id'])) {
            $sql .= " AND b.unit_id = ?";
            $params[] = $filters['unit_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'ALL') {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $sql .= " AND b.bill_date >= ?";
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= " AND b.bill_date <= ?";
            $params[] = $filters['to'];
        }

        $sql .= " ORDER BY b.bill_date DESC, b.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Populate line items
        foreach ($bills as &$bill) {
            $stmtItems = $db->prepare("SELECT bi.*, cm.charge_name, cm.calculation_type 
                FROM bill_items bi 
                LEFT JOIN charge_masters cm ON bi.charge_master_id = cm.id 
                WHERE bi.bill_id = ?");
            $stmtItems->execute([$bill['id']]);
            $bill['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }

        return $bills;
    }

    public function findByIdWithItems(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT b.*, u.unit_code, u.sqft_area, u.floor_number, t.name as tower_name, 
            COALESCE(
                (SELECT ru2.full_name FROM unit_occupancies uo2 JOIN residents r2 ON uo2.resident_id = r2.id JOIN users ru2 ON r2.user_id = ru2.id WHERE uo2.unit_id = u.id AND r2.is_deleted = 0 ORDER BY (CASE WHEN r2.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo2.is_primary DESC, uo2.id ASC LIMIT 1),
                u.tenant_name,
                u.owner_name,
                'Resident'
            ) as resident_name, 
            COALESCE(
                (SELECT r3.resident_type FROM unit_occupancies uo3 JOIN residents r3 ON uo3.resident_id = r3.id WHERE uo3.unit_id = u.id AND r3.is_deleted = 0 ORDER BY (CASE WHEN r3.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo3.is_primary DESC, uo3.id ASC LIMIT 1),
                u.occupancy_status,
                'Owner'
            ) as resident_type, 
            u.owner_name,
            u.tenant_name,
            (SELECT GROUP_CONCAT(CONCAT(ru4.full_name, ' (', r4.resident_type, ')') SEPARATOR ', ')
             FROM unit_occupancies uo4
             JOIN residents r4 ON uo4.resident_id = r4.id
             JOIN users ru4 ON r4.user_id = ru4.id
             WHERE uo4.unit_id = u.id AND r4.is_deleted = 0) as all_residents_summary,
            COALESCE(
                (SELECT ru5.phone FROM unit_occupancies uo5 JOIN residents r5 ON uo5.resident_id = r5.id JOIN users ru5 ON r5.user_id = ru5.id WHERE uo5.unit_id = u.id AND r5.is_deleted = 0 ORDER BY (CASE WHEN r5.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo5.is_primary DESC, uo5.id ASC LIMIT 1),
                u.contact_phone
            ) as resident_phone, 
            COALESCE(
                (SELECT ru6.email FROM unit_occupancies uo6 JOIN residents r6 ON uo6.resident_id = r6.id JOIN users ru6 ON r6.user_id = ru6.id WHERE uo6.unit_id = u.id AND r6.is_deleted = 0 ORDER BY (CASE WHEN r6.resident_type = 'Tenant' THEN 1 ELSE 2 END), uo6.is_primary DESC, uo6.id ASC LIMIT 1),
                u.contact_email
            ) as resident_email,
            s.name as society_name, s.address as society_address, s.society_code 
            FROM {$this->table} b 
            JOIN units u ON b.unit_id = u.id 
            JOIN towers t ON u.tower_id = t.id 
            JOIN societies s ON b.society_id = s.id 
            WHERE b.id = ? AND b.is_deleted = 0 LIMIT 1");
        $stmt->execute([$id]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) return null;

        $stmtItems = $db->prepare("SELECT bi.*, cm.charge_name, cm.calculation_type 
            FROM bill_items bi 
            LEFT JOIN charge_masters cm ON bi.charge_master_id = cm.id 
            WHERE bi.bill_id = ?");
        $stmtItems->execute([$id]);
        $bill['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        return $bill;
    }

    /**
     * Batch Monthly Recurring Bill Generation Engine
     * Calculates line items per unit according to Charge Masters, computes GST, creates Bills + Bill Items + Journal Entry
     */
    public function generateMonthlyBills(int $societyId, string $periodStart, string $periodEnd, string $dueDate, ?int $createdByUserId = null): array {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) $db->beginTransaction();

        try {
            // 1. Get active charge masters
            $cmStmt = $db->prepare("SELECT * FROM charge_masters WHERE society_id = ? AND is_recurring = 1 AND is_deleted = 0");
            $cmStmt->execute([$societyId]);
            $chargeMasters = $cmStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($chargeMasters)) {
                throw new \RuntimeException("No active recurring Charge Masters configured for this society. Please create at least one charge rule.");
            }

            // 2. Get units with active owner or tenant (Occupied Units)
            $unitStmt = $db->prepare("SELECT u.* FROM units u 
                LEFT JOIN unit_occupancies uo ON u.id = uo.unit_id 
                LEFT JOIN residents r ON uo.resident_id = r.id 
                WHERE u.society_id = ? AND u.is_deleted = 0 
                  AND (u.occupancy_status IN ('Owner', 'Rented', 'Occupied (Owner)', 'Occupied (Tenant)') 
                       OR (u.owner_name IS NOT NULL AND u.owner_name != '') 
                       OR (u.tenant_name IS NOT NULL AND u.tenant_name != '')
                       OR r.id IS NOT NULL)
                GROUP BY u.id
                ORDER BY u.unit_code ASC");
            $unitStmt->execute([$societyId]);
            $units = $unitStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($units)) {
                // Fallback to all active units if none flagged
                $fallbackStmt = $db->prepare("SELECT u.* FROM units u WHERE u.society_id = ? AND u.is_deleted = 0 ORDER BY u.unit_code ASC");
                $fallbackStmt->execute([$societyId]);
                $units = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $generatedBills = [];
            $totalBilledAll = 0.00;
            $monthLabel = date('M Y', strtotime($periodStart));

            // Default Accounts
            $coaModel = new ChartOfAccount();
            $arAccount = $coaModel->findByCode($societyId, '1040'); // Accounts Receivable
            $maintAccount = $coaModel->findByCode($societyId, '4010'); // Maintenance Income
            $gstAccount = $coaModel->findByCode($societyId, '2040'); // GST Payable

            // Fetch society code for globally unique bill numbers
            $socStmt = $db->prepare("SELECT society_code FROM societies WHERE id = ?");
            $socStmt->execute([$societyId]);
            $rawSocCode = $socStmt->fetchColumn() ?: ('SOC' . $societyId);
            $cleanSocCode = strtoupper(str_replace(['-', ' ', '_'], '', $rawSocCode));

            foreach ($units as $unit) {
                $unitId = (int)$unit['id'];
                $unitCode = $unit['unit_code'];
                $areaSqft = (float)($unit['sqft_area'] ?: 1000);

                $cleanUnitCode = strtoupper(str_replace(['-', ' ', '_'], '', $unitCode));
                $periodTag = date('Ym', strtotime($periodStart));
                $billNumber = "BILL-{$cleanSocCode}-{$periodTag}-{$cleanUnitCode}";

                // Check if bill already exists for this unit in this period OR with this bill_number
                $checkStmt = $db->prepare("SELECT id FROM {$this->table} WHERE (society_id = ? AND unit_id = ? AND billing_period_start = ?) OR bill_number = ? LIMIT 1");
                $checkStmt->execute([$societyId, $unitId, $periodStart, $billNumber]);
                if ($checkStmt->fetch()) continue; // Skip already generated

                $subtotal = 0.00;
                $taxTotal = 0.00;
                $lineItems = [];

                foreach ($chargeMasters as $cm) {
                    $calcType = $cm['calculation_type'];
                    $rate = (float)$cm['rate_amount'];
                    $gstPct = (float)$cm['gst_percentage'];

                    $qty = 1.0;
                    $itemSubtotal = 0.00;

                    if ($calcType === 'SqFt') {
                        $qty = $areaSqft;
                        $itemSubtotal = round($qty * $rate, 2);
                    } elseif ($calcType === 'Fixed') {
                        $qty = 1.0;
                        $itemSubtotal = $rate;
                    } elseif ($calcType === 'Meter') {
                        $qty = 20.0; // Standard nominal units consumed
                        $itemSubtotal = round($qty * $rate, 2);
                    } else {
                        $qty = 1.0;
                        $itemSubtotal = $rate;
                    }

                    $itemTax = round(($itemSubtotal * $gstPct) / 100, 2);
                    $itemTotal = $itemSubtotal + $itemTax;

                    $subtotal += $itemSubtotal;
                    $taxTotal += $itemTax;

                    $lineItems[] = [
                        'charge_master_id' => $cm['id'],
                        'description' => $cm['charge_name'] . ($calcType === 'SqFt' ? " ({$areaSqft} sq.ft @ ₹{$rate}/sqft)" : ''),
                        'quantity' => $qty,
                        'rate' => $rate,
                        'subtotal' => $itemSubtotal,
                        'tax_amount' => $itemTax,
                        'total_amount' => $itemTotal
                    ];
                }

                $totalAmount = $subtotal + $taxTotal;
                $totalBilledAll += $totalAmount;

                // Insert Bill Header
                $stmtBill = $db->prepare("INSERT INTO {$this->table} 
                    (society_id, unit_id, bill_number, billing_period_start, billing_period_end, bill_date, due_date, subtotal_amount, tax_amount, late_fee_amount, total_amount, paid_amount, outstanding_amount, status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, 0.00, ?, 'Unpaid', ?)");
                $stmtBill->execute([
                    $societyId,
                    $unitId,
                    $billNumber,
                    $periodStart,
                    $periodEnd,
                    date('Y-m-d'),
                    $dueDate,
                    $subtotal,
                    $taxTotal,
                    $totalAmount,
                    $totalAmount,
                    "Monthly Maintenance Bill for {$monthLabel} - Unit {$unitCode}"
                ]);
                $billId = (int)$db->lastInsertId();

                // Insert Bill Items
                $stmtItem = $db->prepare("INSERT INTO bill_items (bill_id, charge_master_id, description, quantity, rate, subtotal, tax_amount, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($lineItems as $li) {
                    $stmtItem->execute([
                        $billId,
                        $li['charge_master_id'],
                        $li['description'],
                        $li['quantity'],
                        $li['rate'],
                        $li['subtotal'],
                        $li['tax_amount'],
                        $li['total_amount']
                    ]);
                }

                $generatedBills[] = [
                    'id' => $billId,
                    'bill_number' => $billNumber,
                    'unit_code' => $unitCode,
                    'total_amount' => $totalAmount
                ];

                // Dispatch real-time in-app notification to all unit residents (owners & tenants)
                try {
                    $notifModel = new \App\Models\Notification();
                    $notifModel->notifyBillGenerated($societyId, $unitId, [
                        'id' => $billId,
                        'bill_number' => $billNumber,
                        'unit_code' => $unitCode,
                        'total_amount' => $totalAmount,
                        'due_date' => $dueDate
                    ]);
                } catch (\Throwable $ne) {
                    // Non-blocking notification dispatch
                }
            }

            // 3. Post Double-Entry Journal for the batch (Debit: Accounts Receivable, Credit: Income & GST)
            if ($totalBilledAll > 0 && $arAccount && $maintAccount) {
                $jeModel = new JournalEntry();
                $jeItems = [
                    [
                        'account_id' => $arAccount['id'],
                        'debit_amount' => $totalBilledAll,
                        'credit_amount' => 0.00
                    ],
                    [
                        'account_id' => $maintAccount['id'],
                        'debit_amount' => 0.00,
                        'credit_amount' => $totalBilledAll
                    ]
                ];
                $jeModel->createWithItems([
                    'society_id' => $societyId,
                    'entry_date' => date('Y-m-d'),
                    'narration' => "Recurring Maintenance Billing Batch for {$monthLabel} (" . count($generatedBills) . " units billed)",
                    'source_module' => 'Billing',
                    'created_by_user_id' => $createdByUserId
                ], $jeItems);
            }

            if ($manageTx) $db->commit();
            return [
                'count' => count($generatedBills),
                'total_amount' => $totalBilledAll,
                'bills' => $generatedBills
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
