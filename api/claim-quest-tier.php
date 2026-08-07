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

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$taskId = isset($body['taskId']) && is_string($body['taskId']) ? $body['taskId'] : null;
$tierIndex = isset($body['tierIndex']) && is_numeric($body['tierIndex']) ? (int) $body['tierIndex'] : null;

// reward NIE jest tu przyjmowane od klienta - serwer sam liczy je z QUESTS.
if (!$taskId || !isset(QUESTS[$taskId]) || $tierIndex === null || $tierIndex < 0 || $tierIndex > 3) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
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

    $claims = is_array($state['questClaims'] ?? null) ? $state['questClaims'] : [];
    $already = is_numeric($claims[$taskId] ?? null) ? (int) $claims[$taskId] : 0;
    // tylko następny nieodebrany poziom, po kolei - tak samo jak dotychczasowa
    // logika kliencka w claimQuestTier() (steam-auth.js), teraz egzekwowane
    // przez serwer zamiast tylko przez UI.
    if ($tierIndex !== $already) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'not_next_tier', 'alreadyClaimed' => $already]);
        exit;
    }

    $def = QUESTS[$taskId];
    $threshold = $def['tiers'][$tierIndex];
    $currentValue = quest_current_value($taskId, $state);
    if ($currentValue < $threshold) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'threshold_not_met', 'currentValue' => $currentValue, 'threshold' => $threshold]);
        exit;
    }

    $reward = $def['rewards'][$tierIndex];
    $claims[$taskId] = $already + 1;
    $state['questClaims'] = $claims;
    $state['balance'] = (is_numeric($state['balance'] ?? null) ? $state['balance'] : 0) + $reward;

    $saved = state_save($pdo, $me['steamid'], $state);
    $pdo->commit();

    echo json_encode(['ok' => true, 'reward' => $reward, 'state' => fix_state_object_fields($saved)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('POST /api/claim-quest-tier błąd: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
