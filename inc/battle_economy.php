<?php
/* Ekonomia Case Battle po stronie serwera: koszt wejścia, losowanie wyników,
   wynik bitwy, wypłaty zwycięzców i zwroty wpisowego. Dotąd KAŻDY z tych
   kroków robił klient (lokalne saldo + push całego stanu), przez co saldo
   rozjeżdżało się między urządzeniami i dało się je dowolnie ustawić.
   Teraz saldo i ekwipunek zmienia wyłącznie serwer, w transakcjach na
   zablokowanych wierszach (lobby -> gracze, zawsze w tej kolejności).

   Funkcje wyniku (hash/seededIndex/determineOutcome/computeBurstStars) są
   1:1 portem z battle.html - zgodność jest sprawdzana testem różnicowym
   JS vs PHP. */
require_once __DIR__ . '/state_ops.php';
require_once __DIR__ . '/battle.php';

const BATTLE_XP_PER_ZL = 1 / 10; // XP_PER_ZL ze steam-auth.js
// Bitwa "running" bez zgłoszenia końca (wszyscy zamknęli karty) jest sama
// rozliczana po tym czasie: stała część + czas na rundę.
const BATTLE_SETTLE_BASE_MS = 180 * 1000;
const BATTLE_SETTLE_PER_ROUND_MS = 20 * 1000;

/* ---------------- skrzynki, koszt, losowanie ---------------- */
function battle_cases(): array {
    static $cases = null;
    if ($cases === null) $cases = require __DIR__ . '/data/battle_cases.php';
    return $cases;
}

function battle_jester_price(array $items, float $normalPrice): float {
    $n = count($items);
    if ($n === 0) return $normalPrice;
    $ev = 0.0; $sum = 0.0;
    foreach ($items as $it) {
        $ev += $it['price'] * (($it['end'] - $it['start'] + 1) / 100000);
        $sum += $it['price'];
    }
    $avg = $sum / $n;
    $m = $ev > 0 ? ($normalPrice / $ev - 1) : 0;
    return $avg * (1 + $m);
}

/* Klucz rundy: "milspec" albo "milspec:jester" (nie dla skrzynek noJester). */
function battle_case_def(string $key): ?array {
    $jester = str_ends_with($key, ':jester');
    $base = $jester ? substr($key, 0, -7) : $key;
    $c = battle_cases()[$base] ?? null;
    if (!$c) return null;
    if ($jester && !empty($c['noJester'])) return null;
    $price = $jester ? battle_jester_price($c['items'], (float) $c['price']) : (float) $c['price'];
    return ['key' => $key, 'jester' => $jester, 'items' => $c['items'], 'price' => $price];
}

/* Koszt wejścia = suma cen skrzynek ze wszystkich rund; null gdy któraś runda
   jest nieprawidłowa. */
function battle_entry_cost(array $rounds): ?float {
    $total = 0.0;
    foreach ($rounds as $key) {
        if (!is_string($key)) return null;
        $def = battle_case_def($key);
        if (!$def) return null;
        $total += $def['price'];
    }
    return $total;
}

function battle_rand_float(): float {
    return random_int(0, 999999999) / 1000000000;
}

function battle_roll_from_case(array $def, bool $boosted): array {
    $pool = $def['items'];
    if (!$boosted) {
        if ($def['jester']) return $pool[random_int(0, count($pool) - 1)];
        $roll = random_int(1, 100000);
        foreach ($pool as $it) {
            if ($roll >= $it['start'] && $roll <= $it['end']) return $it;
        }
        return $pool[0];
    }
    $topIdx = 0;
    foreach ($pool as $i => $it) {
        if ($it['price'] > $pool[$topIdx]['price']) $topIdx = $i;
    }
    $top = $pool[$topIdx];
    if (battle_rand_float() < 0.30) return $top;
    $rest = [];
    foreach ($pool as $i => $it) if ($i !== $topIdx) $rest[] = $it;
    if (!$rest) return $top;
    if ($def['jester']) return $rest[random_int(0, count($rest) - 1)];
    $weights = array_map(fn($it) => max(1, $it['end'] - $it['start'] + 1), $rest);
    $total = array_sum($weights);
    $r = battle_rand_float() * $total;
    foreach ($rest as $i => $it) {
        $r -= $weights[$i];
        if ($r <= 0) return $it;
    }
    return $rest[count($rest) - 1];
}

