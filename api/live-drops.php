<?php
/* Publiczny (bez logowania) odczyt strumienia ostatnich wygranych dla paska
   "na żywo". ?after=<id> zwraca tylko wpisy nowsze niż id (rosnąco); bez
   after - najnowsze ?limit (domyślnie 20), też rosnąco. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/live_drops.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min(40, (int) $_GET['limit'])) : 20;
$after = isset($_GET['after']) && is_numeric($_GET['after']) ? max(0, (int) $_GET['after']) : null;

try {
    $pdo = db();
    live_drops_ensure_table($pdo);
    if ($after !== null) {
        $stmt = $pdo->prepare('SELECT id, display_name, case_id, weapon, skin, wear, price FROM live_drops WHERE id > ? ORDER BY id ASC LIMIT ' . $limit);
        $stmt->execute([$after]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query('SELECT id, display_name, case_id, weapon, skin, wear, price FROM live_drops ORDER BY id DESC LIMIT ' . $limit);
        $rows = array_reverse($stmt->fetchAll());
    }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['price'] = (float) $r['price'];
    }
    unset($r);
    echo json_encode(['ok' => true, 'drops' => $rows]);
} catch (Throwable $e) {
    error_log('GET /api/live-drops błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
