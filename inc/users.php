<?php
require_once __DIR__ . '/db.php';

/* ---- slugi profili (np. /profile.php?u=aleksio) ---- */
function slugify(string $name): string {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($ascii === false || $ascii === null) $ascii = $name;
    $slug = strtolower($ascii);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return mb_substr($slug, 0, 32);
}

function assign_slug(string $steamid, string $displayName): string {
    $base = slugify($displayName);
    if ($base === '') $base = 'gracz';
    $taken = function (string $s) use ($steamid): bool {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE slug = ? AND steamid != ? LIMIT 1');
        $stmt->execute([$s, $steamid]);
        return (bool) $stmt->fetchColumn();
    };
    if (!$taken($base)) return $base;
    $n = 2;
    while ($taken("$base-$n")) $n++;
    return "$base-$n";
}

/* ---- poziom jako "wskaźnik wodny" - patrz odpowiednik w server.js ---- */
function xp_for_level(float $level): float {
    return 1000 * (pow(1.1, $level) - 1);
}
function level_for_xp(float $totalXp): int {
    $xp = $totalXp > 0 ? $totalXp : 0;
    $EPS = 1e-9;
    $level = (int) floor(log($xp / 1000 + 1) / log(1.1) + $EPS);
    while (xp_for_level($level + 1) - $EPS <= $xp) $level++;
    while ($level > 0 && xp_for_level($level) - $EPS > $xp) $level--;
    return $level;
}
function ensure_level_watermark(?array $state): ?array {
    if ($state === null) return null;
    if (empty($state['xpScaleMigratedV2'])) {
        $rawXp = is_numeric($state['xp'] ?? null) ? (float) $state['xp'] : 0;
        $state['xp'] = $rawXp * 10;
        $state['xpScaleMigratedV2'] = true;
    }
    if (isset($state['levelWatermark']) && is_numeric($state['levelWatermark'])) return $state;
    $xp = is_numeric($state['xp'] ?? null) ? (float) $state['xp'] : 0;
    $recovered = level_for_xp($xp);
    $stored = is_numeric($state['level'] ?? null) ? (float) $state['level'] : 0;
    $state['levelWatermark'] = max($stored, $recovered, 0);
    return $state;
}

/* ---- odczyt/zapis wierszy users ---- */
function row_to_user(array $row): array {
    $row['state'] = $row['state'] !== null ? json_decode($row['state'], true) : null;
    $row['boosted_drop'] = (bool) $row['boosted_drop'];
    return $row;
}

function get_user(string $steamid): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE steamid = ?');
    $stmt->execute([$steamid]);
    $row = $stmt->fetch();
    return $row ? row_to_user($row) : null;
}

function find_user_by_slug(string $slug): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? row_to_user($row) : null;
}

/* json_decode(..., true) zamienia {} na pustą tablicę PHP - przy ponownym
   json_encode() taka tablica wraca jako [] zamiast {}, więc klient (który
   traktuje questClaims jak zwykły obiekt JS) dostałby tablicę zamiast
   obiektu. Wołane tuż przed KAŻDYM zwróceniem stanu do klienta (nie tylko
   przy zapisie w api/state.php), bo błąd wraca przy każdym odczycie z bazy. */
function fix_state_object_fields(?array $state): ?array {
    if ($state === null) return null;
    if (isset($state['questClaims']) && is_array($state['questClaims']) && empty($state['questClaims'])) {
        $state['questClaims'] = new stdClass();
    }
    if (isset($state['freeCaseOpens']) && is_array($state['freeCaseOpens']) && empty($state['freeCaseOpens'])) {
        $state['freeCaseOpens'] = new stdClass();
    }
    return $state;
}

/* Kształt zwracany do klienta - odpowiednik publicUser() z server.js. */
function public_user(array $u): array {
    return [
        'steamid' => $u['steamid'],
        'displayName' => $u['display_name'],
        'avatar' => $u['avatar'],
        'profileUrl' => $u['profile_url'],
        'createdAt' => $u['created_at'] ?? null,
        'slug' => $u['slug'] ?? null,
        'state' => fix_state_object_fields($u['state'] ?? null),
    ];
}

/* Upsert po udanym logowaniu Steam - odpowiednik zwrotnicy SteamStrategy. */
function upsert_user_from_steam(string $steamid, string $displayName, ?string $avatar, ?string $profileUrl): array {
    $existing = get_user($steamid);
    $slug = $existing['slug'] ?? assign_slug($steamid, $displayName);
    $createdAt = $existing['created_at'] ?? now_ms();
    $stmt = db()->prepare('
        INSERT INTO users (steamid, display_name, avatar, profile_url, slug, created_at, boosted_drop, state)
        VALUES (:steamid, :display_name, :avatar, :profile_url, :slug, :created_at, :boosted_drop, :state)
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            avatar = VALUES(avatar),
            profile_url = VALUES(profile_url)
    ');
    $stmt->execute([
        ':steamid' => $steamid,
        ':display_name' => $displayName,
        ':avatar' => $avatar,
        ':profile_url' => $profileUrl,
        ':slug' => $slug,
        ':created_at' => $createdAt,
        ':boosted_drop' => ($existing['boosted_drop'] ?? false) ? 1 : 0,
        ':state' => $existing && $existing['state'] !== null ? json_encode($existing['state']) : null,
    ]);
    return get_user($steamid);
}
