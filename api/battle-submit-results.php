<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$tabId = (string) ($payload['tabId'] ?? '');
$results = $payload['results'] ?? null;

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $lobbyId);
    if (!$lobby || $lobby['hostTabId'] !== $tabId || $lobby['status'] !== 'lobby') {
        $pdo->rollBack();
        echo json_encode(['ok' => false]);
        exit;
    }
    foreach ($lobby['slots'] as $s) {
        if (($s['type'] ?? '') === 'empty') {
            $pdo->rollBack();
            echo json_encode(['ok' => false]);
            exit;
        }
    }
    if (!is_array($results) || count($results) !== count($lobby['slots'])) {
        $pdo->rollBack();
        echo json_encode(['ok' => false]);
        exit;
    }

    $lobby['results'] = $results;
    $lobby['status'] = 'running';
    $lobby['startedAt'] = now_ms();

    battle_save($pdo, $lobby);
    $pdo->commit();
    echo json_encode(['ok' => true, 'lobby' => $lobby]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-submit-results błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
