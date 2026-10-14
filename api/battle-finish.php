<?php
/* Koniec bitwy zgłasza KTOKOLWIEK, kto pierwszy dogląda animacji do końca
   (nie tylko host). Serwer ignoruje wynik od klienta: sam go liczy z wyników
   zapisanych przy starcie i wypłaca zwycięzców (inc/battle_economy.php),
   idempotentnie - kolejne zgłoszenia tej samej bitwy tylko zwracają
   rozliczenie. Odpowiedź zawiera aktualny stan konta WOŁAJĄCEGO. */
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_economy.php';

header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$lobbyId = (string) ($payload['lobbyId'] ?? '');

$pdo = db();
$lobby = battle_finish_lobby($pdo, $lobbyId);
if (!$lobby) {
    echo json_encode(['ok' => false]);
    exit;
}
$me = current_user();
$out = ['ok' => true, 'lobby' => $lobby];
if ($me) {
    $mine = $lobby['settlement']['payouts'][$me['steamid']] ?? null;
    if ($mine) $out['payout'] = $mine;
    $fresh = get_user($me['steamid']);
    if ($fresh && is_array($fresh['state'] ?? null)) $out['state'] = fix_state_object_fields($fresh['state']);
}
echo json_encode($out);
