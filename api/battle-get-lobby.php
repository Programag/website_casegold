<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');

// Odpytywane na żywo (~co 1.5s, patrz battle-poll-shim.js) przez KAŻDEGO,
// kto ma tę bitwę otwartą - zarówno usadzonych graczy, jak i czystych
// widzów (wejście w udostępniony link). Jeśli podano tabId I jest on
// usadzony w tym lobby, aktualizuje jego "lastSeen" (odpowiednik tego, co
// w Node dawał za darmo żywy socket) - ale NIGDY nie odmawia widoku tylko
// dlatego, że tabId nie jest usadzony (to by zepsuło zwykłe podglądanie).
// Wymóg "musisz być usadzony" ma tylko jednorazowy battle-resume.php,
// wołany raz przy starcie strony.
$id = (string) ($_GET['id'] ?? '');
$tabId = isset($_GET['tabId']) ? (string) $_GET['tabId'] : null;

if ($id === '') { echo json_encode(['ok' => false, 'reason' => 'not_found']); exit; }

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $id);
    if (!$lobby) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'reason' => 'not_found']);
        exit;
    }

    [$lobby, $changed, $deleted] = battle_sweep_stale($lobby);
    if ($deleted) {
        battle_delete($pdo, $id);
        $pdo->commit();
        echo json_encode(['ok' => false, 'reason' => 'not_found']);
        exit;
    }

    if ($tabId !== null && $tabId !== '' && battle_find_slot_idx($lobby, $tabId) !== -1) {
        $lobby = battle_touch_slot($lobby, $tabId);
        $changed = true;
    }

    if ($changed) battle_save($pdo, $lobby);
    $pdo->commit();
    echo json_encode(['ok' => true, 'lobby' => $lobby]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-get-lobby błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
