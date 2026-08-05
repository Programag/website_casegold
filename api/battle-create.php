<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/battle.php';

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
$cost = max(0, min(BATTLE_MAX_COST, (float) ($cfg['cost'] ?? 0)));

$pdo = db();
battle_cleanup_old_finished($pdo);

$id = battle_new_id();
$slots = [];
for ($i = 0; $i < $totalPlayers; $i++) {
    $slots[] = $i === $hostSlotIdx
        ? ['type' => 'host', 'name' => $user['display_name'], 'tabId' => $tabId, 'steamid' => $user['steamid'], 'avatar' => $user['avatar'], 'boosted' => (bool) $user['boosted_drop'], 'lastSeen' => now_ms()]
        : ['type' => 'empty'];
}
$lobby = [
    'id' => $id,
    'hostTabId' => $tabId,
    'rounds' => $rounds,
    'mode' => (string) ($cfg['mode'] ?? 'standard'),
    'teams' => !empty($cfg['teams']),
    'shared' => !empty($cfg['shared']),
    'private' => !empty($cfg['private']),
    'cost' => $cost,
    'totalPlayers' => $totalPlayers,
    'slots' => $slots,
    'status' => 'lobby',
    'results' => null,
    'stakedTotal' => $cost,
    'createdAt' => now_ms(),
];

$pdo->beginTransaction();
battle_save($pdo, $lobby);
$pdo->commit();

echo json_encode(['ok' => true, 'lobby' => $lobby]);
