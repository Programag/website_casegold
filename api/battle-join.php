<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'reason' => 'not_logged_in']); exit; }

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$idx = (int) ($payload['idx'] ?? -1);
$tabId = (string) ($payload['tabId'] ?? '');
$name = $payload['name'] ?? null;

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $lobbyId);
    if (!$lobby || $lobby['status'] !== 'lobby') {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'reason' => 'not_found']);
        exit;
    }
    if (!isset($lobby['slots'][$idx]) || ($lobby['slots'][$idx]['type'] ?? '') !== 'empty') {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'reason' => 'taken']);
        exit;
    }
    foreach ($lobby['slots'] as $s) {
        if (($s['tabId'] ?? null) === $tabId) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'reason' => 'already_in']);
            exit;
        }
    }
    foreach ($lobby['slots'] as $s) {
        if (($s['steamid'] ?? null) === $user['steamid']) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'reason' => 'already_seated']);
            exit;
        }
    }

    $playerName = $user['display_name'] ?: (string) ($name ?: 'Gracz');
    $lobby['slots'][$idx] = [
        'type' => 'player',
        'name' => $playerName,
        'tabId' => $tabId,
        'steamid' => $user['steamid'],
        'avatar' => $user['avatar'],
        'boosted' => (bool) $user['boosted_drop'],
        'lastSeen' => now_ms(),
    ];
    $lobby['stakedTotal'] = ($lobby['stakedTotal'] ?? $lobby['cost']) + $lobby['cost'];

    battle_save($pdo, $lobby);
    $pdo->commit();
    echo json_encode(['ok' => true, 'lobby' => $lobby]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-join błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
