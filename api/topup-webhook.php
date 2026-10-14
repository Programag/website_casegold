<?php
/* Publiczny endpoint wołany PRZEZ Przelewy24 (urlStatus z rejestracji
   transakcji) - serwer-do-serwera, BEZ sesji/logowania. Nigdy nie ufamy
   samemu ciału tego żądania jako dowodowi wpłaty (patrz komentarz w
   inc/przelewy24.php) - dopiero p24_verify_transaction() poniżej decyduje,
   czy zaksięgować prawdziwe saldo. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/state_ops.php';
require_once __DIR__ . '/../inc/przelewy24.php';
require_once __DIR__ . '/../inc/affiliate.php';
require_once __DIR__ . '/../inc/topup.php';

header('Content-Type: text/plain');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['sessionId']) || !isset($payload['orderId'])) {
    error_log('POST /api/topup-webhook nieprawidłowy JSON: ' . $raw);
    http_response_code(400);
    echo 'bad_request';
    exit;
}

// Pierwsza linia obrony - odrzuca oczywiste fałszywki. Świadomie NIE
// przerywamy tu na twardo (patrz komentarz przy p24_verify_webhook_sign) -
// logujemy niezgodność, ale i tak lecimy dalej do autorytatywnego verify()
// poniżej, żeby ewentualna pomyłka w liście pól podpisu webhooka nie
// blokowała księgowania prawdziwych wpłat. Jedyną realną bramką jest verify().
if (!p24_verify_webhook_sign($payload)) {
    error_log('POST /api/topup-webhook podpis webhooka nie zgadza się (sessionId=' . $payload['sessionId'] . '): ' . $raw);
}

$sessionId = (string) $payload['sessionId'];
$orderId = $payload['orderId'];
$amountGrosze = (int) ($payload['amount'] ?? 0);

$pdo = db();
topup_ensure_table($pdo);
$find = $pdo->prepare('SELECT * FROM topup_orders WHERE session_id = ? LIMIT 1');
$find->execute([$sessionId]);
$order = $find->fetch();
if (!$order) {
    error_log('POST /api/topup-webhook nieznany sessionId: ' . $sessionId);
    http_response_code(404);
    echo 'unknown_session';
    exit;
}
topup_note($pdo, $sessionId, 'webhook dotarł (orderId=' . $orderId . ')');

if ($order['status'] === 'paid') {
    echo 'OK'; // P24 może powtarzać powiadomienie - idempotentnie
    exit;
}

if ($amountGrosze !== (int) $order['price_grosze']) {
    error_log("POST /api/topup-webhook niezgodna kwota dla $sessionId: oczekiwano {$order['price_grosze']}, otrzymano $amountGrosze");
    topup_note($pdo, $sessionId, "webhook: niezgodna kwota ($amountGrosze zamiast {$order['price_grosze']})");
    http_response_code(400);
    echo 'amount_mismatch';
    exit;
}

switch (topup_finalize($pdo, $order, $orderId)) {
    case 'paid':
    case 'already_paid':
        echo 'OK';
        break;
    case 'not_confirmed':
        echo 'not_confirmed'; // nie retry'ujemy własnych, potwierdzonych niepowodzeń
        break;
    case 'verify_error':
        http_response_code(502);
        echo 'verify_failed';
        break;
    default:
        http_response_code(503);
        echo 'storage_unavailable';
}
