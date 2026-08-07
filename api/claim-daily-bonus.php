<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/state_rewards.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => 'not_logged_in']);
    exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $state = state_lock_user_for_update($pdo, $me['steamid']);
    if ($state === null) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'no_such_user']);
        exit;
    }

    // Uprawnienie i wysokość nagrody liczone WYŁĄCZNIE z tego, co serwer ma
    // już zapisane (dailyBonusAt/dailyStreak) - klient nie przysyła (i nie
    // mógłby wpłynąć na) żadnej wartości tu użytej.
    $status = daily_bonus_status($state);
    if (!$status['canClaim']) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'not_yet']);
        exit;
    }

    $state['balance'] = (is_numeric($state['balance'] ?? null) ? $state['balance'] : 0) + $status['reward'];
    $state['dailyBonusAt'] = now_ms();
    $state['dailyStreak'] = $status['effectiveStreak'] + 1;

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'reward' => $status['reward'],
        'newStreak' => $state['dailyStreak'],
        'day' => $status['pendingDay'],
        'state' => fix_state_object_fields($saved),
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/claim-daily-bonus błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
