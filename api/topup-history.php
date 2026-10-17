<?php
/* Historia płatności zalogowanego gracza (menu ustawień -> "Historia
   płatności"). Tylko odczyt, wyłącznie zamówienia TEGO gracza. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/topup.php';

header('Content-Type: application/json');

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$pdo = db();
topup_ensure_table($pdo);
// Zaległe zamówienia "oczekujące" (np. porzucone u P24 albo z niedostarczonym
// webhookiem) uzgadniamy z P24: opłacone księgujemy, a te bez wpłaty po 15
// minutach (tyle żyje link do płatności) oznaczamy jako nieopłacone.
$pending = $pdo->prepare('SELECT * FROM topup_orders WHERE steamid = ? AND status = "pending" AND created_at < ? ORDER BY id DESC LIMIT 5');
$pending->execute([$me['steamid'], now_ms() - 60000]);
foreach ($pending->fetchAll() as $o) {
    if (topup_reconcile($pdo, $o) && now_ms() - (int) $o['created_at'] > 15 * 60 * 1000) {
        topup_mark_failed($pdo, $o['session_id'], 'expired', 'płatność nieopłacona (brak wpłaty w P24 po 15 min)');
    }
}

$stmt = $pdo->prepare('SELECT id, status, price_grosze, virtual_amount, created_at, paid_at, fail_reason FROM topup_orders WHERE steamid = ? ORDER BY id DESC LIMIT 100');
$stmt->execute([$me['steamid']]);
$orders = [];
foreach ($stmt->fetchAll() as $r) {
    $orders[] = [
        'id' => (int) $r['id'],
        'status' => $r['status'],
        'priceGrosze' => (int) $r['price_grosze'],
        'virtualAmount' => (int) $r['virtual_amount'],
        'createdAt' => (int) $r['created_at'],
        'paidAt' => $r['paid_at'] !== null ? (int) $r['paid_at'] : null,
        'reason' => $r['fail_reason'],
    ];
}
echo json_encode(['ok' => true, 'orders' => $orders]);
