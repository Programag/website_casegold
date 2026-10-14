<?php
/* Ulepszacz - w całości po stronie serwera: serwer sam sprawdza własność
   przedmiotów i saldo, bierze cenę celu z własnej tabeli
   (inc/data/upgrade_targets.php), liczy szansę, LOSUJE wynik i księguje
   zmianę salda/ekwipunku. Klient dostaje tylko wynik do animacji. Stałe
   identyczne z upgrade.html (HOUSE_EDGE/MAX_CHANCE/MAX_SELECTABLE_CHANCE). */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_ops.php';

header('Content-Type: application/json');

const UPGRADE_HOUSE_EDGE = 0.08;
const UPGRADE_MAX_CHANCE = 95;
const UPGRADE_MAX_SELECTABLE_CHANCE = 92;

function upgrade_fail(int $status, string $code): void {
    http_response_code($status);
    echo json_encode(['error' => $code]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') upgrade_fail(405, 'method_not_allowed');
$me = current_user();
if (!$me) upgrade_fail(401, 'not_logged_in');

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$targets = require __DIR__ . '/../inc/data/upgrade_targets.php';
$targetIdx = isset($body['targetIndex']) && is_numeric($body['targetIndex']) ? (int) $body['targetIndex'] : -1;
$target = $targets[$targetIdx] ?? null;
if (!$target) upgrade_fail(400, 'bad_target');
// Lista celów na kliencie i serwerze musi się zgadzać - inaczej odrzucamy,
// zamiast ulepszać na inny przedmiot niż ten, który gracz wybrał.
if (($body['weapon'] ?? null) !== $target['weapon'] || ($body['skin'] ?? null) !== $target['skin'] || ($body['wear'] ?? null) !== $target['wear']) {
    upgrade_fail(400, 'target_mismatch');
}

$itemIds = [];
if (isset($body['itemIds']) && is_array($body['itemIds'])) {
    foreach ($body['itemIds'] as $id) {
        if (is_numeric($id)) $itemIds[] = (int) $id;
    }
}
if (count($itemIds) !== count(array_unique($itemIds)) || count($itemIds) > 200) upgrade_fail(400, 'bad_items');
$cash = isset($body['cash']) && is_numeric($body['cash']) ? (float) $body['cash'] : 0.0;
if (!is_finite($cash) || $cash < 0) upgrade_fail(400, 'bad_cash');

$pdo = db();
$pdo->beginTransaction();
try {
    $state = state_lock_user_for_update($pdo, $me['steamid']);
    if ($state === null) {
        $pdo->rollBack();
        upgrade_fail(404, 'no_such_user');
    }

    $inventory = is_array($state['inventory'] ?? null) ? array_values($state['inventory']) : [];
    $wanted = array_flip($itemIds);
    $keep = [];
    $itemsTotal = 0.0;
    $taken = 0;
    foreach ($inventory as $it) {
        $iid = is_array($it) && isset($it['_id']) && is_numeric($it['_id']) ? (int) $it['_id'] : null;
        if ($iid !== null && isset($wanted[$iid])) {
            $itemsTotal += is_numeric($it['price'] ?? null) && is_finite((float) $it['price']) ? max(0.0, (float) $it['price']) : 0.0;
            unset($wanted[$iid]); // po jednym przedmiocie na ID (kolizje starych ID)
            $taken++;
        } else {
            $keep[] = $it;
        }
    }
    if ($taken !== count($itemIds)) {
        $pdo->rollBack();
        upgrade_fail(409, 'items_missing');
    }

    $balance = is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0.0;
    if ($cash > $balance + 1e-9) {
        $pdo->rollBack();
        upgrade_fail(409, 'insufficient_funds');
    }

    $inTotal = $itemsTotal + $cash;
    $targetPrice = (float) $target['price'];
    if ($inTotal <= 0 || $targetPrice <= 0 || $targetPrice <= $inTotal) {
        $pdo->rollBack();
        upgrade_fail(400, 'bad_bet');
    }
    $chance = min(UPGRADE_MAX_CHANCE, ($inTotal / $targetPrice) * (1 - UPGRADE_HOUSE_EDGE) * 100);
    if ($chance > UPGRADE_MAX_SELECTABLE_CHANCE) {
        $pdo->rollBack();
        upgrade_fail(400, 'chance_too_high');
    }

    $won = (random_int(0, 999999999) / 10000000) < $chance; // równomiernie w [0,100)

    $state['balance'] = $balance - $cash;
    $state['upgradeClicks'] = (is_numeric($state['upgradeClicks'] ?? null) ? $state['upgradeClicks'] : 0) + 1;
    $state['spentUpgrader'] = (is_numeric($state['spentUpgrader'] ?? null) ? $state['spentUpgrader'] : 0) + $inTotal;

    $wonItem = null;
    if ($won) {
        $wonItem = ['weapon' => $target['weapon'], 'skin' => $target['skin'], 'wear' => $target['wear'], 'price' => $targetPrice, '_id' => state_next_item_id($state)];
        $keep[] = $wonItem;
        $best = is_array($state['bestPull'] ?? null) ? $state['bestPull'] : null;
        if (!$best || $targetPrice > ($best['price'] ?? 0)) {
            $state['bestPull'] = ['weapon' => $target['weapon'], 'skin' => $target['skin'], 'wear' => $target['wear'], 'price' => $targetPrice, 'at' => now_ms()];
        }
    }
    $state['inventory'] = $keep;

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'won' => $won, 'chance' => $chance, 'inTotal' => $inTotal, 'item' => $wonItem, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/apply-upgrade błąd: ' . $e->getMessage());
    upgrade_fail(503, 'storage_unavailable');
}
