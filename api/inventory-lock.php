<?php
/* Blokada/odblokowanie przedmiotu przed przypadkową sprzedażą - flaga
   `locked` żyje na serwerze razem z ekwipunkiem. */
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
$id = isset($body['id']) && is_numeric($body['id']) ? (int) $body['id'] : null;
if ($id === null || !array_key_exists('locked', $body)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}
$locked = !empty($body['locked']);

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
    $found = false;
    foreach ($inventory as $i => $it) {
        if (is_array($it) && isset($it['_id']) && is_numeric($it['_id']) && (int) $it['_id'] === $id) {
            if ($locked) $inventory[$i]['locked'] = true; else unset($inventory[$i]['locked']);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'no_such_item']);
        exit;
    }
    $state['inventory'] = $inventory;
    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();
    echo json_encode(['ok' => true, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/inventory-lock błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
