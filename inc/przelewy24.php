<?php
/* Wspólne funkcje do integracji z Przelewy24 (REST API v1) - rejestracja
   transakcji (api/topup-create.php) i jej potwierdzenie po powiadomieniu
   webhookiem (api/topup-webhook.php). Dokumentacja: developers.przelewy24.pl
   (dostęp do niej nie był możliwy z tego środowiska w trakcie pisania tego
   pliku - pola i formuła podpisu zweryfikowane pośrednio przez niezależne,
   open source'owe implementacje tego samego API; PRZED włączeniem
   produkcyjnych danych sprzedawcy przetestuj cały przepływ na sandboxie). */
require_once __DIR__ . '/db.php';

function p24_config(): array {
    $cfg = cs2_config()['p24'] ?? null;
    if (!$cfg) {
        throw new RuntimeException('Brak sekcji "p24" w config.php - patrz config.example.php.');
    }
    return $cfg;
}

function p24_base_url(): string {
    return !empty(p24_config()['sandbox']) ? 'https://sandbox.przelewy24.pl' : 'https://secure.przelewy24.pl';
}

/* SHA-384 nad JSON-em pól w USTALONEJ kolejności (kolejność kluczy w
   tablicy PHP = kolejność w json_encode) + kluczem CRC dopisanym na końcu -
   dokładnie tak, jak wymaga tego P24 przy każdym z trzech podpisów
   (register/verify/webhook) używanych w tym pliku. */
function p24_sign(array $fields): string {
    $fields['crc'] = p24_config()['crc'];
    return hash('sha384', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* Wspólny szkielet wywołania REST - Basic Auth (posId:apiKey), JSON w obie
   strony. Rzuca wyjątkiem przy błędzie sieci/HTTP/JSON, żeby wołający mógł
   to złapać w jednym miejscu zamiast sprawdzać każdy warunek osobno. */
function p24_request(string $method, string $path, array $body): array {
    $cfg = p24_config();
    $ch = curl_init(p24_base_url() . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $cfg['pos_id'] . ':' . $cfg['api_key'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Przelewy24: błąd połączenia ($path): $err");
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Przelewy24: nieprawidłowa odpowiedź ($path, HTTP $httpCode): $raw");
    }
    if ($httpCode >= 400) {
        $msg = $decoded['error'] ?? $decoded['data']['info'] ?? $raw;
        throw new RuntimeException("Przelewy24: błąd API ($path, HTTP $httpCode): $msg");
    }
    return $decoded;
}

/* Rejestruje nową transakcję i zwraca token do zbudowania URL-a
   przekierowania (p24_payment_url()). $sessionId musi być unikalne w
   obrębie CAŁEGO konta sprzedawcy (nie tylko naszej bazy) - używamy do
   tego naszego order_id z topup_orders. */
function p24_register_transaction(
    string $sessionId,
    int $amountGrosze,
    string $description,
    string $email,
    string $urlReturn,
    string $urlStatus
): string {
    $cfg = p24_config();
    $body = [
        'merchantId' => (int) $cfg['merchant_id'],
        'posId' => (int) $cfg['pos_id'],
        'sessionId' => $sessionId,
        'amount' => $amountGrosze,
        'currency' => 'PLN',
        'description' => $description,
        'email' => $email,
        'country' => 'PL',
        'language' => 'pl',
        'urlReturn' => $urlReturn,
        'urlStatus' => $urlStatus,
    ];
    $body['sign'] = p24_sign([
        'sessionId' => $sessionId,
        'merchantId' => (int) $cfg['merchant_id'],
        'amount' => $amountGrosze,
        'currency' => 'PLN',
    ]);
    $res = p24_request('POST', '/api/v1/transaction/register', $body);
    $token = $res['data']['token'] ?? null;
    if (!$token) {
        throw new RuntimeException('Przelewy24: brak tokenu w odpowiedzi register: ' . json_encode($res));
    }
    return $token;
}

function p24_payment_url(string $token): string {
    return p24_base_url() . '/trnRequest/' . rawurlencode($token);
}

/* Potwierdza transakcję PO otrzymaniu webhooka - dopiero to (nie sam
   webhook) jest autorytatywnym "tak, pieniądze faktycznie doszły", bo to
   MY wołamy P24 własnymi, uwierzytelnionymi danymi, więc atakujący
   podszywający się pod webhook nie jest w stanie sfałszować odpowiedzi
   "success" na to zapytanie. */
function p24_verify_transaction(string $sessionId, string $orderId, int $amountGrosze): bool {
    $cfg = p24_config();
    $body = [
        'merchantId' => (int) $cfg['merchant_id'],
        'posId' => (int) $cfg['pos_id'],
        'sessionId' => $sessionId,
        'amount' => $amountGrosze,
        'currency' => 'PLN',
        'orderId' => $orderId,
    ];
    $body['sign'] = p24_sign([
        'sessionId' => $sessionId,
        'orderId' => $orderId,
        'amount' => $amountGrosze,
        'currency' => 'PLN',
    ]);
    $res = p24_request('PUT', '/api/v1/transaction/verify', $body);
    return ($res['data']['status'] ?? null) === 'success';
}

/* Sprawdza podpis PRZYCHODZĄCEGO powiadomienia webhookiem - pierwsza linia
   obrony (odrzuca oczywiście spreparowane/nie-od-P24 żądania), ale NIE
   jedyna: właściwą bramką bezpieczeństwa jest i tak późniejsze wywołanie
   p24_verify_transaction() powyżej. Lista pól dla tego konkretnego podpisu
   nie była możliwa do zweryfikowania w oficjalnej dokumentacji z tego
   środowiska - jeśli w produkcyjnych logach ta funkcja zacznie odrzucać
   prawdziwe powiadomienia, sprawdź surowy JSON z rzeczywistego webhooka
   (error_log w api/topup-webhook.php) i popraw listę/kolejność pól tutaj. */
function p24_verify_webhook_sign(array $payload): bool {
    $expected = p24_sign([
        'merchantId' => (int) ($payload['merchantId'] ?? 0),
        'posId' => (int) ($payload['posId'] ?? 0),
        'sessionId' => (string) ($payload['sessionId'] ?? ''),
        'amount' => (int) ($payload['amount'] ?? 0),
        'originAmount' => (int) ($payload['originAmount'] ?? 0),
        'currency' => (string) ($payload['currency'] ?? ''),
        'orderId' => (int) ($payload['orderId'] ?? 0),
        'methodId' => (int) ($payload['methodId'] ?? 0),
        'statement' => (string) ($payload['statement'] ?? ''),
    ]);
    return hash_equals($expected, (string) ($payload['sign'] ?? ''));
}
