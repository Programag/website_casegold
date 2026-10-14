<?php
/* Tabela zamówień doładowań (Przelewy24) tworzy się sama przy pierwszym
   użyciu - na hostingu bez wygodnego dostępu do bazy nie trzeba jej
   ręcznie dodawać. Definicja jest też w schema.sql. Starsza tabela bez
   kolumny consent_at dostaje ją automatycznie. */
require_once __DIR__ . '/db.php';

function topup_ensure_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS topup_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(64) NOT NULL UNIQUE,
        steamid VARCHAR(32) NOT NULL,
        package_index TINYINT UNSIGNED NOT NULL,
        price_grosze INT UNSIGNED NOT NULL,
        virtual_amount INT UNSIGNED NOT NULL,
        affiliate_code VARCHAR(20) NULL,
        status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
        p24_order_id VARCHAR(64) NULL,
        created_at BIGINT NOT NULL,
        consent_at BIGINT NULL,
        paid_at BIGINT NULL,
        INDEX idx_steamid (steamid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$pdo->query("SHOW COLUMNS FROM topup_orders LIKE 'consent_at'")->fetch()) {
        $pdo->exec('ALTER TABLE topup_orders ADD COLUMN consent_at BIGINT NULL');
    }
    $done = true;
}
