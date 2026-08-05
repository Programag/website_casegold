<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/battle.php';

header('Content-Type: application/json');
echo json_encode(battle_public_list());
