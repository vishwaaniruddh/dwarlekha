<?php
require_once __DIR__ . '/../vendor/autoload.php';

$email = 'pj@gmail.com';
$password = 'Welcome@123';
$hash = password_hash($password, PASSWORD_BCRYPT);

$db = \App\Config\Database::getConnection();

// 1. Check if user already exists
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, status = 'Active', is_deleted = 0 WHERE id = ?");
    $stmt->execute([$hash, $existing['id']]);
    echo "Updated existing user ID: " . $existing['id'] . " with password Welcome@123\n";
} else {
    $roleStmt = $db->query("SELECT id FROM roles WHERE code = 'resident' OR name = 'Resident' LIMIT 1");
    $roleId = $roleStmt->fetchColumn() ?: 7;
    
    $unitStmt = $db->query("SELECT id, society_id, unit_code FROM units WHERE unit_code = 'B1-2001' OR society_id = 3 LIMIT 1");
    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
    $unitId = $unit['id'] ?? 1;
    $societyId = $unit['society_id'] ?? 3;
    $unitCode = $unit['unit_code'] ?? 'B1-2001';
    
    $stmt = $db->prepare("INSERT INTO users 
        (name, email, phone, password_hash, role_id, society_id, unit_id, status, is_deleted) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', 0)");
    $stmt->execute(['Prasenjit Berra', $email, '+91 98200-11223', $hash, $roleId, $societyId, $unitId]);
    $newId = $db->lastInsertId();
    echo "Created new user ID: " . $newId . " for flat " . $unitCode . " with password Welcome@123\n";
}

// 2. Also ensure residents table has record
$resStmt = $db->prepare("SELECT id FROM residents WHERE email = ? AND is_deleted = 0");
$resStmt->execute([$email]);
$resExisting = $resStmt->fetchColumn();

if (!$resExisting) {
    $unitStmt = $db->query("SELECT id, society_id, unit_code FROM units WHERE unit_code = 'B1-2001' OR society_id = 3 LIMIT 1");
    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
    
    $insRes = $db->prepare("INSERT INTO residents 
        (society_id, unit_id, name, email, phone, resident_type, status, verification_status, is_deleted) 
        VALUES (?, ?, ?, ?, ?, 'Owner', 'Active', 'Approved', 0)");
    $insRes->execute([$unit['society_id'] ?? 3, $unit['id'] ?? 1, 'Prasenjit Berra', $email, '+91 98200-11223']);
    echo "Created resident entry for Prasenjit Berra\n";
} else {
    echo "Resident record already exists for " . $email . "\n";
}
