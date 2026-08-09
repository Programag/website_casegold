<?php
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
$code = isset($body['code']) && is_string($body['code']) ? strtoupper(trim($body['code'])) : '';

// 3-20 znaków, tylko litery A-Z i cyfry (bez spacji/znaków specjalnych -
// kod trafia też do kosmetycznego linku polecającego w affiliate.html).
if ($code === '' || !preg_match('/^[A-Z0-9]{3,20}$/', $code)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_code_format']);
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

    // Sprawdzenie unikalności PO zablokowaniu WŁASNEGO wiersza (nie
    // wszystkich) - dwóch graczy próbujących zająć ten sam kod w tym samym
    // momencie może oboje przejść tę kontrolę (affiliateCode nie ma
    // realnego ograniczenia UNIQUE na poziomie bazy, bo żyje wewnątrz JSON
    // `state`, nie w osobnej kolumnie). Przy niskiej skali tej funkcji
    // (kosmetyczny kod partnera, nie coś wpływającego na bezpieczeństwo
    // konta) to zaakceptowane ryzyko, nie błąd projektowy.
    $check = $pdo->prepare("SELECT steamid FROM users WHERE JSON_UNQUOTE(JSON_EXTRACT(state, '$.affiliateCode')) = ? AND steamid != ? LIMIT 1");
    $check->execute([$code, $me['steamid']]);
    if ($check->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'code_taken']);
        exit;
    }

    $state['affiliateCode'] = $code;
    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'code' => $code, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/set-affiliate-code błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
