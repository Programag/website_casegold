<?php
/* Darmowe skrzynki (darmowe_open.html) otwierają się po stronie klienta, więc
   serwer o ich wygranych nie wie - to zgłoszenie służy WYŁĄCZNIE do
   pokazania wygranej w pasku "na żywo". Nie dotyka salda ani ekwipunku.
   Zabezpieczenia, bo to publiczny strumień: przedmiot musi istnieć w tabeli
   danej skrzynki (inc/data/free_case_items.php), cena pochodzi z serwera,
   gracz musi mieć wymagany poziom, a z jednej skrzynki zgłoszenie przyjmujemy
   najwyżej raz na cooldown (3 h) - tyle, ile legalnie można ją otworzyć. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';
require_once __DIR__ . '/../inc/live_drops.php';

header('Content-Type: application/json');

const FREE_DROP_MIN_GAP_MS = 175 * 60 * 1000; // 3 h z zapasem 5 min na rozjazd zegarów

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
$caseId = isset($body['caseId']) && is_string($body['caseId']) ? $body['caseId'] : '';
$weapon = isset($body['weapon']) && is_string($body['weapon']) ? $body['weapon'] : '';
$skin = isset($body['skin']) && is_string($body['skin']) ? $body['skin'] : '';
$wear = isset($body['wear']) && is_string($body['wear']) ? $body['wear'] : '';

$cases = require __DIR__ . '/../inc/data/free_case_items.php';
$case = $cases[$caseId] ?? null;
if (!$case) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_case_id']);
    exit;
}
$found = null;
foreach ($case['items'] as $it) {
    if ($it['weapon'] === $weapon && $it['skin'] === $skin && $it['wear'] === $wear) { $found = $it; break; }
}
if (!$found) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_item']);
    exit;
}

$state = is_array($me['state'] ?? null) ? $me['state'] : [];
if (state_effective_level($state) < $case['minLevel']) {
    http_response_code(403);
    echo json_encode(['error' => 'level_too_low']);
    exit;
}

try {
    $pdo = db();
    live_drops_ensure_table($pdo);
    $feedCaseId = 'free_' . $caseId;
    $last = $pdo->prepare('SELECT MAX(created_at) FROM live_drops WHERE steamid = ? AND case_id = ?');
    $last->execute([$me['steamid'], $feedCaseId]);
    $lastAt = (int) $last->fetchColumn();
    if ($lastAt > 0 && now_ms() - $lastAt < FREE_DROP_MIN_GAP_MS) {
        echo json_encode(['ok' => true, 'recorded' => false]); // za wcześnie - po cichu pomijamy
        exit;
    }
    live_drops_record($pdo, $me['steamid'], (string) ($me['display_name'] ?? 'Gracz'), $feedCaseId, [$found]);
    echo json_encode(['ok' => true, 'recorded' => true]);
} catch (Throwable $e) {
    error_log('POST /api/report-free-drop błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
