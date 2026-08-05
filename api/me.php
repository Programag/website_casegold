<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/niefart.php';

header('Content-Type: application/json');

// Wołane synchronicznie na KAŻDYM wejściu na stronę (patrz steam-auth.js) -
// najlepsze miejsce na "leniwe" sprawdzenie wypłat dnia (niefart, najaktywniejszy
// gracz), bo Node robił to przez stały proces w tle, którego PHP nie ma.
check_niefart_payout();
check_active_player_payout();

$u = current_user();
if (!$u) {
    echo json_encode(['loggedIn' => false]);
    exit;
}

$adminId = cs2_config()['admin_steamid'];
$isAdmin = $adminId && $u['steamid'] === $adminId;

// Tania, deterministyczna operacja w pamięci - trwały zapis i tak nastąpi
// przy najbliższym PUT /api/state (patrz komentarz w server.js).
$u['state'] = ensure_level_watermark($u['state']);

echo json_encode([
    'loggedIn' => true,
    'user' => public_user($u),
    'isAdmin' => $isAdmin,
]);
