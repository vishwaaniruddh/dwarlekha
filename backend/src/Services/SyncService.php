<?php
namespace App\Services;

use App\Config\Database;
use Exception;
use PDO;

class SyncService {
    private const SYNC_SECRET = 'DwarLekha@Sync2026';

    // Canonical list of tables in dependency order
    private const ORDERED_TABLES = [
        'societies',
        'towers',
        'floors',
        'unit_types',
        'units',
        'roles',
        'permissions',
        'role_permissions',
        'users',
        'residents',
        'unit_occupancies',
        'family_members',
        'resident_documents',
        'vehicles',
        'visitors',
        'chart_of_accounts',
        'charge_masters',
        'invoices',
        'invoice_items',
        'payments',
        'expenses',
        'journal_entries',
        'journal_entry_lines',
        'helpdesk_tickets',
        'ticket_replies',
        'amenities',
        'amenity_bookings',
        'notices',
        'notifications',
        'audit_logs',
        'vendors'
    ];

    public function ensureEssentialTablesExist(): void {
        try {
            $db = Database::getConnection();
            $db->exec("
                CREATE TABLE IF NOT EXISTS `smtp_configs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `society_id` int(11) NOT NULL,
                    `sender_name` varchar(150) NOT NULL DEFAULT 'Society Management Office',
                    `sender_email` varchar(150) NOT NULL,
                    `reply_to_email` varchar(150) DEFAULT NULL,
                    `smtp_host` varchar(255) NOT NULL,
                    `smtp_port` int(11) NOT NULL DEFAULT 587,
                    `smtp_encryption` enum('tls','ssl','none') NOT NULL DEFAULT 'tls',
                    `smtp_username` varchar(255) NOT NULL,
                    `smtp_password` text NOT NULL,
                    `is_active` tinyint(1) NOT NULL DEFAULT 1,
                    `last_tested_at` timestamp NULL DEFAULT NULL,
                    `last_test_status` enum('pending','success','failed') NOT NULL DEFAULT 'pending',
                    `last_test_error` text DEFAULT NULL,
                    `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
                    `deleted_at` timestamp NULL DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_society_smtp` (`society_id`,`is_deleted`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $db->exec("
                CREATE TABLE IF NOT EXISTS `email_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `society_id` int(11) NOT NULL,
                    `recipient_email` varchar(255) NOT NULL,
                    `recipient_name` varchar(150) DEFAULT NULL,
                    `subject` varchar(255) NOT NULL,
                    `body_html` longtext DEFAULT NULL,
                    `body_text` text DEFAULT NULL,
                    `status` enum('sent','failed','queued') NOT NULL DEFAULT 'sent',
                    `error_message` text DEFAULT NULL,
                    `sent_at` timestamp NULL DEFAULT NULL,
                    `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
                    `deleted_at` timestamp NULL DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_email_society` (`society_id`,`is_deleted`),
                    KEY `idx_email_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Auto-add legal_name to societies table if missing
            try {
                $db->exec("ALTER TABLE `societies` ADD COLUMN `legal_name` VARCHAR(150) NULL AFTER `name`");
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) {}
    }

    public function getStatus(): array {
        $this->ensureEssentialTablesExist();
        $db = Database::getConnection();
        $isLocal = (
            php_sapi_name() === 'cli' && (PHP_OS_FAMILY === 'Windows')
        ) || (
            !empty($_SERVER['HTTP_HOST']) && (
                str_contains($_SERVER['HTTP_HOST'], 'localhost') || 
                str_contains($_SERVER['HTTP_HOST'], '127.0.0.1')
            )
        );

        $tablesStatus = [];
        $totalRecords = 0;

        // Get all tables in current database
        $stmt = $db->query("SHOW TABLES");
        $allDbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($allDbTables as $tbl) {
            try {
                $countStmt = $db->query("SELECT COUNT(*) FROM `{$tbl}`");
                $count = (int)$countStmt->fetchColumn();
                $totalRecords += $count;

                // Check soft-deleted count if column exists
                $activeCount = $count;
                try {
                    $activeStmt = $db->query("SELECT COUNT(*) FROM `{$tbl}` WHERE is_deleted = 0");
                    $activeCount = (int)$activeStmt->fetchColumn();
                } catch (\Throwable $ignored) {}

                $tablesStatus[] = [
                    'name' => $tbl,
                    'total_count' => $count,
                    'active_count' => $activeCount,
                    'is_core' => in_array($tbl, self::ORDERED_TABLES)
                ];
            } catch (\Throwable $e) {
                $tablesStatus[] = [
                    'name' => $tbl,
                    'total_count' => 0,
                    'active_count' => 0,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'environment' => $isLocal ? 'Local Development' : 'Live Production Server',
            'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'database' => $db->query("SELECT DATABASE()")->fetchColumn() ?: 'unknown',
            'server_time' => date('Y-m-d H:i:s'),
            'total_tables' => count($tablesStatus),
            'total_records' => $totalRecords,
            'tables' => $tablesStatus,
            'production_url' => 'http://dwarlekha.sarsspl.com/backend/public'
        ];
    }

    public function exportData(array $selectedTables = []): array {
        $db = Database::getConnection();
        $stmt = $db->query("SHOW TABLES");
        $allDbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $tablesToExport = !empty($selectedTables) 
            ? array_intersect($selectedTables, $allDbTables) 
            : $allDbTables;

        // Order tables safely
        $ordered = [];
        foreach (self::ORDERED_TABLES as $t) {
            if (in_array($t, $tablesToExport)) {
                $ordered[] = $t;
            }
        }
        foreach ($tablesToExport as $t) {
            if (!in_array($t, $ordered)) {
                $ordered[] = $t;
            }
        }

        $dump = [];
        $schemas = [];
        $totalExportedRows = 0;

        foreach ($ordered as $table) {
            try {
                $createStmt = $db->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $createStmt->fetch(PDO::FETCH_NUM);
                if (!empty($createRow[1])) {
                    $schemas[$table] = $createRow[1];
                }
            } catch (\Throwable $e) {}

            $rowsStmt = $db->query("SELECT * FROM `{$table}`");
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
            $dump[$table] = $rows;
            $totalExportedRows += count($rows);
        }

        return [
            'version' => '1.0',
            'exported_at' => date('Y-m-d H:i:s'),
            'source_host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'total_tables' => count($dump),
            'total_rows' => $totalExportedRows,
            'schemas' => $schemas,
            'data' => $dump
        ];
    }

    public function importData(array $payload, string $mode = 'replace'): array {
        // 1. Unpack nested payload wrappers if present
        $data = $payload;
        $schemas = $payload['schemas'] ?? [];
        if (isset($data['data']) && is_array($data['data'])) {
            if (isset($data['schemas']) && is_array($data['schemas'])) {
                $schemas = array_merge($schemas, $data['schemas']);
            }
            $data = $data['data'];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        // 2. Remove any top-level metadata keys
        $metadataKeys = ['version', 'exported_at', 'source_host', 'total_tables', 'total_rows', 'success', 'error', 'message', 'schemas'];
        foreach ($metadataKeys as $k) {
            unset($data[$k]);
        }

        if (!is_array($data) || empty($data)) {
            throw new Exception("Invalid or empty sync data payload provided.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            // Get actual existing tables in target database
            $this->ensureEssentialTablesExist();

            // Disable foreign key checks for clean bulk synchronization & table creation
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            // Auto-create any missing tables if DDL schema was provided in payload
            if (!empty($schemas) && is_array($schemas)) {
                foreach ($schemas as $tblName => $ddl) {
                    if (!in_array($tblName, $existingDbTables) && !empty($ddl)) {
                        try {
                            $db->exec($ddl);
                            $existingDbTables[] = $tblName;
                        } catch (\Throwable $ignored) {
                            try {
                                $cleanDdl = preg_replace('/AUTO_INCREMENT=\d+/i', '', $ddl);
                                $db->exec($cleanDdl);
                                $existingDbTables[] = $tblName;
                            } catch (\Throwable $e2) {}
                        }
                    }
                }
            }

            $importedStats = [];
            $totalInserted = 0;

            // Import according to ordered tables
            $tablesToProcess = array_intersect(array_keys($data), $existingDbTables);
            $ordered = [];
            foreach (self::ORDERED_TABLES as $t) {
                if (in_array($t, $tablesToProcess)) {
                    $ordered[] = $t;
                }
            }
            foreach ($tablesToProcess as $t) {
                if (!in_array($t, $ordered)) {
                    $ordered[] = $t;
                }
            }

            foreach ($ordered as $table) {
                $rows = $data[$table] ?? [];
                if (!is_array($rows)) continue;

                // Query existing columns in target database to ensure schema compatibility
                $colStmt = $db->query("SHOW COLUMNS FROM `{$table}`");
                $targetColumns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                $targetColMap = array_flip($targetColumns);

                // If mode is replace, delete all rows safely within transaction
                if ($mode === 'replace') {
                    $db->exec("DELETE FROM `{$table}`");
                }

                $tableCount = 0;
                if (!empty($rows)) {
                    // Prepare batch / row insert
                    foreach ($rows as $row) {
                        if (!is_array($row) || empty($row)) continue;

                        // Only insert columns that exist on the target table
                        $filteredRow = array_intersect_key($row, $targetColMap);
                        if (empty($filteredRow)) continue;

                        $columns = array_keys($filteredRow);
                        $colList = implode('`, `', $columns);
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                        $updates = [];
                        foreach ($columns as $col) {
                            $updates[] = "`{$col}` = VALUES(`{$col}`)";
                        }
                        $updateStr = implode(', ', $updates);

                        $sql = "INSERT INTO `{$table}` (`{$colList}`) VALUES ({$placeholders}) 
                                ON DUPLICATE KEY UPDATE {$updateStr}";

                        $stmt = $db->prepare($sql);
                        $stmt->execute(array_values($filteredRow));
                        $tableCount++;
                        $totalInserted++;
                    }
                }

                $importedStats[$table] = $tableCount;
            }

            $db->exec("SET FOREIGN_KEY_CHECKS = 1");

            if ($manageTx && $db->inTransaction()) {
                $db->commit();
            }

            return [
                'success' => true,
                'imported_tables' => count($importedStats),
                'total_rows_synced' => $totalInserted,
                'details' => $importedStats,
                'synced_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Throwable $e) {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception("Data sync import failed: " . $e->getMessage());
        }
    }

    public function pushToRemote(string $targetUrl, string $secretKey = '', array $tables = [], string $mode = 'replace'): array {
        // Auto-upgrade to HTTPS for live domain
        $targetUrl = preg_replace('/^http:\/\/(dwarlekha\.sarsspl\.com)/i', 'https://$1', trim($targetUrl));
        $export = $this->exportData($tables);
        $remoteEndpoint = rtrim($targetUrl, '/') . '/index.php?route=sync/import';

        $ch = curl_init($remoteEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_POSTREDIR => 3, // Keep POST payload across 301/302/307 redirects
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sync-Key: ' . ($secretKey ?: self::SYNC_SECRET)
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'data' => $export['data'],
                'schemas' => $export['schemas'] ?? [],
                'mode' => $mode,
                'secret_key' => $secretKey ?: self::SYNC_SECRET
            ]),
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL Network Error: " . $curlError);
        }

        $result = json_decode($response, true);
        if ($httpCode !== 200 || empty($result['success'])) {
            throw new Exception("Remote Server Error ({$httpCode}): " . ($result['error'] ?? substr(strip_tags($response), 0, 300)));
        }

        return $result;
    }

    public function pullFromRemote(string $sourceUrl, string $secretKey = '', array $tables = [], string $mode = 'replace'): array {
        // Auto-upgrade to HTTPS for live domain
        $sourceUrl = preg_replace('/^http:\/\/(dwarlekha\.sarsspl\.com)/i', 'https://$1', trim($sourceUrl));
        $remoteEndpoint = rtrim($sourceUrl, '/') . '/index.php?route=sync/export';

        $ch = curl_init($remoteEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_POSTREDIR => 3, // Keep POST payload across 301/302/307 redirects
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sync-Key: ' . ($secretKey ?: self::SYNC_SECRET)
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'secret_key' => $secretKey ?: self::SYNC_SECRET,
                'tables' => $tables
            ]),
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL Network Error: " . $curlError);
        }

        $payload = json_decode($response, true);
        if ($httpCode !== 200 || empty($payload['data'])) {
            throw new Exception("Remote Export Error ({$httpCode}): " . ($payload['error'] ?? substr(strip_tags($response), 0, 300)));
        }

        // Import downloaded data locally
        return $this->importData($payload, $mode);
    }

    public function getComparison(string $remoteUrl, string $secretKey = ''): array {
        $localStatus = $this->getStatus();
        $localTables = [];
        foreach ($localStatus['tables'] as $t) {
            $localTables[$t['name']] = $t;
        }

        // Auto-upgrade to HTTPS for live domain
        $remoteUrl = preg_replace('/^http:\/\/(dwarlekha\.sarsspl\.com)/i', 'https://$1', trim($remoteUrl));
        $remoteEndpoint = rtrim($remoteUrl, '/') . '/index.php?route=sync/status';

        $ch = curl_init($remoteEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Sync-Key: ' . ($secretKey ?: self::SYNC_SECRET)
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("Unable to reach remote server: " . $curlError);
        }

        $remoteData = json_decode($response, true);
        if ($httpCode !== 200 || empty($remoteData['success']) || empty($remoteData['data']['tables'])) {
            throw new Exception("Failed to fetch remote server status ({$httpCode}): " . ($remoteData['error'] ?? substr(strip_tags($response), 0, 300)));
        }

        $remoteTables = [];
        foreach ($remoteData['data']['tables'] as $t) {
            $remoteTables[$t['name']] = $t;
        }

        // All table names union
        $allTableNames = array_unique(array_merge(array_keys($localTables), array_keys($remoteTables)));

        // Preserve order based on ORDERED_TABLES first, then others
        $ordered = [];
        foreach (self::ORDERED_TABLES as $t) {
            if (in_array($t, $allTableNames)) {
                $ordered[] = $t;
            }
        }
        foreach ($allTableNames as $t) {
            if (!in_array($t, $ordered)) {
                $ordered[] = $t;
            }
        }

        $comparison = [];
        $inSyncCount = 0;
        $mismatchCount = 0;
        $missingOnRemoteCount = 0;
        $missingOnLocalCount = 0;

        foreach ($ordered as $tbl) {
            $hasLocal = isset($localTables[$tbl]);
            $hasRemote = isset($remoteTables[$tbl]);

            $localCount = $hasLocal ? (int)$localTables[$tbl]['total_count'] : 0;
            $remoteCount = $hasRemote ? (int)$remoteTables[$tbl]['total_count'] : 0;
            $localActive = $hasLocal ? (int)$localTables[$tbl]['active_count'] : 0;
            $remoteActive = $hasRemote ? (int)$remoteTables[$tbl]['active_count'] : 0;

            if (!$hasRemote) {
                $status = 'missing_on_remote';
                $missingOnRemoteCount++;
            } elseif (!$hasLocal) {
                $status = 'missing_on_local';
                $missingOnLocalCount++;
            } elseif ($localCount !== $remoteCount) {
                $status = 'count_mismatch';
                $mismatchCount++;
            } else {
                $status = 'in_sync';
                $inSyncCount++;
            }

            $comparison[] = [
                'name' => $tbl,
                'table' => $tbl,
                'status' => $status,
                'has_local' => $hasLocal,
                'has_remote' => $hasRemote,
                'local_count' => $localCount,
                'remote_count' => $remoteCount,
                'local_active' => $localActive,
                'remote_active' => $remoteActive,
                'diff' => $localCount - $remoteCount,
                'is_core' => in_array($tbl, self::ORDERED_TABLES)
            ];
        }

        return [
            'summary' => [
                'total_unique_tables' => count($ordered),
                'in_sync' => $inSyncCount,
                'count_mismatch' => $mismatchCount,
                'missing_on_remote' => $missingOnRemoteCount,
                'missing_on_local' => $missingOnLocalCount,
                'local_total_records' => $localStatus['total_records'] ?? 0,
                'remote_total_records' => $remoteData['data']['total_records'] ?? 0,
                'local_tables_count' => count($localTables),
                'remote_tables_count' => count($remoteTables),
                'remote_host' => $remoteData['data']['host'] ?? '',
                'remote_database' => $remoteData['data']['database'] ?? '',
                'remote_environment' => $remoteData['data']['environment'] ?? '',
                'remote_time' => $remoteData['data']['server_time'] ?? ''
            ],
            'tables' => $comparison
        ];
    }

    public function getTableSchema(string $table): string {
        if ($table === 'all' || $table === 'all_missing' || $table === 'missing') {
            return $this->getMissingSql();
        }
        $db = Database::getConnection();
        $stmt = $db->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
        return $row[1] ?? '';
    }

    public function getMissingSql(): string {
        $db = Database::getConnection();
        $sql = "-- ======================================================\n";
        $sql .= "-- DwarLekha Missing Tables & Columns Migration Script\n";
        $sql .= "-- Execute in Hostinger phpMyAdmin (u444388293_dwarlekha)\n";
        $sql .= "-- ======================================================\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        // 1. smtp_configs
        try {
            $stmt = $db->query("SHOW CREATE TABLE `smtp_configs`");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
            if (!empty($row[1])) {
                $createSql = preg_replace('/CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $row[1], 1);
                $sql .= "-- 1. Table: smtp_configs\n" . $createSql . ";\n\n";
            }
        } catch (\Throwable $e) {}

        // 2. email_logs
        try {
            $stmt = $db->query("SHOW CREATE TABLE `email_logs`");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
            if (!empty($row[1])) {
                $createSql = preg_replace('/CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $row[1], 1);
                $sql .= "-- 2. Table: email_logs\n" . $createSql . ";\n\n";
            }
        } catch (\Throwable $e) {}

        // 3. societies missing columns
        $sql .= "-- 3. Columns: Add missing columns to societies table\n";
        $sql .= "ALTER TABLE `societies`\n";
        $sql .= "  ADD COLUMN IF NOT EXISTS `legal_name` varchar(255) DEFAULT NULL AFTER `name`,\n";
        $sql .= "  ADD COLUMN IF NOT EXISTS `bank_name` varchar(150) DEFAULT NULL AFTER `logo_url`,\n";
        $sql .= "  ADD COLUMN IF NOT EXISTS `bank_account_no` varchar(50) DEFAULT NULL AFTER `bank_name`,\n";
        $sql .= "  ADD COLUMN IF NOT EXISTS `bank_ifsc` varchar(20) DEFAULT NULL AFTER `bank_account_no`,\n";
        $sql .= "  ADD COLUMN IF NOT EXISTS `upi_vpa` varchar(100) DEFAULT NULL AFTER `bank_ifsc`;\n\n";

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return $sql;
    }
}

