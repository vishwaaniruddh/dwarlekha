<?php
/**
 * ============================================================================
 * DwarLekha Scheduled Cron Worker
 * Enterprise Scheduled Task Runner for Hostinger cPanel / hPanel
 * ============================================================================
 *
 * Supported CLI Modes:
 *   php backend/bin/cron.php --task=daily      (Overdue sync + visitor cleanup + DB backup)
 *   php backend/bin/cron.php --task=monthly    (Recurring monthly maintenance bill generation)
 *   php backend/bin/cron.php --task=all        (Daily + Monthly if 1st of month)
 *   php backend/bin/cron.php --task=backup     (Standalone Gzip DB backup & rotation)
 *   php backend/bin/cron.php --task=overdue    (Sync overdue invoices & unit statuses)
 *
 * Flags:
 *   --force        Force monthly bill generation even if today is not the 1st
 *   --dry-run      Simulate execution without modifying the database
 *   --society_id=X Target a specific society ID (defaults to all active societies)
 *
 * Web Hook Fallback (cURL / HTTP):
 *   curl https://dwarlekha.sarsspl.com/backend/bin/cron.php?token=dwarlekha_cron_secret_key&task=daily
 */

// Allow CLI or HTTP with secret token
$isCli = (php_sapi_name() === 'cli');
$cronSecret = getenv('CRON_SECRET') ?: 'dwarlekha_cron_secure_token_2026';

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $token = $_GET['token'] ?? '';
    if ($token !== $cronSecret) {
        http_response_code(403);
        echo "[ERROR 403] Unauthorized access to cron runner.\n";
        exit(1);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Models\Bill;
use App\Services\AuditService;
use App\Services\MailService;

// Parse Options
$options = [];
if ($isCli) {
    $rawOpts = getopt('', ['task::', 'force', 'dry-run', 'society_id::', 'send-emails']);
    $options['task'] = $rawOpts['task'] ?? 'all';
    $options['force'] = isset($rawOpts['force']);
    $options['dry_run'] = isset($rawOpts['dry-run']);
    $options['send_emails'] = isset($rawOpts['send-emails']);
    $options['society_id'] = isset($rawOpts['society_id']) ? (int)$rawOpts['society_id'] : null;
} else {
    $options['task'] = $_GET['task'] ?? 'all';
    $options['force'] = isset($_GET['force']);
    $options['dry_run'] = isset($_GET['dry_run']) || isset($_GET['dry-run']);
    $options['send_emails'] = isset($_GET['send_emails']) || isset($_GET['send-emails']);
    $options['society_id'] = !empty($_GET['society_id']) ? (int)$_GET['society_id'] : null;
}

$startTime = microtime(true);
$startMemory = memory_get_usage(true);

function cliLog(string $msg, string $type = 'INFO'): void {
    $time = date('Y-m-d H:i:s');
    $prefix = "[{$time}] [{$type}]";
    echo "{$prefix} {$msg}\n";
    if (ob_get_level() > 0) ob_flush();
}

cliLog("===============================================================");
cliLog("🚀 DwarLekha Scheduled Cron Worker Initialized");
cliLog("Mode: " . ($options['dry_run'] ? "DRY RUN (Simulation)" : "LIVE EXECUTION"));
cliLog("Task: " . strtoupper($options['task']));
cliLog("===============================================================");

$db = Database::getConnection();

// 1. Fetch Target Societies
$socQuery = "SELECT id, name, society_code FROM societies WHERE is_deleted = 0";
if ($options['society_id']) {
    $socQuery .= " AND id = " . (int)$options['society_id'];
}
$socQuery .= " ORDER BY id ASC";
$societies = $db->query($socQuery)->fetchAll(\PDO::FETCH_ASSOC);

if (empty($societies)) {
    cliLog("No active societies found to process.", 'WARNING');
    exit(0);
}

cliLog("Found " . count($societies) . " active society tenant(s) to process.");

$summary = [
    'societies_processed' => count($societies),
    'overdue_invoices_synced' => 0,
    'bills_generated' => 0,
    'bills_amount_generated' => 0.0,
    'visitors_checked_out' => 0,
    'backup_file' => null
];

$billModel = new Bill();

// ── TASK 1: SYNC OVERDUE INVOICES & UNIT STATUSES ──
if (in_array($options['task'], ['all', 'daily', 'overdue'])) {
    cliLog("--- [Task 1/4] Running Invoices & Maintenance Overdue Status Sync ---");
    foreach ($societies as $soc) {
        $socId = (int)$soc['id'];
        $socName = $soc['name'];

        if ($options['dry_run']) {
            $overdueCount = (int)$db->query("
                SELECT COUNT(*) FROM bills 
                WHERE society_id = {$socId} AND is_deleted = 0 
                AND status IN ('Unpaid', 'Partially Paid') 
                AND due_date < CURRENT_DATE
            ")->fetchColumn();
            cliLog("  • {$socName}: {$overdueCount} invoices past due date (Dry-run)");
        } else {
            $syncedCount = $billModel->syncOverdueStatus($socId);
            $billModel->syncUnitMaintenanceStatus($socId);
            $summary['overdue_invoices_synced'] += $syncedCount;
            cliLog("  • {$socName}: {$syncedCount} invoice(s) transitioned to 'Overdue'.");

            if ($options['send_emails'] && $syncedCount > 0) {
                $mailService = new MailService($db);
                $overdueBills = $db->query("SELECT id FROM bills WHERE society_id = {$socId} AND status = 'Overdue' AND is_deleted = 0")->fetchAll(\PDO::FETCH_COLUMN);
                $emailsSent = 0;
                foreach ($overdueBills as $bId) {
                    $billDetail = $billModel->findByIdWithItems((int)$bId);
                    if ($billDetail && !empty($billDetail['resident_email'])) {
                        $mRes = $mailService->sendOverdueReminder($socId, $billDetail);
                        if (!empty($mRes['success'])) $emailsSent++;
                    }
                }
                cliLog("    ↳ Dispatched {$emailsSent} overdue email reminder(s).");
                $summary['emails_sent'] = ($summary['emails_sent'] ?? 0) + $emailsSent;
            }
        }
    }
}

// ── TASK 2: VISITOR OVERSTAY CLEANUP ──
if (in_array($options['task'], ['all', 'daily', 'visitors'])) {
    cliLog("--- [Task 2/4] Running Visitor Overstay Detection & Auto-Exit ---");
    foreach ($societies as $soc) {
        $socId = (int)$soc['id'];
        $socName = $soc['name'];

        // Overstay rule: Inside for > 16 hours or entered before today
        $overstayStmt = $db->query("
            SELECT COUNT(*) FROM visitors 
            WHERE society_id = {$socId} AND is_deleted = 0 AND status = 'Inside' 
            AND check_in_time <= (NOW() - INTERVAL 16 HOUR)
        ");
        $overstayCount = (int)$overstayStmt->fetchColumn();

        if ($overstayCount > 0) {
            if ($options['dry_run']) {
                cliLog("  • {$socName}: {$overstayCount} visitor(s) exceed 16-hour stay (Dry-run).");
            } else {
                $upStmt = $db->prepare("
                    UPDATE visitors 
                    SET status = 'Exited', 
                        check_out_time = NOW() 
                    WHERE society_id = ? AND status = 'Inside' AND is_deleted = 0 
                    AND check_in_time <= (NOW() - INTERVAL 16 HOUR)
                ");
                $upStmt->execute([$socId]);
                $summary['visitors_checked_out'] += $overstayCount;
                cliLog("  • {$socName}: Auto-checked out {$overstayCount} overstayed visitor(s).");
            }
        } else {
            cliLog("  • {$socName}: No overstayed visitors detected.");
        }
    }
}

// ── TASK 3: MONTHLY RECURRING BILL GENERATION ──
// Auto-runs if today is the 1st of the month, or if explicitly requested via --task=monthly or --force
$isFirstDayOfMonth = (date('j') === '1');
$shouldRunMonthlyBills = in_array($options['task'], ['monthly']) || ($options['task'] === 'all' && $isFirstDayOfMonth) || $options['force'];

if ($shouldRunMonthlyBills) {
    cliLog("--- [Task 3/4] Running Monthly Recurring Maintenance Bill Generation ---");
    
    $periodStart = date('Y-m-01');
    $periodEnd = date('Y-m-t');
    $dueDate = date('Y-m-15', strtotime('+1 month'));

    foreach ($societies as $soc) {
        $socId = (int)$soc['id'];
        $socName = $soc['name'];

        // Check if society has active recurring charge rules
        $cmCount = (int)$db->query("
            SELECT COUNT(*) FROM charge_masters 
            WHERE society_id = {$socId} AND is_recurring = 1 AND is_deleted = 0
        ")->fetchColumn();

        if ($cmCount === 0) {
            cliLog("  • {$socName}: Skipped (No active recurring charge master rules configured).");
            continue;
        }

        // Check if current period bills were already generated
        $existingBills = (int)$db->query("
            SELECT COUNT(*) FROM bills 
            WHERE society_id = {$socId} AND billing_period_start = '{$periodStart}' 
            AND billing_period_end = '{$periodEnd}' AND is_deleted = 0
        ")->fetchColumn();

        if ($existingBills > 0 && !$options['force']) {
            cliLog("  • {$socName}: Already generated ({$existingBills} bills exist for {$periodStart} to {$periodEnd}).");
            continue;
        }

        if ($options['dry_run']) {
            cliLog("  • {$socName}: Would generate bills for {$periodStart} to {$periodEnd} (Dry-run).");
        } else {
            try {
                $res = $billModel->generateMonthlyBills($socId, $periodStart, $periodEnd, $dueDate, null);
                $summary['bills_generated'] += $res['count'];
                $summary['bills_amount_generated'] += $res['total_amount'];
                cliLog("  • {$socName}: Generated {$res['count']} bills (Total: ₹" . number_format($res['total_amount'], 2) . ").");

                if ($options['send_emails'] && !empty($res['bills'])) {
                    $mailService = new MailService($db);
                    $invEmailsSent = 0;
                    foreach ($res['bills'] as $b) {
                        $billDetail = $billModel->findByIdWithItems((int)$b['id']);
                        if ($billDetail && !empty($billDetail['resident_email'])) {
                            $mRes = $mailService->sendInvoiceNotification($socId, $billDetail);
                            if (!empty($mRes['success'])) $invEmailsSent++;
                        }
                    }
                    cliLog("    ↳ Dispatched {$invEmailsSent} invoice email notification(s).");
                    $summary['emails_sent'] = ($summary['emails_sent'] ?? 0) + $invEmailsSent;
                }
            } catch (\Throwable $e) {
                cliLog("  • {$socName}: Generation error: " . $e->getMessage(), 'ERROR');
            }
        }
    }
} else {
    cliLog("--- [Task 3/4] Monthly Billing skipped (Today is " . date('jS') . " of month; runs on 1st or with --force) ---");
}

// ── TASK 4: DATABASE BACKUP & ROTATION ──
if (in_array($options['task'], ['all', 'daily', 'backup'])) {
    cliLog("--- [Task 4/4] Running Automated Database Backup & Retention Rotation ---");
    $backupDir = __DIR__ . '/../backups/';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0750, true);
        @file_put_contents($backupDir . '.htaccess', "Deny from all\nOptions -Indexes\n");
    }

    $timestamp = date('Y-m-d_His');
    $backupSql = $backupDir . "db_backup_{$timestamp}.sql";
    $backupGz = $backupSql . ".gz";

    if ($options['dry_run']) {
        cliLog("  • Database backup simulated: {$backupGz} (Dry-run)");
    } else {
        try {
            $tables = [];
            $stmt = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $handle = fopen($backupSql, 'w');
            fwrite($handle, "-- DwarLekha Platform Database Backup\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($handle, "SET NAMES utf8mb4;\n\n");

            foreach ($tables as $table) {
                $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, ($createStmt['Create Table'] ?? '') . ";\n\n");

                $dataStmt = $db->query("SELECT * FROM `{$table}`");
                $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols = array_keys($rows[0]);
                    $colsStr = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
                    fwrite($handle, "INSERT INTO `{$table}` ({$colsStr}) VALUES\n");
                    $valLines = [];
                    foreach ($rows as $r) {
                        $escaped = array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($r));
                        $valLines[] = "(" . implode(', ', $escaped) . ")";
                    }
                    fwrite($handle, implode(",\n", $valLines) . ";\n\n");
                }
            }
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);

            // Gzip compress
            $gzHandle = gzopen($backupGz, 'w9');
            gzwrite($gzHandle, file_get_contents($backupSql));
            gzclose($gzHandle);
            @unlink($backupSql); // Delete raw SQL

            $fileSizeKb = round(filesize($backupGz) / 1024, 1);
            $summary['backup_file'] = basename($backupGz);
            cliLog("  • Backup created: " . basename($backupGz) . " ({$fileSizeKb} KB)");

            // Retention cleanup (delete backups older than 30 days)
            $retentionSeconds = 30 * 86400;
            $prunedCount = 0;
            foreach (glob($backupDir . "db_backup_*.sql.gz") as $oldFile) {
                if (filemtime($oldFile) < (time() - $retentionSeconds)) {
                    @unlink($oldFile);
                    $prunedCount++;
                }
            }
            if ($prunedCount > 0) {
                cliLog("  • Pruned {$prunedCount} backup(s) older than 30 days.");
            }
        } catch (\Throwable $e) {
            cliLog("  • Backup error: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Record Cron Audit Log
$durationMs = round((microtime(true) - $startTime) * 1000, 2);
$peakMemoryMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);

if (!$options['dry_run']) {
    AuditService::log(
        'system.cron_execution',
        'cron',
        null,
        array_merge($summary, [
            'task' => $options['task'],
            'duration_ms' => $durationMs,
            'peak_memory_mb' => $peakMemoryMb
        ]),
        1
    );
}

cliLog("===============================================================");
cliLog("✅ Cron Run Completed in {$durationMs}ms (Peak Memory: {$peakMemoryMb} MB)");
cliLog("Overdue Invoices Synced : " . $summary['overdue_invoices_synced']);
cliLog("Visitors Checked Out    : " . $summary['visitors_checked_out']);
cliLog("Monthly Bills Created   : " . $summary['bills_generated'] . " (₹" . number_format($summary['bills_amount_generated'], 2) . ")");
cliLog("Database Backup File    : " . ($summary['backup_file'] ?: 'None'));
cliLog("===============================================================");

exit(0);
