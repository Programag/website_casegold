<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_bots.php';

header('Content-Type: application/json');

// Lista bitew ma zawsze ok. 5 aktywnych: ile mniej jest bitew z prawdziwym
// graczem, tyle bitew samych botów (inc/battle_bots.php) - utrzymywane
// leniwie przy każdym odpytaniu listy.
battle_bots_maintain(db());

echo json_encode(battle_public_list());
