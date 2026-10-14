<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_economy.php';

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
    // Lobby z poprzedniej, klienckiej ekonomii (bez serverEconomy) nie przyjmuje już
    // nowych graczy - wpisowe musi pobrać serwer.
    if (!$lobby || $lobby['status'] !== 'lobby' || empty($lobby['serverEconomy'])) {
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

    // Wpisowe pobiera serwer, atomowo z zajęciem miejsca (lobby -> gracz).
    $state = battle_charge_user($pdo, $user['steamid'], (float) $lobby['cost']);
    if ($state === null) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'reason' => 'insufficient_funds']);
        exit;
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
        'paid' => (float) $lobby['cost'],
    ];
    $lobby['stakedTotal'] = ($lobby['stakedTotal'] ?? $lobby['cost']) + $lobby['cost'];

    battle_save($pdo, $lobby);
    $pdo->commit();
    echo json_encode(['ok' => true, 'lobby' => $lobby, 'state' => fix_state_object_fields($state)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-join błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
