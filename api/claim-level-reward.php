<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_rewards.php';

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
$level = isset($body['level']) && is_numeric($body['level']) ? (int) $body['level'] : null;
if ($level === null || $level < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
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

    $currentLevel = state_effective_level($state);
    if ($level > $currentLevel) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'level_not_reached', 'currentLevel' => $currentLevel]);
        exit;
    }
    $claimed = is_array($state['claimedLevelRewards'] ?? null) ? $state['claimedLevelRewards'] : [];
    if (in_array($level, $claimed)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'already_claimed']);
        exit;
    }
    $item = level_reward_item($level);
    if (!$item) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'no_reward_item']);
        exit;
    }

    $claimed[] = $level;
    $state['claimedLevelRewards'] = $claimed;
    // Pierwszy endpoint dodający przedmiot w tej migracji - ustala konwencję
    // serwerowo nadawanych ID (state_next_item_id), którą kolejne fazy
    // (sprzedaż, blokada) będą traktować jako jedyne źródło prawdy o ID.
    $itemId = state_next_item_id($state);
    $inventory = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
    $newItem = ['weapon' => $item['weapon'], 'skin' => $item['skin'], 'wear' => $item['wear'], 'price' => $item['price'], '_id' => $itemId];
    $inventory[] = $newItem;
    $state['inventory'] = $inventory;

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'item' => $newItem, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/claim-level-reward błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
