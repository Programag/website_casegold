<?php
/* Odpytywane przez topup-return.html po powrocie z płatności - WYŁĄCZNIE do
   odczytu statusu ustawionego przez api/topup-webhook.php. Ten endpoint
   sam w sobie nigdy niczego nie księguje. */
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

$sessionId = $_GET['session'] ?? '';
if (!is_string($sessionId) || $sessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_session']);
    exit;
}

$pdo = db();
topup_ensure_table($pdo);
// steamid w warunku - zamówienie musi należeć do zalogowanego gracza, żeby
// ktoś nie mógł podejrzeć statusu cudzej płatności po zgadniętym session id.
$find = $pdo->prepare('SELECT * FROM topup_orders WHERE session_id = ? AND steamid = ? LIMIT 1');
$find->execute([$sessionId, $me['steamid']]);
$order = $find->fetch();
if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

// Zapasowa ścieżka, gdyby powiadomienie P24 (webhook) nie dotarło: jeśli
// zamówienie wisi jako "pending" dłużej niż kilkanaście sekund, pytamy P24
// o transakcję po sessionId i - gdy jest opłacona (status 2) - kończymy ją tą
// samą, uwierzytelnioną ścieżką co webhook (verify -> księgowanie).
// $noPayment - P24 wprost mówi, że nie ma udanej wpłaty (brak transakcji albo
// status 0) - strona powrotu po kilku takich odpowiedziach pokazuje "odrzucona".
$noPayment = false;
if ($order['status'] === 'pending' && now_ms() - (int) $order['created_at'] > 12000) {
    $noPayment = topup_reconcile($pdo, $order);
    $find->execute([$sessionId, $me['steamid']]);
    $order = $find->fetch() ?: $order;
    // Strona powrotu prosi o potwierdzenie ("confirm"), gdy kilka razy z rzędu
    // usłyszała "brak wpłaty" - wtedy zamówienie trwale oznaczamy jako
    // anulowane (nadal da się je zaksięgować, jeśli P24 później potwierdzi wpłatę).
    if ($noPayment && $order['status'] === 'pending' && ($_GET['confirm'] ?? '') === '1') {
        topup_mark_failed($pdo, $sessionId, 'cancelled', 'płatność anulowana lub odrzucona (brak wpłaty w P24)');
        $find->execute([$sessionId, $me['steamid']]);
        $order = $find->fetch() ?: $order;
    }
}

$out = [
    'ok' => true,
    'status' => $order['status'],
    'virtualAmount' => (int) $order['virtual_amount'],
];
if ($order['status'] === 'failed') $out['reason'] = $order['fail_reason'] ?: 'rejected';
if ($order['status'] === 'pending' && $noPayment) $out['noPayment'] = true;
// Notatka diagnostyczna tylko dla admina.
if ((cs2_config()['admin_steamid'] ?? null) === $me['steamid'] && !empty($order['note'])) $out['note'] = $order['note'];
echo json_encode($out);