/* Wyniki bitwy losuje SERWER (klient host-a już ich nie dostarcza). */
function battle_generate_results(array $lobby): array {
    $results = array_map(fn($s) => ['rounds' => [], 'total' => 0], $lobby['slots']);
    foreach ($lobby['rounds'] as $caseKey) {
        $def = battle_case_def($caseKey);
        foreach ($lobby['slots'] as $i => $slot) {
            $it = battle_roll_from_case($def, !empty($slot['boosted']));
            $item = ['weapon' => $it['weapon'], 'skin' => $it['skin'], 'wear' => $it['wear'], 'price' => (float) $it['price'], '_caseKey' => $caseKey];
            $results[$i]['rounds'][] = $item;
            $results[$i]['total'] += $item['price'];
        }
    }
    return $results;
}

/* ---------------- wynik bitwy (port determineOutcome) ---------------- */
function battle_hash_str(string $s): int {
    $h = 2166136261;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $h ^= ord($s[$i]);
        $h = ($h * 16777619) & 0xFFFFFFFF;
    }
    return $h;
}
function battle_seeded_index(string $seed, int $n): int { return battle_hash_str($seed) % $n; }
function battle_team_of_idx(int $idx, int $totalPlayers): int { return $idx < $totalPlayers / 2 ? 0 : 1; }

function battle_compute_burst_stars(array $lobby): array {
    $n = count($lobby['slots']);
    $stars = array_fill(0, $n, 0);
    $invert = $lobby['mode'] === 'under-burst';
    $teams = ($lobby['teams'] ?? false) === true;
    foreach ($lobby['rounds'] as $roundIdx => $caseKey) {
        if ($teams) {
            $teamSums = [0, 0];
            foreach ($lobby['results'] as $i => $r) $teamSums[battle_team_of_idx($i, $lobby['totalPlayers'])] += $r['rounds'][$roundIdx]['price'];
            $extreme = $invert ? min($teamSums) : max($teamSums);
            $cand = array_values(array_filter([0, 1], fn($t) => $teamSums[$t] == $extreme));
            $winnerTeam = count($cand) === 1 ? $cand[0] : battle_seeded_index($lobby['id'] . ':burstteamround:' . $roundIdx, 2);
            foreach ($lobby['slots'] as $i => $s) {
                if (battle_team_of_idx($i, $lobby['totalPlayers']) === $winnerTeam) $stars[$i]++;
            }
            continue;
        }
        $prices = array_map(fn($r) => $r['rounds'][$roundIdx]['price'], $lobby['results']);
        $extreme = $invert ? min($prices) : max($prices);
        $cand = [];
        foreach ($prices as $i => $p) if ($p == $extreme) $cand[] = $i;
        $winnerIdx = count($cand) === 1 ? $cand[0] : $cand[battle_seeded_index($lobby['id'] . ':burstround:' . $roundIdx, count($cand))];
        $stars[$winnerIdx]++;
    }
    return $stars;
}

