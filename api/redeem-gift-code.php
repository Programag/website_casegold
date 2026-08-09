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
$code = isset($body['code']) && is_string($body['code']) ? strtoupper(trim($body['code'])) : '';
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$codes = require __DIR__ . '/../inc/data/gift_codes.php';
$def = $codes[$code] ?? null;
if (!$def) {
    http_response_code(404);
    echo json_encode(['error' => 'invalid_code']);
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

    $redeemed = is_array($state['redeemedGiftCodes'] ?? null) ? $state['redeemedGiftCodes'] : [];
    if (in_array($code, $redeemed, true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'already_redeemed']);
        exit;
    }
    $redeemed[] = $code;
    $state['redeemedGiftCodes'] = $redeemed;

    $result = ['type' => $def['type']];
    if ($def['type'] === 'money') {
        $amount = (float) $def['amount'];
        $currentBalance = is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0;
        $state['balance'] = $currentBalance + $amount;
        $result['amount'] = $amount;
    } else {
        $item = gift_case_random_item();
        $itemId = state_next_item_id($state);
        $inventory = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
        $newItem = ['weapon' => $item['weapon'], 'skin' => $item['skin'], 'wear' => $item['wear'], 'price' => $item['price'], '_id' => $itemId];
        $inventory[] = $newItem;
        $state['inventory'] = $inventory;
        $result['item'] = $newItem;
    }

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(array_merge(['ok' => true], $result, ['state' => fix_state_object_fields($saved)]));
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/redeem-gift-code błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
