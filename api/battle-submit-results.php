<?php
/* Start bitwy: wywołuje go host, gdy wszystkie miejsca są zajęte. Wyniki
   LOSUJE SERWER (inc/battle_economy.php) - to, co host-klient mógłby tu
   przysłać, jest ignorowane, więc nie da się ustawić sobie wygranej. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_economy.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$tabId = (string) ($payload['tabId'] ?? '');

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
    foreach ($lobby['rounds'] as $key) {
        if (!battle_case_def((string) $key)) {
            $pdo->rollBack();
            echo json_encode(['ok' => false]);
            exit;
        }
    }

    $lobby['results'] = battle_generate_results($lobby);
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
