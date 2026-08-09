<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';

header('Content-Type: application/json');

// Przelewa jeszcze nie wypłacone zarobki partnerskie (affiliateStats.totalEarnedVirtual
// minus już wypłacone totalWithdrawn) na WŁASNE saldo gracza - operuje na
// koncie WOŁAJĄCEGO (nie na cudzym, w przeciwieństwie do
// api/record-affiliate-deposit.php, które celowo blokuje wiersz WŁAŚCICIELA
// kodu, bo to on dostaje prowizję).
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

    $stats = is_array($state['affiliateStats'] ?? null) ? $state['affiliateStats'] : ['timesUsed' => 0, 'totalDepositedPln' => 0, 'totalEarnedVirtual' => 0, 'totalWithdrawn' => 0];
    $totalEarned = (float) ($stats['totalEarnedVirtual'] ?? 0);
    $totalWithdrawn = (float) ($stats['totalWithdrawn'] ?? 0);
    $available = $totalEarned - $totalWithdrawn;

    if ($available <= 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'nothing_to_withdraw']);
        exit;
    }

    $stats['totalWithdrawn'] = $totalWithdrawn + $available;
    $state['affiliateStats'] = $stats;
    $currentBalance = is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0;
    $state['balance'] = $currentBalance + $available;

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'amount' => $available, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/withdraw-affiliate-earnings błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
