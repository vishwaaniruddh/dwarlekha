<?php
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/PushNotificationService.php';

use App\Config\Database;
use App\Services\PushNotificationService;

$db = Database::getConnection();

// 1. Truncate / Clear tables
$db->exec("TRUNCATE TABLE visitors");
$db->exec("TRUNCATE TABLE push_notifications_log");
echo "Truncated visitors and push_notifications_log tables successfully.\n";

// 2. Find unit_id for B1-2001
$unitId = 559;
$societyId = 3;
$residentName = 'Prasenjit Berra';
$residentType = 'Owner';

try {
    $stmt = $db->query("SELECT id, society_id FROM units WHERE unit_code LIKE '%B1-2001%' OR unit_code LIKE '%2001%' LIMIT 1");
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $unitId = (int)$u['id'];
        $societyId = (int)$u['society_id'];
    }
} catch (Throwable $e) {}

// 3. Insert fresh Gatepass for B1-2001
$passCode = 'PASS-' . rand(100000, 999999);
$visitorCode = 'VIS-' . rand(100000, 999999);
$createdAt = date('Y-m-d H:i:s');
$checkInTime = date('h:i A');

$insertStmt = $db->prepare("INSERT INTO visitors (
    visitor_code,
    society_id,
    unit_id,
    name,
    visitor_type,
    phone,
    flat_visiting,
    host_name,
    check_in_time,
    status,
    purpose,
    gate_number,
    vehicle_number,
    pass_code,
    photo_url,
    notified_resident_name,
    notified_resident_type,
    approval_status,
    is_deleted,
    created_at
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?
)");

$visitorName = 'Aryan Sharma';
$visitorType = 'Guest';
$hostDisplayName = "{$residentName} ({$residentType})";

$insertStmt->execute([
    $visitorCode,
    $societyId,
    $unitId,
    $visitorName,
    $visitorType,
    '+91 98765-43210',
    'B1-2001',
    $hostDisplayName,
    $checkInTime,
    'Waiting at Gate',
    'Personal Visit / Dinner',
    'Gate 1 (Main North)',
    'MH-12-AB-4567',
    $passCode,
    'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=200&auto=format&fit=crop&q=80',
    $residentName,
    $residentType,
    'Pending Approval',
    $createdAt
]);

echo "Created new visitor entry for Flat B1-2001:\n";
echo "Visitor: {$visitorName} ({$visitorType})\n";
echo "Pass Code: {$passCode}\n";
echo "Host: {$hostDisplayName}\n";
echo "Status: Waiting at Gate • Pending Approval\n";

// 4. Send Expo Push Notification
$pushService = new PushNotificationService();
$res = $pushService->notifyGatepassGenerated([
    'id' => $visitorCode,
    'name' => $visitorName,
    'type' => $visitorType,
    'flat_visiting' => 'B1-2001',
    'unit_id' => $unitId,
    'pass_code' => $passCode,
    'purpose' => 'Personal Visit / Dinner'
], $societyId);

echo "Push Notification status: " . ($res['success'] ? "Delivered successfully" : "Attempted (" . json_encode($res) . ")") . "\n";
