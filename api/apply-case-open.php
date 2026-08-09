<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';

header('Content-Type: application/json');

// 10 zł wydane = 1 EXP - ta sama stawka co XP_PER_ZL w steam-auth.js.
const CASE_OPEN_XP_PER_ZL = 1 / 10;
const CASE_OPEN_MAX_COUNT = 5;
// Tryb Jester przelicza cenę skrzynki tak, żeby zachować tę samą marżę co
// tryb standardowy (patrz computeJesterPrice() w jester-mode.js) - to
// wymaga PEŁNEJ tabeli przedmiotów danej skrzynki (cena + szansa każdego),
// której serwer celowo nie portuje (23 skrzynki, setki pozycji, sam
// katalog przedmiotów i tak nie jest tu walidowany - patrz plan migracji).
// Zamiast pełnej rewalidacji, w trybie Jester akceptujemy cenę przysłaną
// przez klienta w rozsądnych, szerokich granicach względem ceny
// standardowej - to nie jest anty-cheat, tylko siatka przed oczywiście
// zepsutym/spreparowanym payloadem (np. cost: 0.01 dla drogiej skrzynki).
const JESTER_PRICE_MIN_MULT = 0.05;
const JESTER_PRICE_MAX_MULT = 20;

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

$caseId = isset($body['caseId']) && is_string($body['caseId']) ? $body['caseId'] : null;
$count = isset($body['count']) && is_numeric($body['count']) ? (int) $body['count'] : null;
$jesterMode = !empty($body['jesterMode']);
$items = isset($body['items']) && is_array($body['items']) ? $body['items'] : null;
// Darmowe otwarcie z kodu prezentowego (api/redeem-gift-code.php dopisuje
// kredyt do state['freeCaseOpens'][caseId]) - zamiast obciążać saldo,
// zużywa jeden kredyt. Zawsze dokładnie 1 skrzynka na żądanie (osobny
// przycisk "Otwórz za darmo" na case_*.html, nie licznik Ilość 1-5), więc
// nie ma tu trybu Jester ani wpływu na cenę.
$useFreeCredit = !empty($body['useFreeCredit']);

static $casePrices = null;
if ($casePrices === null) $casePrices = require __DIR__ . '/../inc/data/case_prices.php';

if (!$caseId || !isset($casePrices[$caseId])) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_case_id']);
    exit;
}
if ($useFreeCredit && $count !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_count']);
    exit;
}
if ($count === null || $count < 1 || $count > CASE_OPEN_MAX_COUNT) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_count']);
    exit;
}
if ($items === null || count($items) !== $count) {
    http_response_code(400);
    echo json_encode(['error' => 'items_count_mismatch']);
    exit;
}

$cleanItems = [];
foreach ($items as $it) {
    if (!is_array($it)
        || !isset($it['weapon']) || !is_string($it['weapon']) || $it['weapon'] === '' || strlen($it['weapon']) > 100
        || !isset($it['skin']) || !is_string($it['skin']) || $it['skin'] === '' || strlen($it['skin']) > 100
        || !isset($it['wear']) || !is_string($it['wear']) || strlen($it['wear']) > 20
        || !isset($it['price']) || !is_numeric($it['price']) || $it['price'] < 0 || !is_finite((float) $it['price'])
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_item']);
        exit;
    }
    $cleanItems[] = [
        'weapon' => $it['weapon'],
        'skin' => $it['skin'],
        'wear' => $it['wear'],
        'price' => (float) $it['price'],
    ];
}

$normalCost = $casePrices[$caseId] * $count;
if ($useFreeCredit) {
    $cost = 0;
} elseif ($jesterMode) {
    $clientCost = isset($body['cost']) && is_numeric($body['cost']) ? (float) $body['cost'] : null;
    $min = $normalCost * JESTER_PRICE_MIN_MULT;
    $max = $normalCost * JESTER_PRICE_MAX_MULT;
    if ($clientCost === null || $clientCost <= 0 || !is_finite($clientCost) || $clientCost < $min || $clientCost > $max) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_jester_cost']);
        exit;
    }
    $cost = $clientCost;
} else {
    $cost = $normalCost;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $state = state_lock_user_for_update($pdo, $me['steamid']);
    if ($state === null) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'no_such_user']);
        exit;
    }

    if ($useFreeCredit) {
        $freeOpens = is_array($state['freeCaseOpens'] ?? null) ? $state['freeCaseOpens'] : [];
        $available = (int) ($freeOpens[$caseId] ?? 0);
        if ($available < 1) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'no_free_open']);
            exit;
        }
        $available -= 1;
        if ($available > 0) { $freeOpens[$caseId] = $available; } else { unset($freeOpens[$caseId]); }
        $state['freeCaseOpens'] = $freeOpens;
    } else {
        $balance = is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0;
        if ($balance < $cost) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'insufficient_funds', 'balance' => $balance, 'cost' => $cost]);
            exit;
        }
        $state['balance'] = $balance - $cost;
    }
    $inventory = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
    $bestPull = is_array($state['bestPull'] ?? null) ? $state['bestPull'] : null;
    $wonItems = [];
    foreach ($cleanItems as $it) {
        $it['_id'] = state_next_item_id($state);
        $inventory[] = $it;
        $wonItems[] = $it;
        // recordPull() z steam-auth.js po stronie serwera - "najlepszy los"
        // pokazywany na profilu/w topce.
        if (!$bestPull || $it['price'] > $bestPull['price']) {
            $bestPull = ['weapon' => $it['weapon'], 'skin' => $it['skin'], 'wear' => $it['wear'], 'price' => $it['price'], 'at' => now_ms()];
        }
    }
    $state['inventory'] = $inventory;
    $state['bestPull'] = $bestPull;
    $state['casesOpened'] = (is_numeric($state['casesOpened'] ?? null) ? $state['casesOpened'] : 0) + $count;
    $state['spentCases'] = (is_numeric($state['spentCases'] ?? null) ? $state['spentCases'] : 0) + $cost;
    $state['xp'] = (is_numeric($state['xp'] ?? null) ? $state['xp'] : 0) + $cost * CASE_OPEN_XP_PER_ZL;
    state_bump_level_watermark($state);

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'items' => $wonItems, 'cost' => $cost, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/apply-case-open błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
