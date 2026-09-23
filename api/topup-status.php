<?php
/* Odpytywane przez topup-return.html po powrocie z płatności - WYŁĄCZNIE do
   odczytu statusu ustawionego przez api/topup-webhook.php. Ten endpoint
   sam w sobie nigdy niczego nie księguje. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

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
// steamid w warunku - zamówienie musi należeć do zalogowanego gracza, żeby
// ktoś nie mógł podejrzeć statusu cudzej płatności po zgadniętym session id.
$find = $pdo->prepare('SELECT status, virtual_amount FROM topup_orders WHERE session_id = ? AND steamid = ? LIMIT 1');
$find->execute([$sessionId, $me['steamid']]);
$order = $find->fetch();
if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'status' => $order['status'],
    'virtualAmount' => (int) $order['virtual_amount'],
]);
