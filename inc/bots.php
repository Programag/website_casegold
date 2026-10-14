<?php
/* Profile botów Case Battle. Boty są zwykłymi wierszami w tabeli users
   (steamid "bot_<id>", slug "bot-<id>"), więc strona profilu i api/profile.php
   działają dla nich bez zmian; zostają tylko wyłączone z rankingów i listy
   admina. Statystyki (poziom, bitwy, najlepszy drop) rosną z każdą
   rozegraną bitwą - patrz battle_settle() w inc/battle_economy.php. */
require_once __DIR__ . '/state_ops.php';

// Nazwy MUSZĄ zgadzać się z BATTLE_BOT_NAMES w inc/battle.php i BOT_ROSTER w battle.html.
const BOT_ROSTER = [
    ['id' => 'hacker', 'name' => 'Hacker', 'avatar' => '/hacker_bot.png'],
    ['id' => 'guy',    'name' => 'Guy',    'avatar' => '/guy_bot.png'],
    ['id' => 'chad',   'name' => 'Chad',   'avatar' => '/chad_bot.png'],
    ['id' => 'steve',  'name' => 'Steve',  'avatar' => '/steve_bot.png'],
    ['id' => 'clown',  'name' => 'Clown',  'avatar' => '/clown_bot.png'],
    ['id' => 'shiba',  'name' => 'Shiba',  'avatar' => '/shiba_bot.png'],
];

function bot_steamid(string $id): string { return 'bot_' . $id; }
function bot_slug(string $id): string { return 'bot-' . $id; }
function bot_is_steamid(?string $steamid): bool { return is_string($steamid) && str_starts_with($steamid, 'bot_'); }

function bot_by_name(string $name): ?array {
    foreach (BOT_ROSTER as $b) if ($b['name'] === $name) return $b;
    return null;
}

/* Deterministyczny generator (ziarno = id bota), żeby profil startowy każdego
   bota był stały i nie ruszał globalnego RNG. */
function bot_seeded(string $id, string $salt, int $min, int $max): int {
    $h = crc32($id . ':' . $salt);
    return $min + ($h % ($max - $min + 1));
}

/* Zakłada brakujące profile botów (INSERT IGNORE - idempotentne i tanie). */
function bots_ensure_profiles(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $cases = require __DIR__ . '/data/battle_cases.php';
    $pricey = [];
    foreach ($cases as $c) foreach ($c['items'] as $it) if ($it['price'] >= 300) $pricey[] = $it;
    $ins = $pdo->prepare('INSERT IGNORE INTO users (steamid, display_name, avatar, profile_url, slug, created_at, boosted_drop, state) VALUES (?, ?, ?, NULL, ?, ?, 0, ?)');
    foreach (BOT_ROSTER as $b) {
        $id = $b['id'];
        $level = bot_seeded($id, 'lvl', 14, 62);
        $played = bot_seeded($id, 'played', 220, 2600);
        $won = (int) round($played * (bot_seeded($id, 'wr', 44, 56) / 100));
        $best = $pricey ? $pricey[bot_seeded($id, 'best', 0, count($pricey) - 1)] : null;
        $state = array_merge(state_default_shape(), [
            'balance' => bot_seeded($id, 'bal', 5000, 400000),
            'xp' => xp_for_level($level) + bot_seeded($id, 'xpx', 0, 300),
            'level' => $level,
            'levelWatermark' => $level,
            'battlesPlayed' => $played,
            'battlesWon' => $won,
            'casesOpened' => $played * bot_seeded($id, 'cpb', 2, 5),
            'xpScaleMigratedV2' => true,
            'bestPull' => $best ? ['weapon' => $best['weapon'], 'skin' => $best['skin'], 'wear' => $best['wear'], 'price' => $best['price'], 'at' => now_ms() - bot_seeded($id, 'bestat', 1, 60) * 86400000] : null,
            'updatedAt' => now_ms() - bot_seeded($id, 'upd', 60, 7200) * 1000,
        ]);
        $ins->execute([bot_steamid($id), $b['name'], $b['avatar'], bot_slug($id), now_ms() - bot_seeded($id, 'created', 40, 420) * 86400000, json_encode($state)]);
    }
    $done = true;
}
