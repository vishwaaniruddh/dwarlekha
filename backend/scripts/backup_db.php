<?php
/**
 * Automated Database Backup Routine for DwarLekha
 * Works on Hostinger Shared Hosting without requiring external mysqldump binaries
 * Usage via CLI / Cron: php backend/scripts/backup_db.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$backupDir = __DIR__ . '/../backups/';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0750, true);
    @file_put_contents($backupDir . '.htaccess', "Deny from all\nOptions -Indexes\n");
}

echo "======================================================\n";
echo "📦 DwarLekha Automated Database Backup Utility\n";
echo "======================================================\n";

try {
    $db = Database::getConnection();
    $timestamp = date('Y-m-d_His');
    $backupFile = $backupDir . "db_backup_{$timestamp}.sql";

    echo "1. Querying schema tables...\n";
    $tables = [];
    $stmt = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $handle = fopen($backupFile, 'w');
    if (!$handle) {
        throw new Exception("Unable to create backup file at {$backupFile}");
    }

    fwrite($handle, "-- DwarLekha Platform Database Backup\n");
    fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($handle, "SET NAMES utf8mb4;\n\n");

    foreach ($tables as $table) {
        echo "  Exporting table: {$table}...\n";
        
        // Structure
        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
        $createSql = $createStmt['Create Table'] ?? '';
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $createSql . ";\n\n");

        // Data
        $dataStmt = $db->query("SELECT * FROM `{$table}`");
        $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = array_keys($rows[0]);
            $quotedCols = array_map(fn($c) => "`{$c}`", $cols);
            $colsStr = implode(', ', $quotedCols);

            $chunks = array_chunk($rows, 200);
            foreach ($chunks as $chunk) {
                $valRows = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = $db->quote($val);
                        }
                    }
                    $valRows[] = '(' . implode(', ', $vals) . ')';
                }
                fwrite($handle, "INSERT INTO `{$table}` ({$colsStr}) VALUES\n" . implode(",\n", $valRows) . ";\n\n");
            }
        }
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    // Compress to .gz
    echo "2. Compressing SQL dump to gzip...\n";
    $gzFile = $backupFile . '.gz';
    $gz = gzopen($gzFile, 'w9');
    $fp = fopen($backupFile, 'r');
    while (!feof($fp)) {
        gzwrite($gz, fread($fp, 1024 * 512));
    }
    fclose($fp);
    gzclose($gz);
    @unlink($backupFile); // Remove uncompressed file

    $sizeKb = round(filesize($gzFile) / 1024, 2);
    echo "✓ Backup created successfully: " . basename($gzFile) . " ({$sizeKb} KB)\n";

    // Retention: Delete backups older than 7 days
    echo "3. Enforcing 7-day retention policy...\n";
    $retentionSecs = 7 * 86400;
    $now = time();
    $backupFiles = glob($backupDir . 'db_backup_*.sql.gz');
    $purged = 0;
    foreach ($backupFiles as $f) {
        if (($now - filemtime($f)) > $retentionSecs) {
            @unlink($f);
            $purged++;
        }
    }
    echo "✓ Retention check complete. Old backups purged: {$purged}\n";
    echo "======================================================\n";
    echo "All Done! 🎉\n";

} catch (\Throwable $e) {
    echo "❌ Backup Error: " . $e->getMessage() . "\n";
    exit(1);
}