function battle_determine_outcome(array $lobby): array {
    $slots = $lobby['slots'];
    $teams = ($lobby['teams'] ?? false) === true;
    $mode = $lobby['mode'];
    $tp = $lobby['totalPlayers'];
    if (!empty($lobby['shared'])) {
        $all = array_keys($slots);
        return ['tie' => false, 'shared' => true, 'winnerIndices' => $all, 'winnerIdx' => 0];
    }
    if ($mode === 'decider' || $mode === 'under-decider') {
        $last = count($lobby['rounds']) - 1;
        if ($teams) {
            $teamSums = [0, 0];
            foreach ($lobby['results'] as $i => $r) $teamSums[battle_team_of_idx($i, $tp)] += $r['rounds'][$last]['price'];
            $extreme = $mode === 'decider' ? max($teamSums) : min($teamSums);
            $cand = array_values(array_filter([0, 1], fn($t) => $teamSums[$t] == $extreme));
            $winnerTeam = count($cand) === 1 ? $cand[0] : battle_seeded_index($lobby['id'] . ':' . $mode, 2);
            $wi = array_values(array_filter(array_keys($slots), fn($i) => battle_team_of_idx($i, $tp) === $winnerTeam));
            return ['tie' => false, 'winnerTeam' => $winnerTeam, 'winnerIndices' => $wi, 'winnerIdx' => $wi[0] ?? 0];
        }
        $prices = array_map(fn($r) => $r['rounds'][$last]['price'], $lobby['results']);
        $extreme = $mode === 'decider' ? max($prices) : min($prices);
        $cand = [];
        foreach ($prices as $i => $p) if ($p == $extreme) $cand[] = $i;
        $w = count($cand) === 1 ? $cand[0] : $cand[battle_seeded_index($lobby['id'] . ':' . $mode, count($cand))];
        return ['tie' => false, 'winnerIdx' => $w, 'winnerIndices' => [$w]];
    }
    if ($mode === 'burst' || $mode === 'under-burst') {
        $stars = battle_compute_burst_stars($lobby);
        if ($teams) {
            $teamStars = [0, 0];
            foreach ($stars as $i => $s) $teamStars[battle_team_of_idx($i, $tp)] += $s;
            $isTie = $teamStars[0] == $teamStars[1];
            $winnerTeam = $isTie ? battle_seeded_index($lobby['id'] . ':' . $mode . ':final', 2) : ($teamStars[0] > $teamStars[1] ? 0 : 1);
            $wi = array_values(array_filter(array_keys($slots), fn($i) => battle_team_of_idx($i, $tp) === $winnerTeam));
            return ['tie' => false, 'winnerTeam' => $winnerTeam, 'winnerIndices' => $wi, 'winnerIdx' => $wi[0] ?? 0];
        }
        $maxStars = max($stars);
        $cand = [];
        foreach ($stars as $i => $s) if ($s === $maxStars) $cand[] = $i;
        $w = count($cand) === 1 ? $cand[0] : $cand[battle_seeded_index($lobby['id'] . ':' . $mode . ':final', count($cand))];
        return ['tie' => false, 'winnerIdx' => $w, 'winnerIndices' => [$w]];
    }
    if ($teams) {
        $teamTotals = [0, 0];
        foreach ($lobby['results'] as $i => $r) $teamTotals[battle_team_of_idx($i, $tp)] += $r['total'];
        $isTie = $teamTotals[0] == $teamTotals[1];
        $teamABetter = $mode === 'standard' ? $teamTotals[0] > $teamTotals[1] : $teamTotals[0] < $teamTotals[1];
        $winnerTeam = $isTie ? battle_seeded_index($lobby['id'] . ':' . $mode, 2) : ($teamABetter ? 0 : 1);
        $wi = array_values(array_filter(array_keys($slots), fn($i) => battle_team_of_idx($i, $tp) === $winnerTeam));
        return ['tie' => false, 'winnerTeam' => $winnerTeam, 'winnerIndices' => $wi, 'winnerIdx' => $wi[0] ?? 0];
    }
    $totals = array_map(fn($r) => $r['total'], $lobby['results']);
    $extreme = $mode === 'standard' ? max($totals) : min($totals);
    $cand = [];
    foreach ($totals as $i => $t) if ($t == $extreme) $cand[] = $i;
    $w = count($cand) === 1 ? $cand[0] : $cand[battle_seeded_index($lobby['id'] . ':' . $mode, count($cand))];
    return ['tie' => false, 'winnerIdx' => $w, 'winnerIndices' => [$w]];
}

/* ---------------- skład nagrody z ogólnej bazy (port pickItemsForValue) ---------------- */
function battle_pick_items_for_value(float $target): array {
    $remaining = (int) round($target * 100);
    if ($remaining <= 0) return [];
    static $itemsDesc = null;
    if ($itemsDesc === null) {
        $db = require __DIR__ . '/data/general_skin_db.php';
        $itemsDesc = array_map(fn($it) => $it + ['cents' => (int) round($it['price'] * 100)], $db);
        usort($itemsDesc, fn($a, $b) => $b['cents'] <=> $a['cents']); // stabilne od PHP 8.0, jak sort() w JS
    }
    $chosen = [];
    $dpLimit = 200000;
    while ($remaining > $dpLimit) {
        $fitting = array_values(array_filter($itemsDesc, fn($it) => $it['cents'] <= $remaining));
        if (!$fitting) break;
        $topFew = array_slice($fitting, 0, min(5, count($fitting)));
        $pick = $topFew[random_int(0, count($topFew) - 1)];
        $chosen[] = $pick;
        $remaining -= $pick['cents'];
    }
    if ($remaining > 0) {
        $cap = min($remaining, $dpLimit);
        $reachable = array_fill(0, $cap + 1, 0);
        $itemFor = array_fill(0, $cap + 1, -1);
        $reachable[0] = 1;
        $nItems = count($itemsDesc);
        $cents = array_column($itemsDesc, 'cents');
        for ($amt = 1; $amt <= $cap; $amt++) {
            for ($idx = 0; $idx < $nItems; $idx++) {
                $c = $cents[$idx];
                if ($c <= $amt && $reachable[$amt - $c]) { $reachable[$amt] = 1; $itemFor[$amt] = $idx; break; }
            }
        }
        $amt = $remaining;
        // $remaining <= $dpLimit gwarantuje $amt <= $cap (w przeciwnym razie krok 1 by je zmniejszył)
        if ($amt <= $cap) {
            if (!$reachable[$amt]) while ($amt > 0 && !$reachable[$amt]) $amt--;
            while ($amt > 0) {
                $it = $itemsDesc[$itemFor[$amt]];
                $chosen[] = $it;
                $amt -= $it['cents'];
            }
        }
    }
    for ($i = count($chosen) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chosen[$i], $chosen[$j]] = [$chosen[$j], $chosen[$i]];
    }
    return array_map(fn($it) => ['weapon' => $it['weapon'], 'skin' => $it['skin'], 'wear' => $it['wear'], 'price' => $it['price']], $chosen);
}

