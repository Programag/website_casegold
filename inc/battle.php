<?php
require_once __DIR__ . '/db.php';

const BATTLE_MAX_ROUNDS = 40;
const BATTLE_MAX_PLAYERS = 4;
// Musi zostawić spory zapas nad realnym maksimum (Angel Case w trybie
// Jester × BATTLE_MAX_ROUNDS ≈ 7,37 mln zł), bo to tylko zabezpieczenie
// przed zniekształconym/złośliwym payloadem, a nie sensowny limit gry -
// za niski próg (poprzednio 1 000 000) po cichu obcinał realny koszt
// bitwy do 1 mln zł w battle-create.php, przez co drugi gracz płacił za
// wejście za mało (lobby.cost), a zwrot przy remisie też był zaniżony.
const BATTLE_MAX_COST = 50000000;
// W Node wykrywał to sam Socket.IO (disconnect). HTTP polling nie ma
// odpowiednika "rozłączenia" - sloty starsze niż ten limit bez żadnego
// pollu z ich tabId są leniwie sprzątane przy najbliższym dotknięciu lobby
// (patrz battle_sweep_stale). Dłuższy niż w Node (8s), bo tu "brak pollu"
// może też znaczyć zwykłe krótkie zawieszenie karty w tle, nie tylko realne
// zamknięcie/utratę połączenia.
const BATTLE_DISCONNECT_GRACE_MS = 15000;
const BATTLE_FINISHED_TTL_MS = 60 * 60 * 1000;
const BATTLE_BOT_NAMES = ["Hacker", "Guy", "Chad", "Steve", "Clown", "Shiba"];

function battle_new_id(): string {
    return 'lobby-' . bin2hex(random_bytes(6));
}

/* Wołane WEWNĄTRZ transakcji, tuż przed mutacją - blokuje wiersz, żeby dwie
   równoległe akcje (np. dwóch graczy klikających "dołącz" w to samo puste
   miejsce) nie nadpisały się nawzajem - to, co w Node dawał za darmo
   jednowątkowy event loop. */
