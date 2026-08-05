<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$tabId = (string) ($payload['tabId'] ?? '');
$outcome = $payload['outcome'] ?? null;

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $lobbyId);
    if (!$lobby || $lobby['hostTabId'] !== $tabId) {
        $pdo->rollBack();
        echo json_encode(['ok' => false]);
        exit;
    }

    $lobby['status'] = 'finished';
    $lobby['outcome'] = $outcome;
    $lobby['finishedAt'] = now_ms();

    battle_save($pdo, $lobby);
    $pdo->commit();

    // Poza transakcją zapisu lobby - to osobny zapis do daily_top (własna
    // transakcja w battle_record_daily_top), zapasowy TTL sprząta resztę.
    battle_record_daily_top($lobby);

    echo json_encode(['ok' => true, 'lobby' => $lobby]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-finish błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
