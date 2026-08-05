<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$idx = (int) ($payload['idx'] ?? -1);
$tabId = (string) ($payload['tabId'] ?? '');
$requestedName = $payload['name'] ?? null;

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $lobbyId);
    if (!$lobby || $lobby['hostTabId'] !== $tabId || $lobby['status'] !== 'lobby') {
        $pdo->rollBack();
        echo json_encode(['ok' => false]);
        exit;
    }
    if (!isset($lobby['slots'][$idx]) || ($lobby['slots'][$idx]['type'] ?? '') !== 'empty') {
        $pdo->rollBack();
        echo json_encode(['ok' => false]);
        exit;
    }

    $used = array_map(fn($s) => $s['name'], array_filter($lobby['slots'], fn($s) => ($s['type'] ?? '') === 'bot'));
    $available = array_values(array_diff(BATTLE_BOT_NAMES, $used));
    // Klient może poprosić o konkretne imię z panelu wyboru bota - akceptuj
    // je tylko, jeśli jest na liście dozwolonych i jeszcze wolne, inaczej
    // dobierz PRAWDZIWIE losowe spośród wolnych.
    if (is_string($requestedName) && in_array($requestedName, BATTLE_BOT_NAMES, true) && !in_array($requestedName, $used, true)) {
        $name = $requestedName;
    } elseif (count($available) > 0) {
        $name = $available[random_int(0, count($available) - 1)];
    } else {
        $name = 'Bot ' . ($idx + 1);
    }

    $lobby['slots'][$idx] = ['type' => 'bot', 'name' => $name, 'tabId' => null];
    battle_save($pdo, $lobby);
    $pdo->commit();
    echo json_encode(['ok' => true, 'lobby' => $lobby]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-add-bot błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
