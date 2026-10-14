<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_economy.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');
$tabId = (string) ($payload['tabId'] ?? '');
$me = current_user();

$pdo = db();
$pdo->beginTransaction();
try {
    $lobby = battle_load_for_update($pdo, $lobbyId);
    if (!$lobby) {
        $pdo->rollBack();
        echo json_encode(['ok' => true]);
        exit;
    }

    // Wyjście z poczekalni oddaje wpisowe (pobrane przez serwer przy
    // utworzeniu/dołączeniu): host zamykając lobby zwraca je WSZYSTKIM
    // usadzonym, zwykły gracz - tylko sobie. W trakcie/po bitwie wyjście
    // niczego nie zwraca (gra toczy się dalej i jest rozliczana na końcu).
    if ($lobby['hostTabId'] === $tabId && $lobby['status'] === 'lobby') {
        battle_refund_all_seated($pdo, $lobby);
        battle_delete($pdo, $lobbyId);
        $state = $me ? state_lock_user_for_update($pdo, $me['steamid']) : null;
        $pdo->commit();
        echo json_encode(['ok' => true, 'closed' => true, 'state' => fix_state_object_fields($state)]);
        exit;
    }

    $idx = battle_find_slot_idx($lobby, $tabId);
    if ($idx !== -1 && $lobby['status'] === 'lobby') {
        $slot = $lobby['slots'][$idx];
        if (!empty($slot['steamid']) && ($slot['paid'] ?? 0) > 0) {
            battle_refund_user($pdo, (string) $slot['steamid'], (float) $slot['paid']);
        }
        $lobby['slots'][$idx] = ['type' => 'empty'];
        battle_save($pdo, $lobby);
    }
    $state = $me ? state_lock_user_for_update($pdo, $me['steamid']) : null;
    $pdo->commit();
    echo json_encode(['ok' => true, 'state' => fix_state_object_fields($state)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('battle-leave błąd: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
