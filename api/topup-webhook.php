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
$orderId = (string) $payload['orderId'];
$amountGrosze = (int) ($payload['amount'] ?? 0);

$pdo = db();
$find = $pdo->prepare('SELECT * FROM topup_orders WHERE session_id = ? LIMIT 1');
$find->execute([$sessionId]);
$order = $find->fetch();
if (!$order) {
    error_log('POST /api/topup-webhook nieznany sessionId: ' . $sessionId);
    http_response_code(404);
    echo 'unknown_session';
    exit;
}

// Idempotencja - P24 może wysłać to samo powiadomienie wielokrotnie
// (retry przy braku/wolnej odpowiedzi). Już opłacone zamówienie kończymy
// tu, ZANIM cokolwiek zaksięgujemy drugi raz.
if ($order['status'] === 'paid') {
    echo 'OK';
    exit;
}

if ($amountGrosze !== (int) $order['price_grosze']) {
    error_log("POST /api/topup-webhook niezgodna kwota dla $sessionId: oczekiwano {$order['price_grosze']}, otrzymano $amountGrosze");
    http_response_code(400);
    echo 'amount_mismatch';
    exit;
}

try {
    $confirmed = p24_verify_transaction($sessionId, $orderId, (int) $order['price_grosze']);
} catch (Throwable $e) {
    error_log('POST /api/topup-webhook błąd verify(): ' . $e->getMessage());
    http_response_code(502);
    echo 'verify_failed';
    exit;
}

if (!$confirmed) {
    error_log("POST /api/topup-webhook verify() zwrócił niepowodzenie dla $sessionId / orderId=$orderId");
    $fail = $pdo->prepare('UPDATE topup_orders SET status = "failed", p24_order_id = ? WHERE session_id = ? AND status = "pending"');
    $fail->execute([$orderId, $sessionId]);
    http_response_code(200); // nie retry'ujemy własnych, potwierdzonych niepowodzeń
    echo 'not_confirmed';
    exit;
}

$pdo->beginTransaction();
try {
    // Ponowna kontrola pod blokadą wiersza zamówienia (nie ma FOR UPDATE
    // bezpośrednio na topup_orders, ale UPDATE ... WHERE status='pending'
    // jest atomowy - druga, równoległa dostawa tego samego webhooka po
    // prostu nie zaktualizuje żadnego wiersza i rowCount() wyjdzie 0).
    $claim = $pdo->prepare('UPDATE topup_orders SET status = "paid", p24_order_id = ?, paid_at = ? WHERE session_id = ? AND status = "pending"');
    $claim->execute([$orderId, now_ms(), $sessionId]);
    if ($claim->rowCount() === 0) {
        // Ktoś inny (równoległa dostawa tego samego webhooka) już to obsłużył.
        $pdo->rollBack();
        echo 'OK';
        exit;
    }

    $state = state_lock_user_for_update($pdo, $order['steamid']);
    if ($state === null) {
        throw new RuntimeException('Użytkownik z zamówienia zniknął: ' . $order['steamid']);
    }
    $state['balance'] = (is_numeric($state['balance'] ?? null) ? $state['balance'] : 0) + (int) $order['virtual_amount'];
    state_save($pdo, $order['steamid'], $state);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/topup-webhook błąd księgowania: ' . $e->getMessage());
    http_response_code(503);
    echo 'storage_unavailable';
    exit;
}

// Prowizja partnerska - osobna transakcja (na koncie WŁAŚCICIELA kodu, nie
// kupującego) - błąd tutaj nie może cofnąć już zaksięgowanej wpłaty kupującego.
credit_affiliate_for_deposit($pdo, $order['affiliate_code'], $order['steamid'], $order['price_grosze'] / 100);

echo 'OK';
