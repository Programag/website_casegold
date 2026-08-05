<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/niefart.php';

header('Content-Type: application/json');

$rows = db()->query('SELECT slug, display_name, avatar, state FROM users WHERE slug IS NOT NULL')->fetchAll();
$list = [];
foreach ($rows as $r) {
    $st = $r['state'] !== null ? json_decode($r['state'], true) : [];
    $list[] = [
        'slug' => $r['slug'],
        'displayName' => $r['display_name'],
        'avatar' => $r['avatar'],
        'balance' => is_numeric($st['balance'] ?? null) ? $st['balance'] : 0,
        'upgradeClicks' => is_numeric($st['upgradeClicks'] ?? null) ? $st['upgradeClicks'] : 0,
        'casesOpened' => is_numeric($st['casesOpened'] ?? null) ? $st['casesOpened'] : 0,
    ];
}

$top = function (string $field) use ($list): array {
    $sorted = $list;
    usort($sorted, fn($a, $b) => $b[$field] <=> $a[$field]);
    $sorted = array_slice($sorted, 0, 20);
    return array_map(fn($u) => ['slug' => $u['slug'], 'displayName' => $u['displayName'], 'avatar' => $u['avatar'], 'value' => $u[$field]], $sorted);
};

echo json_encode([
    'balance' => $top('balance'),
    'upgrades' => $top('upgradeClicks'),
    'cases' => $top('casesOpened'),
    // Ranking "na żywo" za DZISIAJ (w toku) - patrz check_niefart_payout()/
    // check_active_player_payout() dla faktycznej, jednorazowej wypłaty za
    // dzień, który się skończył.
    'niefart' => niefart_ranking_for_date(warsaw_date_string(now_ms())),
    'active' => active_player_ranking_for_date(warsaw_date_string(now_ms())),
]);
