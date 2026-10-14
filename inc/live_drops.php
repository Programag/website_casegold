<?php
/* Publiczny strumień "najnowszych wygranych" pokazywany w przesuwanym pasku
   na górze każdej strony (steam-auth.js -> initLiveFeed, api/live-drops.php).
   Zapis dzieje się z api/apply-case-open.php PO udanym zatwierdzeniu
   otwarcia skrzynki - błąd tutaj nigdy nie może zepsuć samego otwarcia, więc
   wszystko jest w try/catch i tylko loguje. */
require_once __DIR__ . '/db.php';

const LIVE_DROPS_KEEP = 300;

/* CREATE TABLE IF NOT EXISTS zamiast ręcznej migracji na produkcji - tabela
   powstaje sama przy pierwszym użyciu (definicja jest też w schema.sql). */
function live_drops_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS live_drops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        steamid VARCHAR(32) NOT NULL,
        display_name VARCHAR(191) NOT NULL,
        case_id VARCHAR(40) NOT NULL,
        weapon VARCHAR(100) NOT NULL,
        skin VARCHAR(100) NOT NULL,
        wear VARCHAR(20) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        created_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = true;
}

/* $items - tablice z kluczami weapon/skin/wear/price (już oczyszczone w
   apply-case-open.php). */
function live_drops_record(PDO $pdo, string $steamid, string $displayName, string $caseId, array $items): void {
    try {
        live_drops_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO live_drops (steamid, display_name, case_id, weapon, skin, wear, price, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $now = now_ms();
        foreach ($items as $it) {
            $ins->execute([$steamid, mb_substr($displayName, 0, 191), $caseId, $it['weapon'], $it['skin'], $it['wear'], round($it['price'], 2), $now]);
        }
        // Co ~20. zapis przycinamy tabelę do ostatnich LIVE_DROPS_KEEP wpisów.
        if (random_int(1, 20) === 1) {
            $max = (int) $pdo->query('SELECT MAX(id) FROM live_drops')->fetchColumn();
            $pdo->prepare('DELETE FROM live_drops WHERE id <= ?')->execute([$max - LIVE_DROPS_KEEP]);
        }
    } catch (Throwable $e) {
        error_log('live_drops_record błąd: ' . $e->getMessage());
    }
}
