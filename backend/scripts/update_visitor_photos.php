<?php
require_once __DIR__ . '/../vendor/autoload.php';

$db = \App\Config\Database::getConnection();

$photos = [
    'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=400&auto=format&fit=crop&q=80',
    'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=400&auto=format&fit=crop&q=80'
];

$stmt = $db->query("SELECT id, name, photo_url FROM visitors WHERE is_deleted = 0");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $i => $r) {
    $p = !empty($r['photo_url']) ? $r['photo_url'] : $photos[$i % count($photos)];
    $up = $db->prepare("UPDATE visitors SET photo_url = ? WHERE id = ?");
    $up->execute([$p, $r['id']]);
    echo "Visitor #" . $r['id'] . " (" . $r['name'] . ") -> Photo: " . $p . "\n";
}
