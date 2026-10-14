<?php
/* Publiczny (bez logowania) odczyt strumienia ostatnich wygranych dla paska
   "na żywo". ?after=<id> zwraca tylko wpisy nowsze niż id (rosnąco); bez
   after - najnowsze ?limit (domyślnie 20), też rosnąco. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/live_drops.php';
require_once __DIR__ . '/../inc/users.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? max(1, min(40, (int) $_GET['limit'])) : 20;
$after = isset($_GET['after']) && is_numeric($_GET['after']) ? max(0, (int) $_GET['after']) : null;

try {
    $pdo = db();
    live_drops_ensure_table($pdo);
    if ($after !== null) {
        $stmt = $pdo->prepare('SELECT id, steamid, display_name, case_id, weapon, skin, wear, price FROM live_drops WHERE id > ? ORDER BY id ASC LIMIT ' . $limit);
        $stmt->execute([$after]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query('SELECT id, steamid, display_name, case_id, weapon, skin, wear, price FROM live_drops ORDER BY id DESC LIMIT ' . $limit);
        $rows = array_reverse($stmt->fetchAll());
    }
    // Aktualne dane gracza (nick/avatar/poziom mogły się zmienić od chwili
    // wygranej) + slug do linku na profil. steamid zostaje po stronie serwera.
    $ids = array_values(array_unique(array_column($rows, 'steamid')));
    $users = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $us = $pdo->prepare("SELECT steamid, slug, display_name, avatar, state FROM users WHERE steamid IN ($in)");
        $us->execute($ids);
        foreach ($us->fetchAll() as $u) $users[$u['steamid']] = $u;
    }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['price'] = (float) $r['price'];
        $u = $users[$r['steamid']] ?? null;
        $st = $u && $u['state'] !== null ? (json_decode($u['state'], true) ?: []) : [];
        // Ta sama reguła co api/profile.php - najwyższa ze znanych wartości.
        $r['level'] = $u ? (int) max(
            level_for_xp(is_numeric($st['xp'] ?? null) ? $st['xp'] : 0),
            is_numeric($st['levelWatermark'] ?? null) ? $st['levelWatermark'] : 0,
            is_numeric($st['level'] ?? null) ? $st['level'] : 0
        ) : null;
        $r['slug'] = $u['slug'] ?? null;
        $r['avatar'] = $u['avatar'] ?? null;
        if ($u && $u['display_name'] !== '') $r['display_name'] = $u['display_name'];
        unset($r['steamid']);
    }
    unset($r);
    echo json_encode(['ok' => true, 'drops' => $rows]);
} catch (Throwable $e) {
    error_log('GET /api/live-drops błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
