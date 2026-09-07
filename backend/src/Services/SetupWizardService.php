<?php
namespace App\Services;

use App\Config\Database;
use App\Config\TenantContext;
use Exception;
use InvalidArgumentException;
use PDO;

class SetupWizardService {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    /**
     * Resolve target society ID from parameter, TenantContext, or active session
     */
    private function resolveSocietyId(?int $societyId = null): int {
        if ($societyId && $societyId > 0) {
            return $societyId;
        }
        $ctxId = TenantContext::getSocietyId();
        if ($ctxId && $ctxId > 0) {
            return $ctxId;
        }
        $currUser = \App\Config\RbacGuard::getCurrentUser();
        if (!empty($currUser['societyId'])) {
            return (int)$currUser['societyId'];
        }
        // Fallback: pick first active society
        $stmt = $this->db->query("SELECT id FROM societies WHERE is_deleted = 0 ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : 1;
    }

    /**
     * Get comprehensive setup checklist and progress for a society
     */
    public function getSetupStatus(?int $societyId = null): array {
        $socId = $this->resolveSocietyId($societyId);

        // 1. Society & Bank Profile
        $socStmt = $this->db->prepare("SELECT * FROM societies WHERE id = ? AND is_deleted = 0 LIMIT 1");
        $socStmt->execute([$socId]);
        $society = $socStmt->fetch();
        if (!$society) {
            throw new Exception("Society not found.");
        }

        $hasBank = !empty($society['bank_account_no']) || !empty($society['upi_vpa']);
        $hasLocation = !empty($society['city']) && (!empty($society['address']) || !empty($society['address_line1']));
        $step1Complete = !empty($society['name']) && $hasLocation;

        // 2. Towers & Units
        $towerStmt = $this->db->prepare("SELECT id, name, tower_code, total_floors, total_units FROM towers WHERE society_id = ? AND is_deleted = 0 ORDER BY id ASC");
        $towerStmt->execute([$socId]);
        $towers = $towerStmt->fetchAll();

        $unitCountStmt = $this->db->prepare("SELECT COUNT(*) as cnt, COUNT(DISTINCT unit_type) as types_cnt, SUM(sqft_area) as total_sqft FROM units WHERE society_id = ? AND is_deleted = 0");
        $unitCountStmt->execute([$socId]);
        $unitStats = $unitCountStmt->fetch();
        $totalUnits = (int)($unitStats['cnt'] ?? 0);
        $step2Complete = $totalUnits > 0 && count($towers) > 0;

        // 3. Chart of Accounts
        $coaStmt = $this->db->prepare("SELECT COUNT(*) as total_accounts, 
                                              SUM(CASE WHEN account_type = 'Asset' THEN 1 ELSE 0 END) as assets,
                                              SUM(CASE WHEN account_type = 'Liability' THEN 1 ELSE 0 END) as liabilities,
                                              SUM(CASE WHEN account_type = 'Equity' THEN 1 ELSE 0 END) as equity,
                                              SUM(CASE WHEN account_type = 'Income' THEN 1 ELSE 0 END) as income,
                                              SUM(CASE WHEN account_type = 'Expense' THEN 1 ELSE 0 END) as expenses
                                       FROM chart_of_accounts 
                                       WHERE society_id = ? AND is_deleted = 0");
        $coaStmt->execute([$socId]);
        $coaStats = $coaStmt->fetch();
        $totalAccounts = (int)($coaStats['total_accounts'] ?? 0);
        $step3Complete = $totalAccounts >= 5;

        $coaListStmt = $this->db->prepare("SELECT a.id, a.account_code, a.account_name, a.account_type, a.is_deleted, a.is_active,
                                           (SELECT COUNT(*) FROM journal_items j WHERE j.account_id = a.id) as tx_count 
                                           FROM chart_of_accounts a 
                                           WHERE a.society_id = ? 
                                           ORDER BY a.account_code ASC");
        $coaListStmt->execute([$socId]);
        $coaAccounts = $coaListStmt->fetchAll();

        // 4. Charge Rules
        $chargeStmt = $this->db->prepare("SELECT id, charge_name, calculation_type, rate_amount, billing_cycle 
                                          FROM charge_masters 
                                          WHERE society_id = ? AND is_deleted = 0");
        $chargeStmt->execute([$socId]);
        $charges = $chargeStmt->fetchAll();
        $step4Complete = count($charges) > 0;

        // 5. SMTP Configuration
        $smtpStmt = $this->db->prepare("SELECT id, smtp_host, sender_email, is_active, last_test_status FROM smtp_configs WHERE society_id = ? AND is_deleted = 0 LIMIT 1");
        $smtpStmt->execute([$socId]);
        $smtpConfig = $smtpStmt->fetch();
        $hasSmtp = !empty($smtpConfig) && !empty($smtpConfig['smtp_host']);
        $step5Complete = $hasSmtp;

        // 6. Admin & Security Users
        $userStmt = $this->db->prepare("SELECT u.id, u.full_name, u.email, u.phone, r.role_code, r.name as role_name
                                        FROM users u
                                        JOIN roles r ON u.role_id = r.id
                                        WHERE u.society_id = ? AND u.is_deleted = 0 AND r.role_code IN ('super_admin', 'estate_director', 'facility_manager')");
        $userStmt->execute([$socId]);
        $adminUsers = $userStmt->fetchAll();
        $step6Complete = count($adminUsers) > 0;

        // Calculate Progress Percentage
        $steps = [
            'society_bank' => [
                'step_number' => 1,
                'title' => 'Society Identity & Bank',
                'is_completed' => $step1Complete,
                'details' => [
                    'name' => $society['name'],
                    'code' => $society['society_code'],
                    'has_bank' => $hasBank,
                    'bank_name' => $society['bank_name'] ?? null,
                    'account_no' => $society['bank_account_no'] ?? null,
                    'upi_vpa' => $society['upi_vpa'] ?? null,
                    'city' => $society['city'] ?? null
                ]
            ],
            'towers_units' => [
                'step_number' => 2,
                'title' => 'Towers & Flats Hierarchy',
                'is_completed' => $step2Complete,
                'details' => [
                    'towers_count' => count($towers),
                    'units_count' => $totalUnits,
                    'total_sqft' => (float)($unitStats['total_sqft'] ?? 0),
                    'towers' => $towers
                ]
            ],
            'chart_of_accounts' => [
                'step_number' => 3,
                'title' => 'Chart of Accounts Preset',
                'is_completed' => $step3Complete,
                'details' => [
                    'total_accounts' => $totalAccounts,
                    'assets' => (int)($coaStats['assets'] ?? 0),
                    'liabilities' => (int)($coaStats['liabilities'] ?? 0),
                    'equity' => (int)($coaStats['equity'] ?? 0),
                    'income' => (int)($coaStats['income'] ?? 0),
                    'expenses' => (int)($coaStats['expenses'] ?? 0),
                    'accounts' => $coaAccounts
                ]
            ],
            'charge_rules' => [
                'step_number' => 4,
                'title' => 'Billing & Charge Master',
                'is_completed' => $step4Complete,
                'details' => [
                    'rules_count' => count($charges),
                    'rules' => $charges
                ]
            ],
            'smtp_settings' => [
                'step_number' => 5,
                'title' => 'Email & SMTP Gateway',
                'is_completed' => $step5Complete,
                'is_optional' => true,
                'details' => [
                    'has_smtp' => $hasSmtp,
                    'host' => $smtpConfig['smtp_host'] ?? null,
                    'sender_email' => $smtpConfig['sender_email'] ?? null,
                    'is_active' => (bool)($smtpConfig['is_active'] ?? false),
                    'last_test_status' => $smtpConfig['last_test_status'] ?? null
                ]
            ],
            'admin_launch' => [
                'step_number' => 6,
                'title' => 'Admin Credentials & Launch',
                'is_completed' => $step6Complete,
                'details' => [
                    'admins_count' => count($adminUsers),
                    'primary_admin' => $adminUsers[0] ?? null
                ]
            ]
        ];

        $completedCount = 0;
        foreach ($steps as $st) {
            if ($st['is_completed']) $completedCount++;
        }
        $progressPercent = (int)round(($completedCount / count($steps)) * 100);

        return [
            'society_id' => $socId,
            'society_name' => $society['name'],
            'society_code' => $society['society_code'],
            'progress_percent' => $progressPercent,
            'completed_steps' => $completedCount,
            'total_steps' => count($steps),
            'is_fully_configured' => ($step1Complete && $step2Complete && $step3Complete && $step4Complete && $step6Complete),
            'steps' => $steps
        ];
    }

    /**
     * Step 1: Save Society Identity and Bank Account info
     */
    public function saveSocietyBankStep(int $societyId, array $input): array {
        $manageTx = !$this->db->inTransaction();
        if ($manageTx) $this->db->beginTransaction();

        try {
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                throw new InvalidArgumentException("Society Name is required.");
            }

            $sql = "UPDATE societies SET 
                        name = ?,
                        legal_name = ?,
                        address_line1 = ?,
                        address = ?,
                        city = ?,
                        state = ?,
                        pincode = ?,
                        contact_phone = ?,
                        contact_email = ?,
                        bank_name = ?,
                        bank_account_no = ?,
                        bank_ifsc = ?,
                        upi_vpa = ?
                    WHERE id = ? AND is_deleted = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $name,
                $input['legal_name'] ?? $name,
                $input['address_line1'] ?? ($input['address'] ?? ''),
                $input['address'] ?? ($input['address_line1'] ?? ''),
                $input['city'] ?? 'Pune',
                $input['state'] ?? 'Maharashtra',
                $input['pincode'] ?? '411057',
                $input['contact_phone'] ?? '+91 98200 11223',
                $input['contact_email'] ?? (strtolower(preg_replace('/[^a-z0-9]/', '', $name)) . '@society.com'),
                $input['bank_name'] ?? 'HDFC Bank',
                $input['bank_account_no'] ?? null,
                $input['bank_ifsc'] ?? null,
                $input['upi_vpa'] ?? null,
                $societyId
            ]);

            if ($manageTx) $this->db->commit();
            return $this->getSetupStatus($societyId);
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Step 2: Fast Bulk Infrastructure & Flat Generator (Rule 1 Strict Transaction)
     */
    public function generateTowersAndUnits(int $societyId, array $input): array {
        $towersConfig = $input['towers'] ?? [];
        if (empty($towersConfig)) {
            // If society already has towers, do not force default Tower A/B
            $checkExisting = $this->db->prepare("SELECT COUNT(*) FROM towers WHERE society_id = ? AND is_deleted = 0");
            $checkExisting->execute([$societyId]);
            if ((int)$checkExisting->fetchColumn() > 0) {
                return [
                    'society_id' => $societyId,
                    'created_towers' => 0,
                    'created_units' => 0,
                    'message' => 'Existing towers retained; no new towers added.'
                ];
            }

            // Default template only if society has 0 towers
            $towersConfig = [
                ['name' => 'Tower A', 'code' => 'A', 'floors' => 10, 'unitsPerFloor' => 4, 'type' => '2BHK', 'sqft' => 950],
                ['name' => 'Tower B', 'code' => 'B', 'floors' => 10, 'unitsPerFloor' => 4, 'type' => '3BHK', 'sqft' => 1450]
            ];
        }

        $manageTx = !$this->db->inTransaction();
        if ($manageTx) $this->db->beginTransaction();

        try {
            $createdTowers = 0;
            $createdUnits = 0;

            foreach ($towersConfig as $tDef) {
                $tName = trim($tDef['name'] ?? 'Tower A');
                $tCode = strtoupper(trim($tDef['code'] ?? substr($tName, -1)));
                $floors = max(1, (int)($tDef['floors'] ?? 10));
                $unitsPerFloor = max(1, (int)($tDef['unitsPerFloor'] ?? 4));
                $defaultType = $tDef['type'] ?? '2BHK';
                $defaultSqft = (int)($tDef['sqft'] ?? 1000);

                // Find or create Tower
                $findTow = $this->db->prepare("SELECT id FROM towers WHERE society_id = ? AND (name = ? OR tower_code = ?) AND is_deleted = 0 LIMIT 1");
                $findTow->execute([$societyId, $tName, $tCode]);
                $towRow = $findTow->fetch();

                if ($towRow) {
                    $towerId = (int)$towRow['id'];
                } else {
                    $insTow = $this->db->prepare("INSERT INTO towers (society_id, name, tower_code, total_floors, total_units) VALUES (?, ?, ?, ?, ?)");
                    $insTow->execute([$societyId, $tName, $tCode, $floors, ($floors * $unitsPerFloor)]);
                    $towerId = (int)$this->db->lastInsertId();
                    $createdTowers++;
                }

                // Batch Generate Units
                $checkUnit = $this->db->prepare("SELECT id FROM units WHERE society_id = ? AND unit_code = ? AND is_deleted = 0 LIMIT 1");
                $insUnit = $this->db->prepare("INSERT INTO units (society_id, tower_id, unit_code, floor_number, unit_type, sqft_area, occupancy_status, maintenance_status) VALUES (?, ?, ?, ?, ?, ?, 'Vacant', 'Paid')");

                for ($f = 1; $f <= $floors; $f++) {
                    for ($u = 1; $u <= $unitsPerFloor; $u++) {
                        $flatNum = sprintf("%02d", $u);
                        $unitCode = "{$tCode}-{$f}{$flatNum}";

                        $checkUnit->execute([$societyId, $unitCode]);
                        if (!$checkUnit->fetch()) {
                            $insUnit->execute([
                                $societyId,
                                $towerId,
                                $unitCode,
                                $f,
                                $defaultType,
                                $defaultSqft
                            ]);
                            $createdUnits++;
                        }
                    }
                }

                // Update Tower total_units count
                $updTow = $this->db->prepare("UPDATE towers SET total_units = (SELECT COUNT(*) FROM units WHERE tower_id = ? AND is_deleted = 0) WHERE id = ?");
                $updTow->execute([$towerId, $towerId]);
            }

            // Sync Society total_units
            $syncSoc = $this->db->prepare("UPDATE societies SET total_units = (SELECT COUNT(*) FROM units WHERE society_id = ? AND is_deleted = 0) WHERE id = ?");
            $syncSoc->execute([$societyId, $societyId]);

            if ($manageTx) $this->db->commit();

            return [
                'created_towers' => $createdTowers,
                'created_units' => $createdUnits,
                'status' => $this->getSetupStatus($societyId)
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Step 3: Apply Standard Chart of Accounts Preset (Rule 1 Strict Transaction)
     */
    public function applyCoaPreset(int $societyId, string $presetType = 'residential', array $selectedCodes = []): array {
        $presets = [
            // Standard Indian Residential Housing Society COA (Compliant with CHS Model Bye-Laws)
            'residential' => [
                // Assets (1000 - 1999)
                ['code' => '1010', 'name' => 'Main Operating Bank Account', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1020', 'name' => 'Cash in Hand / Petty Cash', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1030', 'name' => 'Sinking Fund Fixed Deposit (Bank FD)', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1040', 'name' => 'Accounts Receivable (Residents)', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1050', 'name' => 'Major Repair Reserve Fixed Deposit', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1120', 'name' => 'Prepaid Insurance & Annual AMC', 'type' => 'Asset', 'balance' => 0.00],

                // Liabilities (2000 - 2999)
                ['code' => '2010', 'name' => 'Advance Maintenance Paid by Residents', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2020', 'name' => 'Trade Accounts Payable (Vendors)', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2030', 'name' => 'Security Guard Vendor Payable', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2040', 'name' => 'Statutory GST & TDS Withholding Payable', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2050', 'name' => 'Statutory Education Fund Payable', 'type' => 'Liability', 'balance' => 0.00],

                // Equity & Reserves (3000 - 3999)
                ['code' => '3010', 'name' => 'Society Corpus Fund', 'type' => 'Equity', 'balance' => 0.00],
                ['code' => '3020', 'name' => 'Sinking Fund Capital Reserve', 'type' => 'Equity', 'balance' => 0.00],
                ['code' => '3030', 'name' => 'General Accumulated Society Reserve', 'type' => 'Equity', 'balance' => 0.00],
                ['code' => '3040', 'name' => 'Major Repair & Maintenance Reserve', 'type' => 'Equity', 'balance' => 0.00],

                // Income (4000 - 4999)
                ['code' => '4010', 'name' => 'Monthly Maintenance Charges', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4020', 'name' => 'Sinking Fund Contribution', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4025', 'name' => 'Repair & Maintenance Fund Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4030', 'name' => 'Late Fee & Penal Interest Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4040', 'name' => 'Water Charges Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4050', 'name' => 'Clubhouse & Amenity Booking Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4060', 'name' => 'Bank Interest Income', 'type' => 'Income', 'balance' => 0.00],

                // Expenses (5000 - 5999)
                ['code' => '5010', 'name' => 'Repair & Maintenance Expense', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5020', 'name' => 'Electricity & Power Charges', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5030', 'name' => 'Water Supply Charges', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5040', 'name' => 'Security Guard Services', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5050', 'name' => 'Housekeeping & Cleaning', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5060', 'name' => 'Elevator AMC & Maintenance', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5070', 'name' => 'Garden & Landscaping', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5080', 'name' => 'Administrative & Office Expense', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5090', 'name' => 'Property Taxes & Municipal Cess', 'type' => 'Expense', 'balance' => 0.00]
            ],

            // Gated Township & Luxury Residential Complex
            'residential_gated' => [
                // Assets
                ['code' => '1010', 'name' => 'Main Operating Bank Account', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1020', 'name' => 'Cash in Hand / Petty Cash', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1030', 'name' => 'Sinking Fund Fixed Deposit (Bank FD)', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1040', 'name' => 'Accounts Receivable (Residents)', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1050', 'name' => 'Major Repair Reserve Fixed Deposit', 'type' => 'Asset', 'balance' => 0.00],
                ['code' => '1120', 'name' => 'Prepaid Insurance & Annual AMC', 'type' => 'Asset', 'balance' => 0.00],

                // Liabilities
                ['code' => '2010', 'name' => 'Advance Maintenance Paid by Residents', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2020', 'name' => 'Trade Accounts Payable (Vendors)', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2030', 'name' => 'Security Guard Vendor Payable', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2040', 'name' => 'Statutory GST & TDS Withholding Payable', 'type' => 'Liability', 'balance' => 0.00],
                ['code' => '2050', 'name' => 'Statutory Education Fund Payable', 'type' => 'Liability', 'balance' => 0.00],

                // Equity & Reserves
                ['code' => '3010', 'name' => 'Society Corpus Fund', 'type' => 'Equity', 'balance' => 0.00],
                ['code' => '3020', 'name' => 'Sinking Fund Capital Reserve', 'type' => 'Equity', 'balance' => 0.00],
                ['code' => '3030', 'name' => 'General Accumulated Society Reserve', 'type' => 'Equity', 'balance' => 0.00],
                ['code' => '3040', 'name' => 'Major Repair & Maintenance Reserve', 'type' => 'Equity', 'balance' => 0.00],

                // Income
                ['code' => '4010', 'name' => 'Monthly Maintenance Charges', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4020', 'name' => 'Sinking Fund Contribution', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4025', 'name' => 'Repair & Maintenance Fund Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4030', 'name' => 'Late Fee & Penal Interest Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4040', 'name' => 'Water Charges Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4050', 'name' => 'Clubhouse & Amenity Booking Income', 'type' => 'Income', 'balance' => 0.00],
                ['code' => '4060', 'name' => 'Bank Interest Income', 'type' => 'Income', 'balance' => 0.00],

                // Expenses
                ['code' => '5010', 'name' => 'Repair & Maintenance Expense', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5020', 'name' => 'Electricity & Power Charges', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5030', 'name' => 'Water Supply Charges', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5040', 'name' => 'Security Guard Services', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5050', 'name' => 'Housekeeping & Cleaning', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5060', 'name' => 'Elevator AMC & Maintenance', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5070', 'name' => 'Garden & Landscaping', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5080', 'name' => 'Administrative & Office Expense', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5090', 'name' => 'Property Taxes & Municipal Cess', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5100', 'name' => 'CCTV Surveillance & Access Control AMC', 'type' => 'Expense', 'balance' => 0.00],
                ['code' => '5110', 'name' => 'Fire Fighting Equipment & Safety Audit', 'type' => 'Expense', 'balance' => 0.00]
            ]
        ];

        $template = $presets[$presetType] ?? $presets['residential'];

        // Essential Core System Accounts that must always remain active for billing & double-entry
        $coreCodes = ['1010', '1040', '2010', '2030', '3010', '4010'];

        $manageTx = !$this->db->inTransaction();
        if ($manageTx) $this->db->beginTransaction();

        try {
            $createdAccounts = 0;
            $updatedAccounts = 0;

            // Determine active codes: if none specified, default to all template codes
            $allTemplateCodes = array_map(fn($t) => $t['code'], $template);
            if (empty($selectedCodes)) {
                $activeCodes = $allTemplateCodes;
            } else {
                // Ensure core accounts are always included
                $activeCodes = array_unique(array_merge($selectedCodes, $coreCodes));
            }

            // Check existing accounts for this society
            $existingStmt = $this->db->prepare("SELECT id, account_code, is_deleted FROM chart_of_accounts WHERE society_id = ?");
            $existingStmt->execute([$societyId]);
            $existingRows = $existingStmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($existingRows)) {
                $txCheck = $this->db->prepare("SELECT COUNT(*) FROM journal_items WHERE account_id = ?");
                $restoreStmt = $this->db->prepare("UPDATE chart_of_accounts SET is_deleted = 0, deleted_at = NULL, is_active = 1 WHERE id = ?");
                $softDelStmt = $this->db->prepare("UPDATE chart_of_accounts SET is_deleted = 1, deleted_at = NOW(), is_active = 0 WHERE id = ?");
                $insCoa = $this->db->prepare("INSERT INTO chart_of_accounts (society_id, account_code, account_name, account_type, is_system_account, is_active, balance) VALUES (?, ?, ?, ?, 1, 1, ?)");

                $existingByCode = [];
                foreach ($existingRows as $r) {
                    $existingByCode[$r['account_code']] = $r;
                }

                // 1. Process activeCodes: if exists and soft-deleted, restore; if not in DB, insert
                foreach ($activeCodes as $code) {
                    if (isset($existingByCode[$code])) {
                        if ((int)$existingByCode[$code]['is_deleted'] === 1) {
                            $restoreStmt->execute([(int)$existingByCode[$code]['id']]);
                            $updatedAccounts++;
                        }
                    } else {
                        // Find definition in template
                        $def = null;
                        foreach ($template as $item) {
                            if ($item['code'] === $code) {
                                $def = $item;
                                break;
                            }
                        }
                        if ($def) {
                            $insCoa->execute([
                                $societyId,
                                $def['code'],
                                $def['name'],
                                $def['type'],
                                $def['balance'] ?? 0.00
                            ]);
                            $createdAccounts++;
                        }
                    }
                }

                // 2. Process unselected accounts: soft-delete only if 0 transactions in journal_items
                foreach ($existingRows as $r) {
                    if (!in_array($r['account_code'], $activeCodes)) {
                        if ((int)$r['is_deleted'] === 0) {
                            $txCheck->execute([(int)$r['id']]);
                            $hasTx = (int)$txCheck->fetchColumn() > 0;
                            if (!$hasTx) {
                                $softDelStmt->execute([(int)$r['id']]);
                                $updatedAccounts++;
                            }
                        }
                    }
                }
            } else {
                // Brand new society with 0 accounts: insert only selected accounts
                $insCoa = $this->db->prepare("INSERT INTO chart_of_accounts (society_id, account_code, account_name, account_type, is_system_account, is_active, balance) VALUES (?, ?, ?, ?, 1, 1, ?)");
                foreach ($template as $item) {
                    if (in_array($item['code'], $activeCodes)) {
                        $insCoa->execute([
                            $societyId,
                            $item['code'],
                            $item['name'],
                            $item['type'],
                            $item['balance'] ?? 0.00
                        ]);
                        $createdAccounts++;
                    }
                }
            }

            if ($manageTx) $this->db->commit();

            return [
                'preset_applied' => $presetType,
                'created_accounts' => $createdAccounts,
                'updated_accounts' => $updatedAccounts,
                'active_accounts_count' => count($activeCodes),
                'status' => $this->getSetupStatus($societyId)
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Step 4: Configure Maintenance Charge Master & Billing Rules
     * Supports both dynamic multiple charge heads and standard single-form submission without bogus duplicates.
     */
    public function saveChargeRules(int $societyId, array $input): array {
        $billingCycle = $input['billing_cycle'] ?? 'Monthly';
        $manageTx = !$this->db->inTransaction();
        if ($manageTx) $this->db->beginTransaction();

        try {
            // Find GL Account IDs
            $findGl = $this->db->prepare("SELECT id FROM chart_of_accounts WHERE society_id = ? AND account_code = ? AND is_deleted = 0 LIMIT 1");

            $getGlId = function(string $code) use ($findGl, $societyId): ?int {
                $findGl->execute([$societyId, $code]);
                $row = $findGl->fetch();
                return $row ? (int)$row['id'] : null;
            };

            $upsertRule = function(string $canonicalName, array $aliases, string $calcType, float $rate, ?int $glId, string $desc) use ($societyId, $billingCycle) {
                // Build placeholder list for name and all aliases
                $allNames = array_unique(array_merge([$canonicalName], $aliases));
                $placeholders = implode(',', array_fill(0, count($allNames), '?'));
                $params = array_merge([$societyId], $allNames);

                $checkStmt = $this->db->prepare("SELECT id FROM charge_masters WHERE society_id = ? AND charge_name IN ($placeholders) AND is_deleted = 0 LIMIT 1");
                $checkStmt->execute($params);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    $upd = $this->db->prepare("UPDATE charge_masters SET charge_name = ?, calculation_type = ?, rate_amount = ?, gl_account_id = ?, billing_cycle = ?, description = ? WHERE id = ?");
                    $upd->execute([$canonicalName, $calcType, $rate, $glId, $billingCycle, $desc, (int)$existing['id']]);
                } else {
                    $ins = $this->db->prepare("INSERT INTO charge_masters (society_id, charge_name, calculation_type, rate_amount, gl_account_id, is_recurring, billing_cycle, description) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
                    $ins->execute([$societyId, $canonicalName, $calcType, $rate, $glId, $billingCycle, $desc]);
                }
            };

            // Case A: Custom charges list passed directly from in-table editor
            if (!empty($input['charges']) && is_array($input['charges'])) {
                // Fetch all existing active rules for this society
                $curRulesStmt = $this->db->prepare("SELECT id, charge_name FROM charge_masters WHERE society_id = ? AND is_deleted = 0");
                $curRulesStmt->execute([$societyId]);
                $curRules = $curRulesStmt->fetchAll(\PDO::FETCH_ASSOC);

                $keptIds = [];

                foreach ($input['charges'] as $c) {
                    $cName = trim($c['charge_name'] ?? '');
                    if (empty($cName)) continue;
                    $cType = in_array($c['calculation_type'] ?? '', ['SqFt', 'Fixed']) ? $c['calculation_type'] : 'Fixed';
                    $cRate = (float)($c['rate_amount'] ?? 0);
                    $cCycle = !empty($c['billing_cycle']) ? $c['billing_cycle'] : $billingCycle;
                    $cDesc = $c['description'] ?? "Charge for $cName";

                    // Auto-detect double-entry GL Account code
                    $cGlCode = $c['gl_account_code'] ?? null;
                    if (!$cGlCode) {
                        $lower = strtolower($cName);
                        if (str_contains($lower, 'sinking')) $cGlCode = '4020';
                        elseif (str_contains($lower, 'repair')) $cGlCode = '4025';
                        elseif (str_contains($lower, 'water')) $cGlCode = '4040';
                        elseif (str_contains($lower, 'club') || str_contains($lower, 'amenity')) $cGlCode = '4050';
                        elseif (str_contains($lower, 'interest') || str_contains($lower, 'late')) $cGlCode = '4030';
                        else $cGlCode = '4010';
                    }
                    $cGlId = $getGlId($cGlCode) ?: $getGlId('4010');

                    $ruleId = !empty($c['id']) ? (int)$c['id'] : null;

                    // If existing ID provided, update it
                    if ($ruleId) {
                        $updStmt = $this->db->prepare("UPDATE charge_masters SET charge_name = ?, calculation_type = ?, rate_amount = ?, gl_account_id = ?, billing_cycle = ?, description = ?, is_deleted = 0 WHERE id = ? AND society_id = ?");
                        $updStmt->execute([$cName, $cType, $cRate, $cGlId, $cCycle, $cDesc, $ruleId, $societyId]);
                        $keptIds[] = $ruleId;
                    } else {
                        // Check if rule with this name exists for society
                        $checkStmt = $this->db->prepare("SELECT id FROM charge_masters WHERE society_id = ? AND charge_name = ? AND is_deleted = 0 LIMIT 1");
                        $checkStmt->execute([$societyId, $cName]);
                        $existing = $checkStmt->fetch();
                        if ($existing) {
                            $exId = (int)$existing['id'];
                            $updStmt = $this->db->prepare("UPDATE charge_masters SET calculation_type = ?, rate_amount = ?, gl_account_id = ?, billing_cycle = ?, description = ? WHERE id = ?");
                            $updStmt->execute([$cType, $cRate, $cGlId, $cCycle, $cDesc, $exId]);
                            $keptIds[] = $exId;
                        } else {
                            $insStmt = $this->db->prepare("INSERT INTO charge_masters (society_id, charge_name, calculation_type, rate_amount, gl_account_id, is_recurring, billing_cycle, description) VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
                            $insStmt->execute([$societyId, $cName, $cType, $cRate, $cGlId, $cCycle, $cDesc]);
                            $keptIds[] = (int)$this->db->lastInsertId();
                        }
                    }
                }

                // Soft-delete any rules that were removed from the table (Rule #4 Soft Delete)
                if (!empty($keptIds)) {
                    $delStmt = $this->db->prepare("UPDATE charge_masters SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
                    foreach ($curRules as $cr) {
                        if (!in_array((int)$cr['id'], $keptIds)) {
                            $delStmt->execute([(int)$cr['id']]);
                        }
                    }
                }
            } else {
                // Case B: Standard Housing Society wizard inputs
                $calcType = $input['calculation_type'] ?? 'Fixed';
                $rateAmount = (float)($input['rate_amount'] ?? ($calcType === 'SqFt' ? 2.50 : 3500.00));
                $sinkingFundAmount = (float)($input['sinking_fund_amount'] ?? 500.00);
                $repairFundAmount = (float)($input['repair_fund_amount'] ?? 0.00);
                $waterAmount = (float)($input['water_charges_amount'] ?? 0.00);

                // 1. Primary Maintenance
                $maintGl = $getGlId('4010');
                $upsertRule('General Maintenance', ['Monthly Society Maintenance', 'Maintenance Charges'], $calcType, $rateAmount, $maintGl, 'Standard society periodic maintenance charge');

                // 2. Statutory Sinking Fund Contribution
                if ($sinkingFundAmount > 0) {
                    $sinkGl = $getGlId('4020');
                    $upsertRule('Sinking Fund Contribution', ['Sinking Fund Reserve Contribution', 'Sinking Fund'], 'Fixed', $sinkingFundAmount, $sinkGl, 'Mandatory statutory sinking fund contribution (Model Bye-laws)');
                }

                // 3. Statutory Major Repair & Renovation Fund
                if ($repairFundAmount > 0) {
                    $repairGl = $getGlId('4025') ?: $getGlId('4010');
                    $upsertRule('Major Repair Fund Contribution', ['Repair & Maintenance Fund', 'Repair Fund'], 'Fixed', $repairFundAmount, $repairGl, 'Mandatory statutory major repair fund (Model Bye-laws)');
                }

                // 4. Water Charges
                if ($waterAmount > 0) {
                    $waterGl = $getGlId('4040');
                    $upsertRule('Water Supply Charges', ['Water Charges'], 'Fixed', $waterAmount, $waterGl, 'Periodic domestic water utility charge');
                }
            }

            if ($manageTx) $this->db->commit();

            return [
                'message' => 'Charge master rules configured successfully.',
                'status' => $this->getSetupStatus($societyId)
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Step 5: Configure Society SMTP Mail Gateway
     */
    public function saveSmtpStep(int $societyId, array $input): array {
        $mailService = new MailService($this->db);
        $saved = $mailService->saveSmtpConfig($societyId, $input);
        return [
            'smtp' => $saved,
            'message' => 'SMTP mail gateway credentials saved successfully.',
            'status' => $this->getSetupStatus($societyId)
        ];
    }

    /**
     * Step 5: Skip SMTP Setup (Setup Later)
     */
    public function skipSmtpStep(int $societyId): array {
        return [
            'skipped' => true,
            'message' => 'SMTP configuration deferred. You can set this up anytime from the Email & SMTP settings.',
            'status' => $this->getSetupStatus($societyId)
        ];
    }

    /**
     * Step 6: Create Primary Society Administrator & Gate Guard PIN
     */
    public function createAdminUser(int $societyId, array $input): array {
        $fullName = trim($input['admin_name'] ?? 'Society Administrator');
        $email = strtolower(trim($input['admin_email'] ?? 'admin@society.com'));
        $password = trim($input['admin_password'] ?? 'Admin@123');
        $phone = trim($input['admin_phone'] ?? '+91 98200 11223');

        if (empty($email)) {
            throw new InvalidArgumentException("Admin email is required.");
        }

        $manageTx = !$this->db->inTransaction();
        if ($manageTx) $this->db->beginTransaction();

        try {
            // Find Estate Director or Super Admin Role
            $roleStmt = $this->db->prepare("SELECT id FROM roles WHERE role_code IN ('estate_director', 'super_admin') AND is_deleted = 0 ORDER BY id ASC LIMIT 1");
            $roleStmt->execute();
            $roleRow = $roleStmt->fetch();
            $roleId = $roleRow ? (int)$roleRow['id'] : 2;

            // Check if user exists
            $userCheck = $this->db->prepare("SELECT id FROM users WHERE email = ? AND is_deleted = 0 LIMIT 1");
            $userCheck->execute([$email]);
            $existingUser = $userCheck->fetch();

            if ($existingUser) {
                $updUser = $this->db->prepare("UPDATE users SET full_name = ?, phone = ?, role_id = ?, society_id = ?, password_hash = ? WHERE id = ?");
                $updUser->execute([
                    $fullName,
                    $phone,
                    $roleId,
                    $societyId,
                    password_hash($password, PASSWORD_BCRYPT),
                    (int)$existingUser['id']
                ]);
                $userId = (int)$existingUser['id'];
            } else {
                $userCode = 'ADM-' . rand(1000, 9999);
                $insUser = $this->db->prepare("INSERT INTO users (society_id, user_code, full_name, email, password_hash, role_id, phone, status, is_parent_user) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', 0)");
                $insUser->execute([
                    $societyId,
                    $userCode,
                    $fullName,
                    $email,
                    password_hash($password, PASSWORD_BCRYPT),
                    $roleId,
                    $phone
                ]);
                $userId = (int)$this->db->lastInsertId();
            }

            if ($manageTx) $this->db->commit();

            return [
                'user_id' => $userId,
                'email' => $email,
                'message' => 'Primary administrator provisioned successfully.',
                'status' => $this->getSetupStatus($societyId)
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Complete One-Click Automated Setup with Smart Defaults
     */
    public function autoSetupAll(int $societyId, array $config = []): array {
        $manageTx = !$this->db->inTransaction();
        if ($manageTx) $this->db->beginTransaction();

        try {
            // 1. Bank step defaults
            $socConfig = $config['society'] ?? [];
            if (empty($socConfig['name'])) {
                $socStmt = $this->db->prepare("SELECT name, city FROM societies WHERE id = ?");
                $socStmt->execute([$societyId]);
                $cur = $socStmt->fetch();
                $socConfig['name'] = $cur['name'] ?? 'Society Estate';
            }
            if (empty($socConfig['address']) && empty($socConfig['address_line1'])) {
                $socConfig['address'] = 'Main Estate Boulevard, Sector 1';
                $socConfig['address_line1'] = 'Main Estate Boulevard, Sector 1';
            }
            $this->saveSocietyBankStep($societyId, $socConfig);

            // 2. Towers & units defaults
            $this->generateTowersAndUnits($societyId, $config['towers'] ?? []);

            // 3. COA preset
            $this->applyCoaPreset($societyId, $config['coa_preset'] ?? 'residential');

            // 4. Charge rules
            $this->saveChargeRules($societyId, $config['charges'] ?? []);

            // 5. Admin user
            if (!empty($config['admin'])) {
                $this->createAdminUser($societyId, $config['admin']);
            }

            if ($manageTx) $this->db->commit();

            return [
                'success' => true,
                'message' => 'Complete society setup wizard executed successfully!',
                'status' => $this->getSetupStatus($societyId)
            ];
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
