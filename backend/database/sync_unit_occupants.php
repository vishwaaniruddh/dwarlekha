<?php
/**
 * Sync units table with existing residents table data
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$db = Database::getConnection();

echo "=== Syncing Unit Owners & Tenants from Residents ===\n";

$units = $db->query("SELECT id, society_id, unit_code, owner_name, tenant_name FROM units WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);

foreach ($units as $u) {
    $stmt = $db->prepare("
        SELECT r.resident_type, usr.full_name, usr.phone, usr.email 
        FROM residents r 
        JOIN users usr ON r.user_id = usr.id 
        WHERE r.unit_id = ? AND r.is_deleted = 0
        ORDER BY r.id DESC
    ");
    $stmt->execute([$u['id']]);
    $resList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ownerName = null;
    $tenantName = null;
    $phone = null;
    $email = null;
    $hasOwner = false;
    $hasTenant = false;

    foreach ($resList as $r) {
        if ($r['resident_type'] === 'Owner') {
            $hasOwner = true;
            if (!$ownerName) {
                $ownerName = $r['full_name'];
                $phone = $r['phone'];
                $email = $r['email'];
            }
        }
        if ($r['resident_type'] === 'Tenant') {
            $hasTenant = true;
            if (!$tenantName) {
                $tenantName = $r['full_name'];
            }
        }
    }

    if ($hasOwner || $hasTenant) {
        $status = $hasTenant ? 'Occupied (Tenant)' : ($hasOwner ? 'Occupied (Owner)' : 'Vacant');
        $upd = $db->prepare("UPDATE units SET occupancy_status = ?, owner_name = ?, tenant_name = ?, contact_phone = ?, contact_email = ? WHERE id = ?");
        $upd->execute([$status, $ownerName, $tenantName, $phone, $email, $u['id']]);
        echo "  [SYNCED] Unit {$u['unit_code']} => Owner: {$ownerName}, Tenant: {$tenantName}, Status: {$status}\n";
    }
}

echo "=== Sync Completed ===\n";
