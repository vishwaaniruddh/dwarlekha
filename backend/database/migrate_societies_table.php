<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();
    $cols = $pdo->query("SHOW COLUMNS FROM societies")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('zone_id', $cols)) {
        $pdo->exec("ALTER TABLE societies ADD COLUMN zone_id INT NULL AFTER country");
        echo "✓ Added zone_id column to societies\n";
    }
    if (!in_array('zone', $cols)) {
        $pdo->exec("ALTER TABLE societies ADD COLUMN zone VARCHAR(50) NULL AFTER zone_id");
        echo "✓ Added zone column to societies\n";
    }

    echo "Societies table schema is fully updated!\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
