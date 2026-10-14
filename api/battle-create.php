<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_economy.php';

header('Content-Type: application/json');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'reason' => 'not_logged_in']); exit; }

$cfg = json_decode(file_get_contents('php://input'), true);
if (!is_array($cfg)) { echo json_encode(['ok' => false]); exit; }

$tabId = (string) ($cfg['tabId'] ?? '');
$rounds = is_array($cfg['rounds'] ?? null) ? array_map('strval', array_slice($cfg['rounds'], 0, BATTLE_MAX_ROUNDS)) : [];
if ($tabId === '' || count($rounds) === 0) { echo json_encode(['ok' => false]); exit; }

$totalPlayers = max(2, min(BATTLE_MAX_PLAYERS, (int) ($cfg['totalPlayers'] ?? 2) ?: 2));
$hostSlotIdx = max(0, min($totalPlayers - 1, (int) ($cfg['hostSlotIdx'] ?? 0)));
// Koszt wejścia liczy SERWER z cen skrzynek (kwota od klienta jest ignorowana)
// i od razu pobiera go z konta hosta - patrz inc/battle_economy.php.
$cost = battle_entry_cost($rounds);
if ($cost === null || $cost <= 0 || $cost > BATTLE_MAX_COST) { echo json_encode(['ok' => false, 'reason' => 'bad_rounds']); exit; }

$pdo = db();
battle_cleanup_old_finished($pdo);

$id = battle_new_id();
$slots = [];
for ($i = 0; $i < $totalPlayers; $i++) {
    $slots[] = $i === $hostSlotIdx
        ? ['type' => 'host', 'name' => $user['display_name'], 'tabId' => $tabId, 'steamid' => $user['steamid'], 'avatar' => $user['avatar'], 'boosted' => (bool) $user['boosted_drop'], 'lastSeen' => now_ms(), 'paid' => $cost]
        : ['type' => 'empty'];
}
$lobby = [
    'id' => $id,
    'hostTabId' => $tabId,
    'rounds' => $rounds,
    'mode' => in_array($cfg['mode'] ?? '', ['standard', 'underdog', 'decider', 'under-decider', 'burst', 'under-burst'], true) ? $cfg['mode'] : 'standard',
    'teams' => !empty($cfg['teams']),
    'shared' => !empty($cfg['shared']),
    'private' => !empty($cfg['private']),
    'cost' => $cost,
    'totalPlayers' => $totalPlayers,
    'slots' => $slots,
    'status' => 'lobby',
    'results' => null,
    'stakedTotal' => $cost,
    'serverEconomy' => true,
    'createdAt' => now_ms(),
];

$pdo->beginTransaction();
try {
    $state = battle_charge_user($pdo, $user['steamid'], $cost);
    if ($state === null) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'reason' => 'insufficient_funds']);
        exit;
    }
    battle_save($pdo, $lobby);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-create błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true, 'lobby' => $lobby, 'state' => fix_state_object_fields($state)]);