function battle_load_for_update(PDO $pdo, string $id): ?array {
    $stmt = $pdo->prepare('SELECT data FROM battle_lobbies WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? json_decode($row['data'], true) : null;
}
function battle_load(string $id): ?array {
    $stmt = db()->prepare('SELECT data FROM battle_lobbies WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? json_decode($row['data'], true) : null;
}
function battle_save(PDO $pdo, array $lobby): void {
    $now = now_ms();
    $stmt = $pdo->prepare('
        INSERT INTO battle_lobbies (id, status, is_private, data, created_at, updated_at)
        VALUES (:id, :status, :is_private, :data, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE status = VALUES(status), is_private = VALUES(is_private), data = VALUES(data), updated_at = VALUES(updated_at)
    ');
    $stmt->execute([
        ':id' => $lobby['id'],
        ':status' => $lobby['status'],
        ':is_private' => !empty($lobby['private']) ? 1 : 0,
        ':data' => json_encode($lobby),
        ':created_at' => $lobby['createdAt'] ?? $now,
        ':updated_at' => $now,
    ]);
}
function battle_delete(PDO $pdo, string $id): void {
    $stmt = $pdo->prepare('DELETE FROM battle_lobbies WHERE id = ?');
    $stmt->execute([$id]);
}

/* "lobby" = poczekalnia (widoczna z przyciskiem Dołącz), "running" = bitwa w
   toku (widoczna tylko do oglądania) - odpowiednik publicLobbies() z Node. */
function battle_public_list(): array {
    $rows = db()->query("SELECT data FROM battle_lobbies WHERE status IN ('lobby','running') AND is_private = 0")->fetchAll();
    return array_map(fn($r) => json_decode($r['data'], true), $rows);
}

/* Sprząta sloty, z których nikt nie odpytał (nie "pollował") od dłużej niż
   BATTLE_DISCONNECT_GRACE_MS - odpowiednik schedulePendingFree() z Node,
   tylko sprawdzane leniwie zamiast timerem w tle. Host bez świeżego pollu w
   poczekalni kasuje całe lobby (tak jak jawne battle:leave hosta); zwykły
   gracz tylko zwalnia własne miejsce. Zwraca [lobby, changed, deleted]. */
function battle_sweep_stale(array $lobby): array {
    if ($lobby['status'] !== 'lobby') return [$lobby, false, false];
    $now = now_ms();
    $changed = false;

    foreach ($lobby['slots'] as $s) {
        if (($s['tabId'] ?? null) === $lobby['hostTabId']) {
            if (isset($s['lastSeen']) && ($now - $s['lastSeen']) > BATTLE_DISCONNECT_GRACE_MS) {
                return [$lobby, false, true]; // host zniknął - całe lobby przepada
            }
            break;
        }
    }
    foreach ($lobby['slots'] as $i => $s) {
        $type = $s['type'] ?? 'empty';
        if ($type === 'empty' || $type === 'bot' || ($s['tabId'] ?? null) === $lobby['hostTabId']) continue;
        if (isset($s['lastSeen']) && ($now - $s['lastSeen']) > BATTLE_DISCONNECT_GRACE_MS) {
            $lobby['slots'][$i] = ['type' => 'empty'];
            $changed = true;
        }
    }
    return [$lobby, $changed, false];
}

function battle_touch_slot(array $lobby, string $tabId): array {
    foreach ($lobby['slots'] as $i => $s) {
        if (($s['tabId'] ?? null) === $tabId) {
            $lobby['slots'][$i]['lastSeen'] = now_ms();
            break;
        }
    }
    return $lobby;
}

function battle_find_slot_idx(array $lobby, string $tabId): int {
    foreach ($lobby['slots'] as $i => $s) {
        if (($s['tabId'] ?? null) === $tabId) return $i;
    }
    return -1;
}

function battle_cleanup_old_finished(PDO $pdo): void {
    $cutoff = now_ms() - BATTLE_FINISHED_TTL_MS;
    $stmt = $pdo->prepare("DELETE FROM battle_lobbies WHERE status = 'finished' AND updated_at < ?");
    $stmt->execute([$cutoff]);
}

/* ---- "Codzienne najlepsze bitwy" ---- */
function battle_best_non_bot_result(array $lobby): ?array {
    $outcome = $lobby['outcome'] ?? null;
    if (!$outcome || !isset($lobby['results']) || !is_array($lobby['results']) || empty($lobby['cost'])) return null;

    if (!empty($outcome['shared'])) {
        $candidateIdx = array_keys($lobby['slots']);
    } elseif (!empty($outcome['winnerIndices']) && is_array($outcome['winnerIndices'])) {
        $candidateIdx = $outcome['winnerIndices'];
    } elseif (isset($outcome['winnerIdx']) && is_numeric($outcome['winnerIdx'])) {
        $candidateIdx = [$outcome['winnerIdx']];
    } else {
        return null;
    }

    $winnerSlot = null;
    foreach ($candidateIdx as $i) {
        $s = $lobby['slots'][$i] ?? null;
        if ($s && in_array($s['type'] ?? '', ['host', 'player'], true)) { $winnerSlot = $s; break; }
    }
    if (!$winnerSlot) return null; // wygrał wyłącznie bot - nie liczy się

    $combinedPot = array_reduce($lobby['results'], fn($sum, $r) => $sum + (is_numeric($r['total'] ?? null) ? $r['total'] : 0), 0);
    if (!empty($outcome['shared'])) {
        $winAmount = $combinedPot / $lobby['totalPlayers'];
    } elseif (!empty($lobby['teams'])) {
        $winAmount = $combinedPot / 2;
    } else {
        $winAmount = $combinedPot;
    }

    return [
        'multiplier' => $winAmount / $lobby['cost'],
        'winAmount' => $winAmount,
        'playerName' => $winnerSlot['name'],
        'playerAvatar' => $winnerSlot['avatar'] ?? null,
    ];
}

function battle_warsaw_date_string(int $epochMs): string {
    $dt = new DateTime('@' . intdiv($epochMs, 1000));
    $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
    return $dt->format('Y-m-d');
}

const DAILY_TOP_SHOWN = 20;
const DAILY_TOP_STORE_MAX = 100;

function battle_record_daily_top(array $lobby): void {
    $best = battle_best_non_bot_result($lobby);
    if (!$best || !($best['winAmount'] > 0)) return;
    $finishedAt = $lobby['finishedAt'] ?? now_ms();
    $dateStr = battle_warsaw_date_string($finishedAt);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT entries FROM daily_top WHERE date_str = ? FOR UPDATE');
        $stmt->execute([$dateStr]);
        $row = $stmt->fetch();
        $list = $row ? json_decode($row['entries'], true) : [];

        $list[] = array_merge([
            'lobbyId' => $lobby['id'],
            'cost' => $lobby['cost'],
            'rounds' => $lobby['rounds'],
            'totalPlayers' => $lobby['totalPlayers'],
            'teams' => !empty($lobby['teams']),
            'finishedAt' => $finishedAt,
        ], $best);
        usort($list, fn($a, $b) => $b['multiplier'] <=> $a['multiplier']);
        if (count($list) > DAILY_TOP_STORE_MAX) $list = array_slice($list, 0, DAILY_TOP_STORE_MAX);

        $upd = $pdo->prepare('INSERT INTO daily_top (date_str, entries) VALUES (?, ?) ON DUPLICATE KEY UPDATE entries = VALUES(entries)');
        $upd->execute([$dateStr, json_encode($list)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log("Nie udało się zapisać bitwy {$lobby['id']} do codziennej topki: " . $e->getMessage());
    }
}
