<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

$db = Database::getConnection();

try {
    $db->exec("ALTER TABLE residents ADD COLUMN push_token VARCHAR(255) NULL");
    echo "Added push_token to residents table.\n";
} catch (Throwable $e) {
    echo "residents push_token: " . $e->getMessage() . "\n";
}
