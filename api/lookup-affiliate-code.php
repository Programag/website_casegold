<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

// Lekki, tylko-do-odczytu endpoint - używany w panelu doładowania
// (steam-auth.js), żeby od razu potwierdzić graczowi, że wpisany kod
// partnera istnieje, ZANIM kliknie "Kup" (gdzie faktycznie nalicza się
// prowizja - patrz api/record-affiliate-deposit.php). Nic tu nie zapisuje.
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
$code = isset($body['code']) && is_string($body['code']) ? strtoupper(trim($body['code'])) : '';
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    exit;
}

$myOwnCode = is_array($me['state'] ?? null) ? ($me['state']['affiliateCode'] ?? null) : null;
if ($code === $myOwnCode) {
    echo json_encode(['ok' => false, 'error' => 'self_referral']);
    exit;
}

$pdo = db();
$find = $pdo->prepare("SELECT display_name FROM users WHERE JSON_UNQUOTE(JSON_EXTRACT(state, '$.affiliateCode')) = ? LIMIT 1");
$find->execute([$code]);
$owner = $find->fetch();
if (!$owner) {
    echo json_encode(['ok' => false, 'error' => 'invalid_affiliate_code']);
    exit;
}

echo json_encode(['ok' => true, 'ownerName' => $owner['display_name']]);
