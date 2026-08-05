<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
$dateStr = battle_warsaw_date_string(now_ms());

$stmt = db()->prepare('SELECT entries FROM daily_top WHERE date_str = ?');
$stmt->execute([$dateStr]);
$row = $stmt->fetch();
$list = $row ? json_decode($row['entries'], true) : [];

echo json_encode(['ok' => true, 'date' => $dateStr, 'entries' => array_slice($list, 0, DAILY_TOP_SHOWN)]);
