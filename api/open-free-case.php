<?php
/* Darmowe skrzynki godzinowe (darmowe_open.html) - w całości po stronie
   serwera: poziom, cooldown (3 h, zapisany w state.freeCaseCooldowns -
   wspólny dla wszystkich urządzeń), LOSOWANIE z tabeli
   inc/data/free_case_items.php i dopisanie przedmiotu do ekwipunku. Klient
   dostaje wylosowany przedmiot do animacji. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';
require_once __DIR__ . '/../inc/live_drops.php';

header('Content-Type: application/json');

const FREE_CASE_COOLDOWN_MS = 3 * 60 * 60 * 1000;

function free_case_fail(int $status, string $code, array $extra = []): void {
    http_response_code($status);
    echo json_encode(array_merge(['error' => $code], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') free_case_fail(405, 'method_not_allowed');
$me = current_user();
if (!$me) free_case_fail(401, 'not_logged_in');

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$caseId = isset($body['caseId']) && is_string($body['caseId']) ? $body['caseId'] : '';
$cases = require __DIR__ . '/../inc/data/free_case_items.php';
$case = $cases[$caseId] ?? null;
if (!$case) free_case_fail(400, 'bad_case_id');

$pdo = db();
$pdo->beginTransaction();
try {
    $state = state_lock_user_for_update($pdo, $me['steamid']);
    if ($state === null) {
        $pdo->rollBack();
        free_case_fail(404, 'no_such_user');
    }

    if (state_effective_level($state) < $case['minLevel']) {
        $pdo->rollBack();
        free_case_fail(403, 'level_too_low');
    }

    $cooldowns = is_array($state['freeCaseCooldowns'] ?? null) ? $state['freeCaseCooldowns'] : [];
    $now = now_ms();
    $last = is_numeric($cooldowns[$caseId] ?? null) ? (float) $cooldowns[$caseId] : 0;
    // Wsteczna zgodność: dotychczasowe cooldowny żyły tylko w przeglądarce, więc
    // dla darmowej skrzynki bez wpisu serwerowego uwzględniamy freeCaseAt.
    if ($caseId === 'free' && $last <= 0 && is_numeric($state['freeCaseAt'] ?? null)) $last = (float) $state['freeCaseAt'];
    $left = FREE_CASE_COOLDOWN_MS - ($now - $last);
    if ($left > 0) {
        $pdo->rollBack();
        free_case_fail(409, 'cooldown', ['leftMs' => (int) $left, 'lastAt' => (int) $last]);
    }

    // Losowanie ważone (chance = waga).
    $total = 0.0;
    foreach ($case['items'] as $it) $total += (float) $it['chance'];
    $roll = (random_int(0, 999999999) / 1000000000) * $total;
    $picked = $case['items'][count($case['items']) - 1];
    $acc = 0.0;
    foreach ($case['items'] as $it) {
        $acc += (float) $it['chance'];
        if ($roll < $acc) { $picked = $it; break; }
    }

    $item = ['weapon' => $picked['weapon'], 'skin' => $picked['skin'], 'wear' => $picked['wear'], 'price' => (float) $picked['price'], '_id' => state_next_item_id($state)];
    $inventory = is_array($state['inventory'] ?? null) ? array_values($state['inventory']) : [];
    $inventory[] = $item;
    $state['inventory'] = $inventory;
    $cooldowns[$caseId] = $now;
    $state['freeCaseCooldowns'] = $cooldowns;
    if ($caseId === 'free') $state['freeCaseAt'] = $now;
    $state['casesOpened'] = (is_numeric($state['casesOpened'] ?? null) ? $state['casesOpened'] : 0) + 1;
    $best = is_array($state['bestPull'] ?? null) ? $state['bestPull'] : null;
    if (!$best || $item['price'] > ($best['price'] ?? 0)) {
        $state['bestPull'] = ['weapon' => $item['weapon'], 'skin' => $item['skin'], 'wear' => $item['wear'], 'price' => $item['price'], 'at' => $now];
    }

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    live_drops_record($pdo, $me['steamid'], (string) ($me['display_name'] ?? 'Gracz'), 'free_' . $caseId, [$item]);

    echo json_encode(['ok' => true, 'item' => $item, 'openedAt' => $now, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/open-free-case błąd: ' . $e->getMessage());
    free_case_fail(503, 'storage_unavailable');
}
