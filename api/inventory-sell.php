<?php
/* Sprzedaż przedmiotów z ekwipunku - w pełni po stronie serwera: wartość
   brana jest z ceny zapisanej na serwerze przy przedmiocie, a nie od klienta
   (klient przysyła tylko ID przedmiotów albo "wszystkie"). Zablokowane
   przedmioty nigdy nie są sprzedawane. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$all = !empty($body['all']);
$ids = [];
if (!$all) {
    if (!isset($body['ids']) || !is_array($body['ids']) || count($body['ids']) === 0 || count($body['ids']) > 5000) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request']);
        exit;
    }
    foreach ($body['ids'] as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) $ids[] = (int) $id;
    }
    if (!$ids) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request']);
        exit;
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $state = state_lock_user_for_update($pdo, $me['steamid']);
    if ($state === null) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'no_such_user']);
        exit;
    }

    $inventory = is_array($state['inventory'] ?? null) ? array_values($state['inventory']) : [];
    $keep = [];
    $sold = 0;
    $total = 0.0;
    $skippedLocked = 0;
    $remainingIds = array_count_values($ids); // id => ile razy poproszono (kolizje starych ID: po jednym przedmiocie na żądanie)
    foreach ($inventory as $it) {
        $itemId = is_array($it) && isset($it['_id']) && is_numeric($it['_id']) ? (int) $it['_id'] : null;
        $wanted = $all || ($itemId !== null && ($remainingIds[$itemId] ?? 0) > 0);
        if (!$wanted) { $keep[] = $it; continue; }
        if (!empty($it['locked'])) { $skippedLocked++; $keep[] = $it; continue; }
        if (!$all) $remainingIds[$itemId]--;
        $price = is_numeric($it['price'] ?? null) && is_finite((float) $it['price']) && $it['price'] > 0 ? (float) $it['price'] : 0.0;
        $total += $price;
        $sold++;
    }
    if ($sold === 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => $skippedLocked > 0 ? 'all_locked' : 'nothing_to_sell']);
        exit;
    }

    $state['inventory'] = $keep;
    $state['balance'] = (is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0) + $total;
    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'sold' => $sold, 'total' => $total, 'skippedLocked' => $skippedLocked, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/inventory-sell błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
