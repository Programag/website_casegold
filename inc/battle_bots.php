<?php
/* Bitwy samych botów - lista bitew ma ZAWSZE ok. 5 aktywnych (poczekalnia lub
   w trakcie): ile jest publicznych bitew z choć jednym prawdziwym graczem,
   tyle mniej bitew botów (2 bitwy graczy -> 3 bitwy botów). Bitwy botów są
   losowe (tryb, liczba graczy, skrzynki) i mają koszt wejścia do 10 000 zł.
   Brak stałego procesu w PHP, więc utrzymanie odbywa się leniwie przy każdym
   odpytaniu listy (api/battle-lobbies.php), pod nieblokującą blokadą MySQL,
   żeby równoległe odpytania nie tworzyły duplikatów. */
require_once __DIR__ . '/battle_economy.php';
require_once __DIR__ . '/bots.php';

const BATTLE_TARGET_ACTIVE = 5;
const BOT_BATTLE_MAX_COST = 10000;
const BOT_BATTLE_ROUND_MS = 7000;
const BOT_BATTLE_BASE_MS = 8000;
// Bitwa botów najpierw czeka w poczekalni z jednym botem i pustymi miejscami -
// przez minimum 15 s (plus losowy dodatek, żeby kolejne bitwy nie startowały
// jednocześnie) prawdziwy gracz może do niej dołączyć. Dopiero potem boty
// zajmują wolne miejsca i bitwa startuje.
const BOT_BATTLE_JOIN_WINDOW_MS = 15000;
const BOT_BATTLE_JOIN_JITTER_MS = 5000;

function battle_has_human(array $lobby): bool {
    foreach ($lobby['slots'] ?? [] as $s) {
        if (in_array($s['type'] ?? '', ['host', 'player'], true)) return true;
    }
    return false;
}

function battle_bot_duration_ms(array $lobby): int {
    return count($lobby['rounds'] ?? []) * BOT_BATTLE_ROUND_MS + BOT_BATTLE_BASE_MS;
}

/* Losowa konfiguracja bitwy botów: tryb, liczba graczy, skrzynki. Budżet
   wejścia jest losowany logarytmicznie (1,6 - 10 000 zł), żeby były i tanie,
   i drogie bitwy; łączny koszt wejścia nigdy nie przekracza 10 000 zł. */
function battle_bot_random_config(): array {
    $r = fn() => random_int(0, 999999) / 1000000;
    $players = [2, 2, 3, 4, 4][random_int(0, 4)];
    $teams = false;
    $shared = false;
    $mode = ['standard', 'underdog', 'decider', 'under-decider', 'burst', 'under-burst'][random_int(0, 5)];
    if ($players === 4 && $r() < 0.4) $teams = true;
    if (!$teams && $r() < 0.12) { $shared = true; $mode = 'standard'; }

    $budget = exp(log(1.6) + $r() * (log(BOT_BATTLE_MAX_COST) - log(1.6)));
    $keys = array_keys(battle_cases());
    $rounds = [];
    $total = 0.0;
    for ($i = 0; $i < 40 && count($rounds) < 12; $i++) {
        $key = $keys[random_int(0, count($keys) - 1)];
        if ($r() < 0.15 && empty(battle_cases()[$key]['noJester'])) $key .= ':jester';
        $def = battle_case_def($key);
        if (!$def) continue;
        $price = $def['price'];
        if ($total + $price > min($budget, BOT_BATTLE_MAX_COST)) {
            if ($rounds) { if ($r() < 0.5) break; else continue; }
            continue;
        }
        $rounds[] = $key;
        $total += $price;
    }
    if (!$rounds) { $rounds = ['milspec']; } // najtańsza skrzynka - zawsze mieści się w limicie
    return ['rounds' => $rounds, 'mode' => $mode, 'teams' => $teams, 'shared' => $shared, 'totalPlayers' => $players];
}

/* Zajmuje wolne miejsca botami (unikalne imiona w obrębie bitwy), losuje
   wyniki i startuje bitwę. Wołać na zablokowanym lobby (albo świeżo
   utworzonym) - zapis lobby należy do wołającego. */
function battle_bot_start(array &$lobby): void {
    $used = [];
    foreach ($lobby['slots'] as $s) if (($s['type'] ?? '') === 'bot') $used[] = $s['name'];
    $free = array_values(array_filter(BOT_ROSTER, fn($b) => !in_array($b['name'], $used, true)));
    shuffle($free);
    foreach ($lobby['slots'] as $i => $s) {
        if (($s['type'] ?? '') !== 'empty') continue;
        $b = array_shift($free);
        if (!$b) $b = ['id' => null, 'name' => 'Bot ' . ($i + 1)];
        $lobby['slots'][$i] = ['type' => 'bot', 'name' => $b['name'], 'tabId' => null] + ($b['id'] ? ['botId' => $b['id'], 'slug' => bot_slug($b['id'])] : []);
    }
    $lobby['results'] = battle_generate_results($lobby);
    $lobby['status'] = 'running';
    $lobby['startedAt'] = now_ms();
}

/* Gdy minął czas na dołączenie, bitwa botów (także z dołączonym już graczem)
   dostaje resztę botów i startuje. Zwraca true, jeśli lobby się zmieniło. */
