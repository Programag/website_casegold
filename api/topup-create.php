<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/przelewy24.php';
require_once __DIR__ . '/../inc/topup.php';

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

// Na sandboxie P24 płatności są testowe (bez prawdziwych pieniędzy), więc
// zamawiać może tylko admin - patrz komentarz w api/me.php.
$p24cfg = cs2_config()['p24'] ?? null;
if (!is_array($p24cfg) || (!empty($p24cfg['sandbox']) && $me['steamid'] !== (cs2_config()['admin_steamid'] ?? null))) {
    http_response_code(403);
    echo json_encode(['error' => 'payments_unavailable']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$packageIndex = isset($body['packageIndex']) && is_numeric($body['packageIndex']) ? (int) $body['packageIndex'] : null;
// Kod partnerski jest opcjonalny i tylko INFORMACYJNY na tym etapie -
// zapisujemy go na zamówieniu, ale prowizja jest naliczana dopiero po
// PRAWDZIWEJ, potwierdzonej wpłacie (patrz api/topup-webhook.php +
// inc/affiliate.php), nigdy tutaj.
$affiliateCode = isset($body['affiliateCode']) && is_string($body['affiliateCode']) && $body['affiliateCode'] !== ''
    ? strtoupper(trim($body['affiliateCode']))
    : null;

// Bez obu oświadczeń nie tworzymy zamówienia: (1) akceptacja
// regulaminu i polityki prywatności, (2) żądanie natychmiastowego
// świadczenia usługi cyfrowej z przyjęciem do wiadomości utraty prawa
// odstąpienia (art. 38 pkt 13 ustawy o prawach konsumenta). Moment
// złożenia oświadczeń zapisujemy na zamówieniu (consent_at) jako dowód.
if (($body['acceptTerms'] ?? false) !== true || ($body['waiveWithdrawal'] ?? false) !== true) {
    http_response_code(400);
    echo json_encode(['error' => 'consent_required']);
    exit;
}

$packages = require __DIR__ . '/../inc/data/topup_packages.php';
if ($packageIndex === null || !isset($packages[$packageIndex])) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_package']);
    exit;
}
$package = $packages[$packageIndex];

$siteUrl = rtrim(cs2_config()['site_url'], '/');
// Steam nie udostępnia adresu e-mail konta - P24 wymaga jakiegoś adresu do
// pola "email" (używa go głównie do ew. potwierdzenia/faktury), więc
// syntetyzujemy nieistniejący, ale poprawny składniowo adres z steamid.
// Gracz i tak zobaczy potwierdzenie na naszej stronie (topup-return.html),
// nie e-mailem.
$syntheticEmail = $me['steamid'] . '@users.' . preg_replace('#^https?://#', '', $siteUrl);

// Szczegóły błędu (np. odpowiedź Przelewy24) zwracamy wyłącznie adminowi -
// do diagnozy konfiguracji; zwykły gracz dostaje tylko kod błędu.
$isAdminCaller = (cs2_config()['admin_steamid'] ?? null) === $me['steamid'];
$fail_json = function (int $status, string $code, ?Throwable $e = null) use ($isAdminCaller) {
    http_response_code($status);
    $out = ['error' => $code];
    if ($e && $isAdminCaller) $out['detail'] = $e->getMessage();
    echo json_encode($out);
    exit;
};

try {
$pdo = db();
topup_ensure_table($pdo);
// sessionId musi być unikalny w obrębie CAŁEGO konta sprzedawcy P24 (nie
// tylko naszej tabeli) - losowy token + steamid jest wystarczająco
// jednoznaczny; UNIQUE na session_id w topup_orders i tak łapie kolizję,
// gdyby się kiedyś zdarzyła (praktycznie niemożliwe przy 32 losowych hex).
$sessionId = 'cg_' . $me['steamid'] . '_' . bin2hex(random_bytes(16));
$now = now_ms();

$insert = $pdo->prepare('INSERT INTO topup_orders (session_id, steamid, package_index, price_grosze, virtual_amount, affiliate_code, status, created_at, consent_at) VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?)');
$insert->execute([$sessionId, $me['steamid'], $packageIndex, $package['priceGrosze'], $package['virtualAmount'], $affiliateCode, $now, $now]);
} catch (Throwable $e) {
    error_log('POST /api/topup-create błąd zapisu zamówienia: ' . $e->getMessage());
    $fail_json(500, 'order_storage_failed', $e);
}

try {
    $token = p24_register_transaction(
        $sessionId,
        $package['priceGrosze'],
        'Doładowanie CASEGOLD - ' . number_format($package['virtualAmount'], 0, ',', ' ') . ' zł wirtualnych',
        $syntheticEmail,
        $siteUrl . '/topup-return?session=' . urlencode($sessionId),
        $siteUrl . '/api/topup-webhook.php'
    );
} catch (Throwable $e) {
    error_log('POST /api/topup-create błąd rejestracji P24: ' . $e->getMessage());
    $fail = $pdo->prepare('UPDATE topup_orders SET status = "failed" WHERE session_id = ?');
    $fail->execute([$sessionId]);
    $fail_json(502, 'payment_provider_unavailable', $e);
}

echo json_encode(['ok' => true, 'redirectUrl' => p24_payment_url($token)]);
