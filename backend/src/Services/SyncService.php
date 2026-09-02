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

    public function getStatus(): array {
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
        $totalExportedRows = 0;

        foreach ($ordered as $table) {
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
            'data' => $dump
        ];
    }

    public function importData(array $payload, string $mode = 'replace'): array {
        $data = $payload['data'] ?? $payload;
        if (!is_array($data) || empty($data)) {
            throw new Exception("Invalid or empty sync data payload provided.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            // Disable foreign key checks for clean bulk synchronization
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            $importedStats = [];
            $totalInserted = 0;

            // Import according to ordered tables
            $tablesToProcess = array_keys($data);
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

                // If mode is replace, truncate table
                if ($mode === 'replace') {
                    $db->exec("TRUNCATE TABLE `{$table}`");
                }

                $tableCount = 0;
                if (!empty($rows)) {
                    // Prepare batch / row insert
                    foreach ($rows as $row) {
                        if (!is_array($row) || empty($row)) continue;

                        $columns = array_keys($row);
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
                        $stmt->execute(array_values($row));
                        $tableCount++;
                        $totalInserted++;
                    }
                }

                $importedStats[$table] = $tableCount;
            }

            $db->exec("SET FOREIGN_KEY_CHECKS = 1");

            if ($manageTx) {
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

    public function pushToRemote(string $targetUrl, string $secretKey = ''): array {
        $export = $this->exportData();
        $remoteEndpoint = rtrim($targetUrl, '/') . '/index.php?route=sync/import';

        $ch = curl_init($remoteEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sync-Key: ' . ($secretKey ?: self::SYNC_SECRET)
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'data' => $export['data'],
                'mode' => 'replace',
                'secret_key' => $secretKey ?: self::SYNC_SECRET
            ]),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false
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
            throw new Exception("Remote Server Error ({$httpCode}): " . ($result['error'] ?? $response));
        }

        return $result;
    }

    public function pullFromRemote(string $sourceUrl, string $secretKey = ''): array {
        $remoteEndpoint = rtrim($sourceUrl, '/') . '/index.php?route=sync/export';

        $ch = curl_init($remoteEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Sync-Key: ' . ($secretKey ?: self::SYNC_SECRET)
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'secret_key' => $secretKey ?: self::SYNC_SECRET
            ]),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false
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
            throw new Exception("Remote Export Error ({$httpCode}): " . ($payload['error'] ?? $response));
        }

        // Import downloaded data locally
        return $this->importData($payload, 'replace');
    }
}
