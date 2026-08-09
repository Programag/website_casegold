<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';

header('Content-Type: application/json');

// Ile wirtualnych złotych dostaje twórca za każdą (na razie fikcyjną -
// panel doładowania nadal nie ma prawdziwych płatności, patrz komentarz w
// steam-auth.js przy TOPUP_PACKAGES) "wpłaconą" prawdziwą złotówkę.
const AFFILIATE_VIRTUAL_PER_PLN = 1000;

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
$packageIndex = isset($body['packageIndex']) && is_numeric($body['packageIndex']) ? (int) $body['packageIndex'] : null;

if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$prices = require __DIR__ . '/../inc/data/topup_packages.php';
if ($packageIndex === null || !isset($prices[$packageIndex])) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_package']);
    exit;
}
// Cena liczona WYŁĄCZNIE z serwerowej tabeli, nigdy z tego, co przysłał
// klient - inaczej dałoby się sfałszować kwotę prowizji partnera.
$pricePln = (float) $prices[$packageIndex];

// Właściciel kodu (ten, kto dostaje prowizję) szukany PRZED transakcją -
// nie trzeba blokować wiersza kupującego, bo jego stan w ogóle się tu nie
// zmienia (panel doładowania dalej nie przyznaje kupującemu żadnej
// prawdziwej waluty - patrz komentarz w steam-auth.js).
$pdo = db();
$find = $pdo->prepare("SELECT steamid FROM users WHERE JSON_UNQUOTE(JSON_EXTRACT(state, '$.affiliateCode')) = ? LIMIT 1");
$find->execute([$code]);
$owner = $find->fetch();
if (!$owner) {
    http_response_code(404);
    echo json_encode(['error' => 'invalid_affiliate_code']);
    exit;
}
if ($owner['steamid'] === $me['steamid']) {
    http_response_code(400);
    echo json_encode(['error' => 'self_referral']);
    exit;
}

$pdo->beginTransaction();
try {
    $state = state_lock_user_for_update($pdo, $owner['steamid']);
    if ($state === null) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'no_such_user']);
        exit;
    }
    // Kod mógł się zmienić między wyszukaniem właściciela a zablokowaniem
    // jego wiersza (rzadki wyścig) - nie przyznawaj prowizji już
    // nieaktualnemu kodowi.
    if (($state['affiliateCode'] ?? null) !== $code) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'invalid_affiliate_code']);
        exit;
    }

    $stats = is_array($state['affiliateStats'] ?? null) ? $state['affiliateStats'] : ['timesUsed' => 0, 'totalDepositedPln' => 0, 'totalEarnedVirtual' => 0, 'totalWithdrawn' => 0];
    $stats['timesUsed'] = (int) ($stats['timesUsed'] ?? 0) + 1;
    $stats['totalDepositedPln'] = (float) ($stats['totalDepositedPln'] ?? 0) + $pricePln;
    $stats['totalEarnedVirtual'] = (float) ($stats['totalEarnedVirtual'] ?? 0) + $pricePln * AFFILIATE_VIRTUAL_PER_PLN;
    $state['affiliateStats'] = $stats;

    state_save($pdo, $owner['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/record-affiliate-deposit błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
