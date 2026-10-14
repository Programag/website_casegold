<?php
/* Tabela zamówień doładowań (Przelewy24) tworzy się sama przy pierwszym
   użyciu - na hostingu bez wygodnego dostępu do bazy nie trzeba jej
   ręcznie dodawać. Definicja jest też w schema.sql. Starsza tabela bez
   kolumny consent_at dostaje ją automatycznie. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/state_ops.php';
require_once __DIR__ . '/przelewy24.php';
require_once __DIR__ . '/affiliate.php';

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
        note VARCHAR(255) NULL,
        INDEX idx_steamid (steamid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$pdo->query("SHOW COLUMNS FROM topup_orders LIKE 'consent_at'")->fetch()) {
        $pdo->exec('ALTER TABLE topup_orders ADD COLUMN consent_at BIGINT NULL');
    }
    if (!$pdo->query("SHOW COLUMNS FROM topup_orders LIKE 'note'")->fetch()) {
        $pdo->exec('ALTER TABLE topup_orders ADD COLUMN note VARCHAR(255) NULL');
    }
    $done = true;
}

/* Krótka notatka diagnostyczna na zamówieniu (widoczna tylko adminowi na
   topup-return.html) - żeby było widać, na którym kroku utknęła płatność. */
function topup_note(PDO $pdo, string $sessionId, string $text): void {
    try {
        $pdo->prepare('UPDATE topup_orders SET note = ? WHERE session_id = ?')->execute([mb_substr(date('H:i:s') . ' ' . $text, 0, 255), $sessionId]);
    } catch (Throwable $e) {
        error_log('topup_note błąd: ' . $e->getMessage());
    }
}

/* Wspólny "koniec drogi" płatności - wołany z webhooka ORAZ z zapasowej
   ścieżki w topup-status.php. Autorytatywnym dowodem wpłaty jest wyłącznie
   własne, uwierzytelnione wywołanie p24_verify_transaction(); dopiero jego
   "success" księguje saldo (atomowo, idempotentnie). Zwraca:
   'paid' | 'already_paid' | 'not_confirmed' | 'verify_error' | 'storage_error'. */
function topup_finalize(PDO $pdo, array $order, $orderId): string {
    $sessionId = $order['session_id'];
    if ($order['status'] === 'paid') return 'already_paid';

    try {
        $confirmed = p24_verify_transaction($sessionId, $orderId, (int) $order['price_grosze']);
    } catch (Throwable $e) {
        error_log('topup_finalize błąd verify(): ' . $e->getMessage());
        topup_note($pdo, $sessionId, 'verify błąd: ' . $e->getMessage());
        return 'verify_error';
    }
    if (!$confirmed) {
        error_log("topup_finalize: verify() odrzucił $sessionId / orderId=$orderId");
        $pdo->prepare('UPDATE topup_orders SET status = "failed", p24_order_id = ? WHERE session_id = ? AND status = "pending"')->execute([(string) $orderId, $sessionId]);
        topup_note($pdo, $sessionId, 'verify: P24 nie potwierdziło transakcji');
        return 'not_confirmed';
    }

    $pdo->beginTransaction();
    try {
        // UPDATE ... WHERE status='pending' jest atomowy - równoległe wywołanie
        // (np. webhook + odpytanie) nie zaksięguje drugi raz (rowCount() == 0).
        $claim = $pdo->prepare('UPDATE topup_orders SET status = "paid", p24_order_id = ?, paid_at = ? WHERE session_id = ? AND status = "pending"');
        $claim->execute([(string) $orderId, now_ms(), $sessionId]);
        if ($claim->rowCount() === 0) {
            $pdo->rollBack();
            return 'already_paid';
        }
        $state = state_lock_user_for_update($pdo, $order['steamid']);
        if ($state === null) {
            throw new RuntimeException('Użytkownik z zamówienia zniknął: ' . $order['steamid']);
        }
        $state['balance'] = (is_numeric($state['balance'] ?? null) ? $state['balance'] : 0) + (int) $order['virtual_amount'];
        state_save($pdo, $order['steamid'], $state);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('topup_finalize błąd księgowania: ' . $e->getMessage());
        topup_note($pdo, $sessionId, 'księgowanie błąd: ' . $e->getMessage());
        return 'storage_error';
    }

    topup_note($pdo, $sessionId, 'zaksięgowano');
    // Prowizja partnerska - osobna transakcja na koncie WŁAŚCICIELA kodu;
    // błąd tutaj nie cofa już zaksięgowanej wpłaty kupującego.
    credit_affiliate_for_deposit($pdo, $order['affiliate_code'], $order['steamid'], $order['price_grosze'] / 100);
    return 'paid';
}