function battle_bot_fill_if_due(array &$lobby): bool {
    if (empty($lobby['botMatch']) || $lobby['status'] !== 'lobby' || empty($lobby['botFillAt'])) return false;
    if (now_ms() < $lobby['botFillAt']) return false;
    battle_bot_start($lobby);
    return true;
}

function battle_create_bot_battle(PDO $pdo): void {
    $cfg = battle_bot_random_config();
    $cost = battle_entry_cost($cfg['rounds']);
    if ($cost === null || $cost > BOT_BATTLE_MAX_COST) return;

    $first = BOT_ROSTER[random_int(0, count(BOT_ROSTER) - 1)];
    $slots = [['type' => 'bot', 'name' => $first['name'], 'tabId' => null, 'botId' => $first['id'], 'slug' => bot_slug($first['id'])]];
    for ($i = 1; $i < $cfg['totalPlayers']; $i++) $slots[] = ['type' => 'empty'];
    $now = now_ms();
    $lobby = [
        'id' => battle_new_id(),
        'hostTabId' => 'bot-host',
        'rounds' => $cfg['rounds'],
        'mode' => $cfg['mode'],
        'teams' => $cfg['teams'],
        'shared' => $cfg['shared'],
        'private' => false,
        'cost' => $cost,
        'totalPlayers' => $cfg['totalPlayers'],
        'slots' => $slots,
        'status' => 'lobby',
        'results' => null,
        'stakedTotal' => 0,
        'serverEconomy' => true,
        'botMatch' => true,
        'botFillAt' => $now + BOT_BATTLE_JOIN_WINDOW_MS + random_int(0, BOT_BATTLE_JOIN_JITTER_MS),
        'createdAt' => $now,
    ];
    $pdo->beginTransaction();
    try {
        battle_save($pdo, $lobby);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('battle_create_bot_battle błąd: ' . $e->getMessage());
    }
}

function battle_bots_maintain(PDO $pdo): void {
    try {
        $got = (int) $pdo->query("SELECT GET_LOCK('cg_battle_bots', 0)")->fetchColumn();
        if ($got !== 1) return;
        try {
            bots_ensure_profiles($pdo);
            // Zakończone bitwy botów (np. widz doczekał końca animacji) nie są nikomu potrzebne.
            $pdo->prepare("DELETE FROM battle_lobbies WHERE status = 'finished' AND updated_at < ? AND JSON_EXTRACT(data, '$.botMatch') = true")->execute([now_ms() - 60000]);
            $rows = $pdo->query("SELECT id, status, is_private, data FROM battle_lobbies WHERE status IN ('lobby','running')")->fetchAll();
            $now = now_ms();
            $humanActive = 0;
            $botActive = 0;
            $botRemaining = []; // id => ile ms do końca animacji
            foreach ($rows as $row) {
                $l = json_decode($row['data'], true);
                if (!is_array($l)) continue;
                $id = (string) $row['id'];
                $pureBot = !empty($l['botMatch']) && !battle_has_human($l);
                if (!empty($l['botMatch']) && $l['status'] === 'lobby' && !empty($l['botFillAt']) && $now >= $l['botFillAt']) {
                    // Okno na dołączenie minęło - boty zajmują wolne miejsca i bitwa startuje
                    // (także gdy dołączył już prawdziwy gracz).
                    $pdo->beginTransaction();
                    try {
                        $locked = battle_load_for_update($pdo, $id);
                        if ($locked && battle_bot_fill_if_due($locked)) battle_save($pdo, $locked);
                        $pdo->commit();
                        if ($locked) $l = $locked;
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        error_log('battle_bots_maintain: start bitwy: ' . $e->getMessage());
                    }
                    $pureBot = !empty($l['botMatch']) && !battle_has_human($l);
                }
                if ($pureBot) {
                    if ($l['status'] === 'running' && !empty($l['startedAt']) && $now >= $l['startedAt'] + battle_bot_duration_ms($l)) {
                        // Skończona: rozlicz (statystyki botów) i usuń z listy.
                        battle_finish_lobby($pdo, $id);
                        $pdo->prepare('DELETE FROM battle_lobbies WHERE id = ?')->execute([$id]);
                    } else {
                        $botActive++;
                        $startsAt = $l['status'] === 'lobby' ? ($l['botFillAt'] ?? $now) : ($l['startedAt'] ?? $now);
                        $botRemaining[$id] = $startsAt + battle_bot_duration_ms($l) - $now;
                    }
                } elseif ((int) $row['is_private'] === 0 && battle_has_human($l)) {
                    $humanActive++;
                }
            }
            $wanted = max(0, BATTLE_TARGET_ACTIVE - $humanActive);
            // Za dużo bitew botów (gracz założył własną): kończymy te, które i
            // tak skończyłyby się najwcześniej - lista ma trzymać równo 5.
            if ($botActive > $wanted) {
                asort($botRemaining);
                foreach (array_slice(array_keys($botRemaining), 0, $botActive - $wanted) as $id) {
                    battle_finish_lobby($pdo, (string) $id);
                    $pdo->prepare('DELETE FROM battle_lobbies WHERE id = ?')->execute([$id]);
                    $botActive--;
                }
            }
            for ($i = $botActive; $i < $wanted; $i++) battle_create_bot_battle($pdo);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('cg_battle_bots')");
        }
    } catch (Throwable $e) {
        error_log('battle_bots_maintain błąd: ' . $e->getMessage());
    }
}