/* ---------------- pieniądze: pobranie wpisowego i zwroty ---------------- */

/* Pobiera wpisowe z konta gracza (w TRWAJĄCEJ transakcji wołającego).
   Zwraca zapisany stan albo null przy braku środków/użytkownika. */
function battle_charge_user(PDO $pdo, string $steamid, float $amount): ?array {
    $state = state_lock_user_for_update($pdo, $steamid);
    if ($state === null) return null;
    $balance = is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0.0;
    if ($balance + 1e-9 < $amount) return null;
    $state['balance'] = $balance - $amount;
    return state_save($pdo, $steamid, $state);
}

/* Zwrot wpisowego (w trwającej transakcji). */
function battle_refund_user(PDO $pdo, string $steamid, float $amount): void {
    if ($amount <= 0) return;
    $state = state_lock_user_for_update($pdo, $steamid);
    if ($state === null) return;
    $state['balance'] = (is_numeric($state['balance'] ?? null) ? (float) $state['balance'] : 0.0) + $amount;
    state_save($pdo, $steamid, $state);
}

/* Zwraca wpisowe wszystkim usadzonym ludziom (host + dołączeni), którzy
   zapłacili - użyć przy kasowaniu lobby w poczekalni. Kolejność rosnąca po
   steamid, żeby blokady wierszy zawsze szły tak samo (bez deadlocków). */
function battle_refund_all_seated(PDO $pdo, array $lobby): void {
    $refunds = [];
    foreach ($lobby['slots'] as $s) {
        if (in_array($s['type'] ?? '', ['host', 'player'], true) && !empty($s['steamid']) && ($s['paid'] ?? 0) > 0) {
            $refunds[$s['steamid']] = ($refunds[$s['steamid']] ?? 0) + (float) $s['paid'];
        }
    }
    ksort($refunds, SORT_STRING);
    foreach ($refunds as $steamid => $amount) battle_refund_user($pdo, (string) $steamid, $amount);
}

/* ---------------- rozliczenie bitwy ---------------- */

/* Rozlicza zakończoną bitwę: liczy wynik NA SERWERZE i wypłaca zwycięzców.
   Wołać WEWNĄTRZ transakcji, po zablokowaniu lobby (FOR UPDATE). Idempotentne
   (flaga settled). Zmienia $lobby (outcome, settlement, settled) - zapis
   lobby należy do wołającego. */
