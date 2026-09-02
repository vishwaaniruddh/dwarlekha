<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

$db = Database::getConnection();

// Add missing columns if needed
$cols = $db->query("DESCRIBE amenities")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('media', $cols)) {
    $db->exec("ALTER TABLE amenities ADD COLUMN media JSON NULL AFTER image_url");
    echo "Added 'media' JSON column to amenities.\n";
}

if (!in_array('description', $cols)) {
    $db->exec("ALTER TABLE amenities ADD COLUMN description TEXT NULL AFTER name");
    echo "Added 'description' column to amenities.\n";
}

if (!in_array('location', $cols)) {
    $db->exec("ALTER TABLE amenities ADD COLUMN location VARCHAR(150) NULL AFTER description");
    echo "Added 'location' column to amenities.\n";
}

if (!in_array('rules', $cols)) {
    $db->exec("ALTER TABLE amenities ADD COLUMN rules TEXT NULL AFTER operating_hours");
    echo "Added 'rules' column to amenities.\n";
}

echo "Amenities schema migration completed.\n";
