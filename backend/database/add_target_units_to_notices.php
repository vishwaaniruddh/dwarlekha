<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;

$db = Database::getConnection();

$cols = $db->query("DESCRIBE notices")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('target_scope', $cols)) {
    $db->exec("ALTER TABLE notices ADD COLUMN target_scope VARCHAR(30) NOT NULL DEFAULT 'ALL' AFTER content");
    echo "Added target_scope column to notices table.\n";
} else {
    echo "target_scope column already exists.\n";
}

if (!in_array('target_units', $cols)) {
    $db->exec("ALTER TABLE notices ADD COLUMN target_units TEXT NULL AFTER target_scope");
    echo "Added target_units column to notices table.\n";
} else {
    echo "target_units column already exists.\n";
}

echo "Done.\n";
