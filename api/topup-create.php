<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/przelewy24.php';

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

$pdo = db();
// sessionId musi być unikalny w obrębie CAŁEGO konta sprzedawcy P24 (nie
// tylko naszej tabeli) - losowy token + steamid jest wystarczająco
// jednoznaczny; UNIQUE na session_id w topup_orders i tak łapie kolizję,
// gdyby się kiedyś zdarzyła (praktycznie niemożliwe przy 32 losowych hex).
$sessionId = 'cg_' . $me['steamid'] . '_' . bin2hex(random_bytes(16));
$now = now_ms();

$insert = $pdo->prepare('INSERT INTO topup_orders (session_id, steamid, package_index, price_grosze, virtual_amount, affiliate_code, status, created_at) VALUES (?, ?, ?, ?, ?, ?, "pending", ?)');
$insert->execute([$sessionId, $me['steamid'], $packageIndex, $package['priceGrosze'], $package['virtualAmount'], $affiliateCode, $now]);

try {
    $token = p24_register_transaction(
        $sessionId,
        $package['priceGrosze'],
        'Doładowanie CASEGOLD - ' . number_format($package['virtualAmount'], 0, ',', ' ') . ' zł wirtualnych',
        $syntheticEmail,
        $siteUrl . '/topup-return.html?session=' . urlencode($sessionId),
        $siteUrl . '/api/topup-webhook.php'
    );
} catch (Throwable $e) {
    error_log('POST /api/topup-create błąd rejestracji P24: ' . $e->getMessage());
    $fail = $pdo->prepare('UPDATE topup_orders SET status = "failed" WHERE session_id = ?');
    $fail->execute([$sessionId]);
    http_response_code(502);
    echo json_encode(['error' => 'payment_provider_unavailable']);
    exit;
}

echo json_encode(['ok' => true, 'redirectUrl' => p24_payment_url($token)]);
