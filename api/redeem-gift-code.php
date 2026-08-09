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
if ($def['type'] === 'case') {
    $casePrices = require __DIR__ . '/../inc/data/case_prices.php';
    if (empty($def['caseId']) || !isset($casePrices[$def['caseId']])) {
        // Błąd w konfiguracji kodu (zły/nieistniejący caseId w gift_codes.php),
        // nie coś, co gracz mógł spowodować - zgłoś jako 500, nie 404, żeby
        // odróżnić od zwykłego "nie ma takiego kodu".
        http_response_code(500);
        echo json_encode(['error' => 'bad_gift_case_config']);
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
        $caseId = $def['caseId'];
        $grantCount = isset($def['count']) && is_numeric($def['count']) ? max(1, (int) $def['count']) : 1;
        $freeOpens = is_array($state['freeCaseOpens'] ?? null) ? $state['freeCaseOpens'] : [];
        $freeOpens[$caseId] = (int) ($freeOpens[$caseId] ?? 0) + $grantCount;
        $state['freeCaseOpens'] = $freeOpens;
        $result['caseId'] = $caseId;
        $result['label'] = $def['label'] ?? $caseId;
        $result['count'] = $grantCount;
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
