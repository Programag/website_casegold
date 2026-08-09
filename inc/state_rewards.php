<?php
/* Serwerowe odpowiedniki formuł nagród z steam-auth.js/zadania.html - Faza 1
   migracji na serwer-autorytatywny (patrz plan). Do tej pory te formuły
   żyły WYŁĄCZNIE w JS: klient sam liczył uprawnienie/nagrodę i tylko
   ZAPISYWAŁ wynik na serwerze, więc serwer nigdy niezależnie nie
   weryfikował "czy ta nagroda faktycznie się należy". Odtąd serwer liczy
   samodzielnie na podstawie WŁASNEGO, zablokowanego (FOR UPDATE) stanu -
   patrz inc/state_ops.php. */
require_once __DIR__ . '/state_ops.php';

/* ---- Dzienny bonus - stałe i formuła identyczne z steam-auth.js ---- */
const DAILY_BONUS_COOLDOWN_MS = 24 * 60 * 60 * 1000;
const DAILY_STREAK_RESET_MS = 48 * 60 * 60 * 1000;
const DAILY_BONUS_DAY_AMOUNT = 600;
const DAILY_BONUS_MAX_DAYS = 5;

function daily_bonus_reward_for_day(int $day): float {
    return min(max($day, 1), DAILY_BONUS_MAX_DAYS) * DAILY_BONUS_DAY_AMOUNT;
}

/* Zwraca ['canClaim'=>bool, 'reward'=>float, 'newStreak'=>int, 'pendingDay'=>int]
   licząc WYŁĄCZNIE z pól już zapisanych w $state (dailyBonusAt/dailyStreak) -
   żadna wartość od klienta nie jest tu potrzebna ani ufana. */
function daily_bonus_status(array $state): array {
    $lastClaim = is_numeric($state['dailyBonusAt'] ?? null) ? (float) $state['dailyBonusAt'] : 0;
    $prevStreak = is_numeric($state['dailyStreak'] ?? null) ? (int) $state['dailyStreak'] : 0;
    $now = now_ms();
    $msSinceClaim = $lastClaim > 0 ? $now - $lastClaim : PHP_FLOAT_MAX;
    $effectiveStreak = $msSinceClaim > DAILY_STREAK_RESET_MS ? 0 : $prevStreak;
    $pendingDay = min($effectiveStreak + 1, DAILY_BONUS_MAX_DAYS);
    return [
        'effectiveStreak' => $effectiveStreak,
        'pendingDay' => $pendingDay,
        'reward' => daily_bonus_reward_for_day($pendingDay),
        'canClaim' => $msSinceClaim >= DAILY_BONUS_COOLDOWN_MS,
    ];
}

/* ---- Zadania (QUESTS) - portowane 1:1 z zadania.html. Tylko pola potrzebne
   do weryfikacji serwerowej (opisy/formatowanie zostają czysto kliencka,
   kosmetyczne sprawy UI). "live" => true (Kolekcjoner) liczy postęp z
   BIEŻĄCEJ wartości ekwipunku, nie z trwałego licznika. ---- */
const QUESTS = [
    'cases_opened' => ['statKey' => 'casesOpened', 'tiers' => [50, 250, 1000, 5000], 'rewards' => [1000, 8000, 40000, 120000]],
    'cases_spent' => ['statKey' => 'spentCases', 'tiers' => [10000, 50000, 250000, 1000000], 'rewards' => [1500, 12000, 60000, 180000]],
    'inv_value' => ['live' => true, 'tiers' => [10000, 75000, 600000, 5000000], 'rewards' => [1300, 10000, 50000, 150000]],
    'battles_played' => ['statKey' => 'battlesPlayed', 'tiers' => [200, 600, 2000, 6000], 'rewards' => [1100, 9000, 45000, 100000]],
    'battles_won' => ['statKey' => 'battlesWon', 'tiers' => [40, 150, 600, 3000], 'rewards' => [1800, 15000, 70000, 200000]],
    'upgrader_spent' => ['statKey' => 'spentUpgrader', 'tiers' => [20000, 75000, 300000, 1000000], 'rewards' => [1600, 13000, 65000, 190000]],
    'upgrade_attempts' => ['statKey' => 'upgradeClicks', 'tiers' => [50, 200, 750, 2000], 'rewards' => [1200, 9500, 42000, 110000]],
];

function quest_current_value(string $taskId, array $state): float {
    $def = QUESTS[$taskId] ?? null;
    if (!$def) return 0;
    if (!empty($def['live'])) {
        $inventory = is_array($state['inventory'] ?? null) ? $state['inventory'] : [];
        return array_reduce($inventory, fn($sum, $it) => $sum + (is_numeric($it['price'] ?? null) ? $it['price'] : 0), 0);
    }
    $key = $def['statKey'];
    return is_numeric($state[$key] ?? null) ? (float) $state[$key] : 0;
}

/* ---- Nagrody za poziom - formuła identyczna z steam-auth.js ---- */
function level_reward_target_price(int $level): float {
    return 100 * pow(1.05, $level - 1);
}

/* Deterministyczny wybór przedmiotu (najbliższa cena) z GENERAL_SKIN_DB -
   liniowe przeszukanie ze ścisłym < na różnicy, więc przy remisie wygrywa
   PIERWSZY pasujący w kolejności tablicy (tak samo jak w steam-auth.js). */
function level_reward_item(int $level): ?array {
    if ($level < 1) return null;
    static $db = null;
    if ($db === null) $db = require __DIR__ . '/data/general_skin_db.php';
    $target = level_reward_target_price($level);
    $best = null;
    $bestDiff = INF;
    foreach ($db as $it) {
        $diff = abs($it['price'] - $target);
        if ($diff < $bestDiff) { $bestDiff = $diff; $best = $it; }
    }
    return $best;
}

/* ---- Kody prezentowe (Discord) - api/redeem-gift-code.php ----
   Kody typu "case" dopisują +1 (albo więcej - patrz 'count' w
   inc/data/gift_codes.php) do state['freeCaseOpens'][caseId] - to jest
   dokładnie ta sama pula darmowych otwarć, którą api/apply-case-open.php
   (parametr useFreeCredit) zużywa przy faktycznym otwarciu na stronie danej
   skrzynki (case_*.html), więc wygrana skrzynka jest PRAWDZIWĄ skrzynką z
   jej właściwą tabelą przedmiotów, nie osobną, uproszczoną pulą. */
