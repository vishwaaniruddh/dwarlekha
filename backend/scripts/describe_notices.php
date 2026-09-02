<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

$db = Database::getConnection();

$cols = $db->query("DESCRIBE notices")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' (' . $c['Type'] . ')' . PHP_EOL;
}
