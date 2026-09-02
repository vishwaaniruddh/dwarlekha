<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class FinancialReport {
    /**
     * Generate Trial Balance Report
     * Lists all active accounts with Total Debits, Total Credits, and Net Balance.
     */
    public function getTrialBalance(int $societyId, ?string $from = null, ?string $to = null): array {
        $db = Database::getConnection();
        
        $sql = "SELECT coa.id, coa.account_code, coa.account_name, coa.account_type,
            COALESCE(SUM(ji.debit_amount), 0.00) as total_debit,
            COALESCE(SUM(ji.credit_amount), 0.00) as total_credit
            FROM chart_of_accounts coa
            LEFT JOIN journal_items ji ON coa.id = ji.account_id
            LEFT JOIN journal_entries je ON ji.journal_entry_id = je.id AND je.is_deleted = 0";
        
        $where = ["coa.is_deleted = 0"];
        $params = [];
        if ($societyId > 0) {
            $where[] = "coa.society_id = ?";
            $params[] = $societyId;
        }

        if ($from) {
            $where[] = "(je.entry_date >= ? OR je.entry_date IS NULL)";
            $params[] = $from;
        }
        if ($to) {
            $where[] = "(je.entry_date <= ? OR je.entry_date IS NULL)";
            $params[] = $to;
        }

        $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type ORDER BY coa.account_code ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalDebits = 0.00;
        $totalCredits = 0.00;

        foreach ($accounts as &$acc) {
            $deb = (float)$acc['total_debit'];
            $cred = (float)$acc['total_credit'];
            $totalDebits += $deb;
            $totalCredits += $cred;

            if (in_array($acc['account_type'], ['Asset', 'Expense'])) {
                $acc['net_balance'] = $deb - $cred;
            } else {
                $acc['net_balance'] = $cred - $deb;
            }
        }

        return [
            'society_id' => $societyId,
            'from' => $from,
            'to' => $to,
            'accounts' => $accounts,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'is_balanced' => abs($totalDebits - $totalCredits) < 0.01
        ];
    }

    /**
     * Generate Profit & Loss (P&L) Statement
     */
    public function getProfitAndLoss(int $societyId, ?string $from = null, ?string $to = null): array {
        $tb = $this->getTrialBalance($societyId, $from, $to);
        
        $incomeAccounts = [];
        $expenseAccounts = [];
        $totalIncome = 0.00;
        $totalExpenses = 0.00;

        foreach ($tb['accounts'] as $acc) {
            if ($acc['account_type'] === 'Income' && ($acc['total_credit'] > 0 || $acc['total_debit'] > 0)) {
                $net = (float)$acc['net_balance'];
                $incomeAccounts[] = $acc;
                $totalIncome += $net;
            } elseif ($acc['account_type'] === 'Expense' && ($acc['total_debit'] > 0 || $acc['total_credit'] > 0)) {
                $net = (float)$acc['net_balance'];
                $expenseAccounts[] = $acc;
                $totalExpenses += $net;
            }
        }

        $netSurplus = $totalIncome - $totalExpenses;

        return [
            'society_id' => $societyId,
            'from' => $from,
            'to' => $to,
            'income_accounts' => $incomeAccounts,
            'expense_accounts' => $expenseAccounts,
            'total_income' => round($totalIncome, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_surplus' => round($netSurplus, 2),
            'is_surplus' => $netSurplus >= 0
        ];
    }

    /**
     * Generate Balance Sheet
     */
    public function getBalanceSheet(int $societyId, ?string $asOfDate = null): array {
        $tb = $this->getTrialBalance($societyId, null, $asOfDate);
        $pnl = $this->getProfitAndLoss($societyId, null, $asOfDate);

        $assetAccounts = [];
        $liabilityAccounts = [];
        $equityAccounts = [];
        $totalAssets = 0.00;
        $totalLiabilities = 0.00;
        $totalEquity = 0.00;

        foreach ($tb['accounts'] as $acc) {
            $net = (float)$acc['net_balance'];
            if ($acc['account_type'] === 'Asset') {
                $assetAccounts[] = $acc;
                $totalAssets += $net;
            } elseif ($acc['account_type'] === 'Liability') {
                $liabilityAccounts[] = $acc;
                $totalLiabilities += $net;
            } elseif ($acc['account_type'] === 'Equity') {
                $equityAccounts[] = $acc;
                $totalEquity += $net;
            }
        }

        // Add current period surplus to equity
        $currentSurplus = (float)$pnl['net_surplus'];
        $totalEquityWithSurplus = $totalEquity + $currentSurplus;
        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquityWithSurplus;

        return [
            'society_id' => $societyId,
            'as_of_date' => $asOfDate ?: date('Y-m-d'),
            'asset_accounts' => $assetAccounts,
            'liability_accounts' => $liabilityAccounts,
            'equity_accounts' => $equityAccounts,
            'current_period_surplus' => round($currentSurplus, 2),
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => round($totalEquityWithSurplus, 2),
            'total_liabilities_and_equity' => round($totalLiabilitiesAndEquity, 2),
            'is_balanced' => abs($totalAssets - $totalLiabilitiesAndEquity) < 0.01
        ];
    }

    /**
     * Resident Receivable Individual Unit Ledger
     */
    public function getResidentLedger(int $societyId, $unitIdentifier): array {
        $db = Database::getConnection();

        $raw = (string)$unitIdentifier;
        $cleanCode = preg_replace('/^FLAT[-_]/i', '', trim($raw));
        $numericId = is_numeric($raw) ? (int)$raw : 0;

        // 1. Fetch unit details by ID or unit_code
        $sql = "SELECT u.*, t.name as tower_name, 
            COALESCE(ru.full_name, u.owner_name, 'Resident') as resident_name, 
            COALESCE(r.resident_type, u.occupancy_status) as resident_type, 
            COALESCE(ru.phone, u.contact_phone) as resident_phone 
            FROM units u 
            JOIN towers t ON u.tower_id = t.id 
            LEFT JOIN unit_occupancies uo ON u.id = uo.unit_id AND uo.is_primary = 1 
            LEFT JOIN residents r ON uo.resident_id = r.id 
            LEFT JOIN users ru ON r.user_id = ru.id 
            WHERE (u.id = ? OR u.unit_code = ? OR u.unit_code = ?)";
        
        $params = [$numericId, $raw, $cleanCode];
        if ($societyId > 0) {
            $sql .= " AND u.society_id = ?";
            $params[] = $societyId;
        }
        $sql .= " LIMIT 1";

        $stmtUnit = $db->prepare($sql);
        $stmtUnit->execute($params);
        $unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);
        if (!$unit) return [];

        $unitId = (int)$unit['id'];

        // 2. Fetch all bills for unit
        $stmtBills = $db->prepare("SELECT id, bill_number, bill_date, total_amount, paid_amount, outstanding_amount, status 
            FROM bills WHERE unit_id = ? AND is_deleted = 0 ORDER BY bill_date ASC");
        $stmtBills->execute([$unitId]);
        $bills = $stmtBills->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch all payments for unit
        $stmtPayments = $db->prepare("SELECT id, receipt_number, payment_date, amount, payment_mode, status, notes 
            FROM payments WHERE unit_id = ? AND is_deleted = 0 AND status = 'Success' ORDER BY payment_date ASC");
        $stmtPayments->execute([$unitId]);
        $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

        // 4. Merge chronologically to build running balance ledger
        $ledgerEntries = [];
        $runningBalance = 0.00;

        foreach ($bills as $b) {
            $amt = (float)$b['total_amount'];
            $runningBalance += $amt;
            $ledgerEntries[] = [
                'date' => $b['bill_date'],
                'particulars' => "Maintenance Invoice ({$b['bill_number']})",
                'reference_no' => $b['bill_number'],
                'type' => 'Invoice',
                'debit' => $amt,
                'credit' => 0.00,
                'balance' => $runningBalance
            ];
        }

        foreach ($payments as $p) {
            $amt = (float)$p['amount'];
            $runningBalance -= $amt;
            $ledgerEntries[] = [
                'date' => date('Y-m-d', strtotime($p['payment_date'])),
                'particulars' => "Payment Received via {$p['payment_mode']} ({$p['receipt_number']})",
                'reference_no' => $p['receipt_number'],
                'type' => 'Payment',
                'debit' => 0.00,
                'credit' => $amt,
                'balance' => $runningBalance
            ];
        }

        // Sort by date
        usort($ledgerEntries, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Recalculate true sequential running balance
        $seqBalance = 0.00;
        foreach ($ledgerEntries as &$entry) {
            $seqBalance += ($entry['debit'] - $entry['credit']);
            $entry['balance'] = round($seqBalance, 2);
        }

        return [
            'unit' => $unit,
            'current_outstanding' => round($seqBalance, 2),
            'entries' => $ledgerEntries
        ];
    }
}
