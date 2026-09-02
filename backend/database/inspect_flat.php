<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$db = Database::getConnection();

echo "--- UNITS ---\n";
$stmt = $db->query("SELECT id, society_id, unit_code, occupancy_status, owner_name, tenant_name FROM units WHERE unit_code = 'B3-1601'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- RESIDENTS FOR B3-1601 ---\n";
$stmt2 = $db->query("SELECT r.id, r.society_id, r.user_id, r.unit_id, r.resident_type, r.is_deleted, u.full_name, u.email 
                     FROM residents r 
                     JOIN users u ON r.user_id = u.id 
                     JOIN units un ON r.unit_id = un.id 
                     WHERE un.unit_code = 'B3-1601'");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
