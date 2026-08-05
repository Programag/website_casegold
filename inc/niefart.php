<?php
require_once __DIR__ . '/db.php';

/* "Niefart dnia" - TOP wg liczby przegranych bitew Case Battle danego dnia
   (czasu polskiego), z automatyczną wypłatą 5000 zł dla górnej połowy o
   północy. W Node robił to setInterval(..., 60*1000) w stałym procesie -
   PHP nie ma stałego procesu, więc sprawdzamy przy okazji api/me.php
   (wołane synchronicznie na KAŻDYM wejściu na stronę - patrz steam-auth.js),
   idempotentnie dzięki zapisanej dacie ostatniej wypłaty. */

const NIEFART_REWARD = 5000;
const NIEFART_TOP_SHOWN = 20;
const NIEFART_TOP_REWARDED = 10;

/* "Najaktywniejszy gracz" - TOP wg LICZBY rozegranych bitew Case Battle
   danego dnia (niezależnie od wyniku), z automatyczną wypłatą 2000 zł dla
   TYLKO pierwszego miejsca o północy - ten sam mechanizm co niefart dnia
   (sprawdzane przy okazji api/me.php), tylko inna metryka i inny próg
   wypłaty. */
const ACTIVE_PLAYER_REWARD = 2000;
const ACTIVE_PLAYER_TOP_SHOWN = 20;
const ACTIVE_PLAYER_TOP_REWARDED = 1;

function warsaw_date_string(int $epochMs): string {
    $dt = new DateTime('@' . intdiv($epochMs, 1000));
    $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
    return $dt->format('Y-m-d');
}

function count_losses_on_date(?array $state, string $dateStr): int {
    $history = $state['battleHistory'] ?? [];
    if (!is_array($history)) return 0;
    $n = 0;
    foreach ($history as $e) {
        if (!is_array($e)) continue;
        if (($e['outcome'] ?? null) !== 'loss') continue;
        if (!isset($e['at']) || !is_numeric($e['at'])) continue;
        if (warsaw_date_string((int) $e['at']) === $dateStr) $n++;
    }
    return $n;
}

function count_battles_on_date(?array $state, string $dateStr): int {
    $history = $state['battleHistory'] ?? [];
    if (!is_array($history)) return 0;
    $n = 0;
    foreach ($history as $e) {
        if (!is_array($e)) continue;
        if (!isset($e['at']) || !is_numeric($e['at'])) continue;
        if (warsaw_date_string((int) $e['at']) === $dateStr) $n++;
    }
    return $n;
}

/* Zwraca wszystkich graczy ze slugiem: [steamid, slug, display_name,
   avatar, state (zdekodowany)] - do rankingu niefartu i do wypłaty. */
function all_users_with_slug(): array {
    $rows = db()->query('SELECT steamid, slug, display_name, avatar, state FROM users WHERE slug IS NOT NULL')->fetchAll();
    foreach ($rows as &$r) {
        $r['state'] = $r['state'] !== null ? json_decode($r['state'], true) : null;
    }
    return $rows;
}

function niefart_ranking_for_date(string $dateStr, array $users = null): array {
    $users = $users ?? all_users_with_slug();
    $rows = [];
    foreach ($users as $u) {
        $value = count_losses_on_date($u['state'], $dateStr);
        if ($value <= 0) continue;
        $rows[] = [
            'steamid' => $u['steamid'],
            'slug' => $u['slug'],
            'displayName' => $u['display_name'],
            'avatar' => $u['avatar'],
            'value' => $value,
        ];
    }
    usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
    return array_slice($rows, 0, NIEFART_TOP_SHOWN);
}

function active_player_ranking_for_date(string $dateStr, array $users = null): array {
    $users = $users ?? all_users_with_slug();
    $rows = [];
    foreach ($users as $u) {
        $value = count_battles_on_date($u['state'], $dateStr);
        if ($value <= 0) continue;
        $rows[] = [
            'steamid' => $u['steamid'],
            'slug' => $u['slug'],
            'displayName' => $u['display_name'],
            'avatar' => $u['avatar'],
            'value' => $value,
        ];
    }
    usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
    return array_slice($rows, 0, ACTIVE_PLAYER_TOP_SHOWN);
}