function battle_settle(PDO $pdo, array &$lobby): void {
    if (!empty($lobby['settled'])) return;
    // Tylko lobby z NOWĄ ekonomią (wpisowe pobrane przez serwer). Starsze,
    // rozliczane jeszcze po stronie klienta, zostały już wypłacone - drugi raz
    // by to było podwójne wypłacenie.
    if (empty($lobby['serverEconomy']) || !isset($lobby['results']) || !is_array($lobby['results'])) { $lobby['settled'] = true; return; }

    $outcome = battle_determine_outcome($lobby);
    $lobby['outcome'] = $outcome;

    $allItems = [];
    foreach ($lobby['results'] as $r) foreach ($r['rounds'] as $it) $allItems[] = $it;
    $wonValue = array_reduce($allItems, fn($s, $it) => $s + $it['price'], 0.0);
    $teams = ($lobby['teams'] ?? false) === true;
    $shared = !empty($outcome['shared']);
    $winnerSet = array_flip($outcome['winnerIndices']);

    $plans = []; // steamid => ['kind','items']
    foreach ($lobby['slots'] as $i => $slot) {
        if (!in_array($slot['type'] ?? '', ['host', 'player'], true) || empty($slot['steamid'])) continue;
        $steamid = (string) $slot['steamid'];
        if ($shared) {
            $plans[$steamid] = ['kind' => 'shared', 'items' => battle_pick_items_for_value($wonValue / $lobby['totalPlayers'])];
        } elseif (isset($winnerSet[$i])) {
            $items = $teams ? battle_pick_items_for_value($wonValue / 2) : array_map(fn($it) => ['weapon' => $it['weapon'], 'skin' => $it['skin'], 'wear' => $it['wear'], 'price' => $it['price']], $allItems);
            $plans[$steamid] = ['kind' => 'win', 'items' => $items];
        } else {
            $plans[$steamid] = ['kind' => 'loss', 'items' => []];
        }
    }

    ksort($plans, SORT_STRING);
    $payouts = [];
    foreach ($plans as $steamid => $plan) {
        $steamid = (string) $steamid;
        $state = state_lock_user_for_update($pdo, $steamid);
        if ($state === null) continue;
        $inventory = is_array($state['inventory'] ?? null) ? array_values($state['inventory']) : [];
        $value = 0.0;
        $given = [];
        $best = is_array($state['bestPull'] ?? null) ? $state['bestPull'] : null;
        foreach ($plan['items'] as $it) {
            $it['_id'] = state_next_item_id($state);
            $inventory[] = $it;
            $given[] = $it;
            $value += $it['price'];
            if (!$best || $it['price'] > ($best['price'] ?? 0)) {
                $best = ['weapon' => $it['weapon'], 'skin' => $it['skin'], 'wear' => $it['wear'], 'price' => $it['price'], 'at' => now_ms()];
            }
        }
        $xp = $value * BATTLE_XP_PER_ZL;
        $state['inventory'] = $inventory;
        if ($best) $state['bestPull'] = $best;
        if ($xp > 0) {
            $state['xp'] = (is_numeric($state['xp'] ?? null) ? $state['xp'] : 0) + $xp;
            state_bump_level_watermark($state);
        }
        $state['casesOpened'] = (is_numeric($state['casesOpened'] ?? null) ? $state['casesOpened'] : 0) + count($given);
        state_save($pdo, $steamid, $state);
        $payouts[$steamid] = ['kind' => $plan['kind'], 'value' => $value, 'xp' => $xp, 'items' => $given];
    }

    $lobby['settlement'] = ['at' => now_ms(), 'payouts' => $payouts];
    $lobby['settled'] = true;
}

/* Rozlicza (zakończone przez serwer) bitwy "running", które wszyscy porzucili
   i nikt nie zgłosił końca - żeby gracze nie tracili wpisowego, gdy zamkną
   kartę w trakcie animacji. Tania kontrola, wołana z api/me.php. */
function battle_settle_overdue(PDO $pdo): void {
    try {
        $rows = $pdo->query("SELECT id, data FROM battle_lobbies WHERE status = 'running'")->fetchAll();
        $now = now_ms();
        foreach ($rows as $row) {
            $l = json_decode($row['data'], true);
            if (!is_array($l) || empty($l['startedAt'])) continue;
            $due = $l['startedAt'] + BATTLE_SETTLE_BASE_MS + count($l['rounds'] ?? []) * BATTLE_SETTLE_PER_ROUND_MS;
            if ($now < $due) continue;
            battle_finish_lobby($pdo, (string) $row['id']);
        }
    } catch (Throwable $e) {
        error_log('battle_settle_overdue błąd: ' . $e->getMessage());
    }
}

/* Kończy i rozlicza lobby w jednej transakcji (idempotentnie) - wspólne dla
   api/battle-finish.php i battle_settle_overdue(). Zwraca lobby po zmianie
   albo null, gdy nie istnieje. */
function battle_finish_lobby(PDO $pdo, string $lobbyId): ?array {
    $pdo->beginTransaction();
    try {
        $lobby = battle_load_for_update($pdo, $lobbyId);
        if (!$lobby) { $pdo->rollBack(); return null; }
        $firstFinish = $lobby['status'] !== 'finished';
        if ($lobby['status'] === 'lobby') { $pdo->rollBack(); return $lobby; } // nie wystartowała - nie ma czego rozliczać
        if (empty($lobby['settled'])) {
            $lobby['status'] = 'finished';
            $lobby['finishedAt'] = now_ms();
            battle_settle($pdo, $lobby);
            battle_save($pdo, $lobby);
        }
        $pdo->commit();
        if ($firstFinish) {
            battle_record_daily_top($lobby);
            require_once __DIR__ . '/live_drops.php';
            live_drops_record_battle($pdo, $lobby);
        }
        return $lobby;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('battle_finish_lobby błąd: ' . $e->getMessage());
        return null;
    }
}
