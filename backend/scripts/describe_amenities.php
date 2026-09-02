<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

$db = Database::getConnection();

echo "=== AMENITIES ===\n";
foreach ($db->query("DESCRIBE amenities")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ")\n";
}

echo "\n=== FACILITY BOOKINGS ===\n";
foreach ($db->query("DESCRIBE facility_bookings")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ")\n";
}