function meta_get(string $key) {
    $stmt = db()->prepare('SELECT meta_value FROM app_meta WHERE meta_key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}
function meta_set(string $key, $value): void {
    $stmt = db()->prepare('INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
    $stmt->execute([$key, json_encode($value)]);
}

function check_niefart_payout(): void {
    try {
        $lastPayoutDate = meta_get('niefart_last_payout_date');
        if (!$lastPayoutDate) {
            meta_set('niefart_last_payout_date', warsaw_date_string(now_ms()));
            return;
        }
        $yesterdayStr = warsaw_date_string(now_ms() - 24 * 60 * 60 * 1000);
        if ($lastPayoutDate === $yesterdayStr) return; // już wypłacone za wczoraj

        $users = all_users_with_slug();
        $winners = array_slice(niefart_ranking_for_date($yesterdayStr, $users), 0, NIEFART_TOP_REWARDED);
        if ($winners) {
            // PDO natywne (nieemulowane) prepare (patrz ATTR_EMULATE_PREPARES
            // w db.php) nie pozwala użyć tej samej nazwanej wartości (:now)
            // dwa razy w jednym zapytaniu - stąd :now1/:now2 zamiast :now.
            $upd = db()->prepare("UPDATE users SET state = JSON_SET(
                COALESCE(state, JSON_OBJECT()),
                '$.balance', COALESCE(JSON_EXTRACT(state, '$.balance'), 0) + :reward,
                '$.adminOverrideAt', :now1,
                '$.updatedAt', :now2
            ) WHERE steamid = :steamid");
            foreach ($winners as $w) {
                $now = now_ms();
                $upd->execute([':reward' => NIEFART_REWARD, ':now1' => $now, ':now2' => $now, ':steamid' => $w['steamid']]);
            }
        }

        meta_set('niefart_last_payout_date', $yesterdayStr);
        meta_set('niefart_last_winners', array_map(fn($w) => [
            'slug' => $w['slug'], 'displayName' => $w['displayName'], 'avatar' => $w['avatar'], 'losses' => $w['value'],
        ], $winners));
    } catch (Throwable $e) {
        error_log('Błąd wypłaty niefartu dnia: ' . $e->getMessage());
    }
}

function check_active_player_payout(): void {
    try {
        $lastPayoutDate = meta_get('active_player_last_payout_date');
        if (!$lastPayoutDate) {
            meta_set('active_player_last_payout_date', warsaw_date_string(now_ms()));
            return;
        }
        $yesterdayStr = warsaw_date_string(now_ms() - 24 * 60 * 60 * 1000);
        if ($lastPayoutDate === $yesterdayStr) return; // już wypłacone za wczoraj

        $users = all_users_with_slug();
        $winners = array_slice(active_player_ranking_for_date($yesterdayStr, $users), 0, ACTIVE_PLAYER_TOP_REWARDED);
        if ($winners) {
            $upd = db()->prepare("UPDATE users SET state = JSON_SET(
                COALESCE(state, JSON_OBJECT()),
                '$.balance', COALESCE(JSON_EXTRACT(state, '$.balance'), 0) + :reward,
                '$.adminOverrideAt', :now1,
                '$.updatedAt', :now2
            ) WHERE steamid = :steamid");
            foreach ($winners as $w) {
                $now = now_ms();
                $upd->execute([':reward' => ACTIVE_PLAYER_REWARD, ':now1' => $now, ':now2' => $now, ':steamid' => $w['steamid']]);
            }
        }

        meta_set('active_player_last_payout_date', $yesterdayStr);
        meta_set('active_player_last_winners', array_map(fn($w) => [
            'slug' => $w['slug'], 'displayName' => $w['displayName'], 'avatar' => $w['avatar'], 'battles' => $w['value'],
        ], $winners));
    } catch (Throwable $e) {
        error_log('Błąd wypłaty za najaktywniejszego gracza dnia: ' . $e->getMessage());
    }
}
