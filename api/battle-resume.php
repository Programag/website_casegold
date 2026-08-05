<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');

// Wołane RAZ przy starcie strony (odpowiednik socket.on("connect") z Node)
// - "czy ta karta faktycznie ma jeszcze miejsce w lobby zapamiętanym w
// sessionStorage". W przeciwieństwie do battle-get-lobby.php (podglądanie),
// tu brak miejsca to jawna porażka - klient wtedy czyści zapamiętane
// myLobbyId i traktuje to jak zwykłe wejście na stronę od nowa.
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$id = (string) ($payload['lobbyId'] ?? '');
$tabId = (string) ($payload['tabId'] ?? '');

if ($id === '' || $tabId === '') { echo json_encode(['ok' => false]); exit; }

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $id);
    if (!$lobby) {
        $pdo->rollBack();
        echo json_encode(['ok' => false]);
        exit;
    }
    [$lobby, $changed, $deleted] = battle_sweep_stale($lobby);
    if ($deleted) {
        battle_delete($pdo, $id);
        $pdo->commit();
        echo json_encode(['ok' => false]);
        exit;
    }
    if (battle_find_slot_idx($lobby, $tabId) === -1) {
        if ($changed) battle_save($pdo, $lobby);
        $pdo->commit();
        echo json_encode(['ok' => false]);
        exit;
    }
    $lobby = battle_touch_slot($lobby, $tabId);
    battle_save($pdo, $lobby);
    $pdo->commit();
    echo json_encode(['ok' => true, 'lobby' => $lobby]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-resume błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
