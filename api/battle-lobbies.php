<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';
require_once __DIR__ . '/../inc/battle_economy.php';
require_once __DIR__ . '/../inc/battle_bots.php';

header('Content-Type: application/json');

// Lista bitew ma zawsze ok. 5 aktywnych: ile mniej jest bitew z prawdziwym
// graczem, tyle bitew samych botów (inc/battle_bots.php) - utrzymywane
// leniwie przy każdym odpytaniu listy.
// Najpierw rozlicz bitwy, których animacja dawno się skończyła, a nikt nie
// zgłosił końca (np. gracz zamknął kartę) - inaczej wiszą na liście jako
// "w trakcie" i liczą się jako aktywne.
battle_settle_overdue(db());
battle_sweep_waiting(db());
battle_bots_maintain(db());

echo json_encode(battle_public_list());
