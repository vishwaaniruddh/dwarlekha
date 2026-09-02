<?php
/**
 * Migration: Add Soft Delete Columns to All Domain Tables
 * 
 * Adds `is_deleted` TINYINT(1) DEFAULT 0 and `deleted_at` TIMESTAMP NULL
 * to every domain table. Idempotent — safe to run multiple times.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$db = Database::getConnection();

$tables = [
    'societies',
    'towers',
    'unit_types',
    'units',
    'roles',
    'permissions',
    'users',
    'residents',
    'family_members',
    'resident_documents',
    'vehicles',
    'unit_occupancies',
    'visitors',
    'invoices',
    'complaints',
    'amenities',
    'facility_bookings',
    'notices',
    'audit_logs',
    'countries',
    'zones',
    'states',
    'cities',
];

echo "=== Soft Delete Migration ===\n\n";

foreach ($tables as $table) {
    try {
        // Check if column already exists
        $check = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'is_deleted'");
        $check->execute([$table]);
        $exists = (int) $check->fetchColumn();

        if ($exists) {
            echo "  [SKIP] {$table} — is_deleted already exists\n";
            continue;
        }

        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL");
        echo "  [OK]   {$table} — added is_deleted + deleted_at\n";
    } catch (\Throwable $e) {
        echo "  [ERR]  {$table} — " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration Complete ===\n";
