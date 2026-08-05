<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$tabId = (string) ($payload['tabId'] ?? '');

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $lobbyId);
    if (!$lobby) {
        $pdo->rollBack();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($lobby['hostTabId'] === $tabId && $lobby['status'] === 'lobby') {
        battle_delete($pdo, $lobbyId);
        $pdo->commit();
        echo json_encode(['ok' => true, 'closed' => true]);
        exit;
    }

    $idx = battle_find_slot_idx($lobby, $tabId);
    if ($idx !== -1 && $lobby['status'] === 'lobby') {
        $lobby['slots'][$idx] = ['type' => 'empty'];
        battle_save($pdo, $lobby);
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-leave błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
