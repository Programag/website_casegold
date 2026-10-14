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
if ($order['status'] === 'pending' && now_ms() - (int) $order['created_at'] > 12000) {
    try {
        $t = p24_get_transaction_by_session($sessionId);
        if ($t && (int) ($t['status'] ?? 0) === 2 && !empty($t['orderId']) && (int) ($t['amount'] ?? 0) === (int) $order['price_grosze']) {
            topup_finalize($pdo, $order, $t['orderId']);
        } else {
            topup_note($pdo, $sessionId, 'odpytanie P24: status=' . ($t['status'] ?? 'brak danych') . ' (webhook jeszcze nie dotarł)');
        }
    } catch (Throwable $e) {
        topup_note($pdo, $sessionId, 'odpytanie P24 błąd: ' . $e->getMessage());
    }
    $find->execute([$sessionId, $me['steamid']]);
    $order = $find->fetch() ?: $order;
}

$out = [
    'ok' => true,
    'status' => $order['status'],
    'virtualAmount' => (int) $order['virtual_amount'],
];
// Notatka diagnostyczna tylko dla admina.
if ((cs2_config()['admin_steamid'] ?? null) === $me['steamid'] && !empty($order['note'])) $out['note'] = $order['note'];
echo json_encode($out);
