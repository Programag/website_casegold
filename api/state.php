<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

function num_or(array $body, string $key, $default) {
    return isset($body[$key]) && is_numeric($body[$key]) ? $body[$key] : $default;
}

$pdo = db();
$pdo->beginTransaction();
try {
    // SELECT ... FOR UPDATE - blokada na TYM JEDNYM wierszu, żeby równoległy
    // zapis (np. admin edytujący saldo w tym samym momencie) nie zgubił
    // żadnej ze zmian ("lost update"), dokładnie to, co withUsersLock robił
    // globalnie w Node (tu wystarczy zrobić to per wiersz).
    $stmt = $pdo->prepare('SELECT * FROM users WHERE steamid = ? FOR UPDATE');
    $stmt->execute([$me['steamid']]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'no_such_user']);
        exit;
    }
    $state = $row['state'] !== null ? json_decode($row['state'], true) : null;

    // Ochrona przed nadpisaniem ręcznej edycji z panelu admina (albo zapisu z
    // innej karty/urządzenia) przez spóźniony, "nieświadomy" tego push - patrz
    // ten sam mechanizm w PUT /api/state z server.js.
    if ($state && isset($state['updatedAt']) && is_numeric($state['updatedAt'])
        && isset($body['baseUpdatedAt']) && is_numeric($body['baseUpdatedAt'])
        && $state['updatedAt'] > $body['baseUpdatedAt']) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'stale_write', 'state' => fix_state_object_fields($state)]);
        exit;
    }

    $bestPull = (isset($body['bestPull']) && is_array($body['bestPull']) && isset($body['bestPull']['price']) && is_numeric($body['bestPull']['price']))
        ? [
            'weapon' => (string) ($body['bestPull']['weapon'] ?? ''),
            'skin' => (string) ($body['bestPull']['skin'] ?? ''),
            'wear' => (string) ($body['bestPull']['wear'] ?? ''),
            'price' => $body['bestPull']['price'],
            'at' => isset($body['bestPull']['at']) && is_numeric($body['bestPull']['at']) ? $body['bestPull']['at'] : now_ms(),
        ]
        : ($state['bestPull'] ?? null);

    $isBrandNewAccount = $state === null;
    $state = ensure_level_watermark($state); // no-op dla null; dla reszty patrz komentarz w users.php
    $xpFloor = ($state && isset($state['xp']) && is_numeric($state['xp'])) ? $state['xp'] : 0;

    $newState = [
        'balance' => num_or($body, 'balance', 0),
        'inventory' => isset($body['inventory']) && is_array($body['inventory']) ? $body['inventory'] : [],
        'invCounter' => num_or($body, 'invCounter', 0),
        'level' => num_or($body, 'level', 0),
        'xp' => max(num_or($body, 'xp', 0), $xpFloor),
        'dailyBonusAt' => num_or($body, 'dailyBonusAt', null),
        'dailyStreak' => num_or($body, 'dailyStreak', $state['dailyStreak'] ?? 0),
        'freeCaseAt' => num_or($body, 'freeCaseAt', null),
        'bestPull' => $bestPull,
        'upgradeClicks' => num_or($body, 'upgradeClicks', $state['upgradeClicks'] ?? 0),
        'casesOpened' => num_or($body, 'casesOpened', $state['casesOpened'] ?? 0),
        'spentCases' => num_or($body, 'spentCases', $state['spentCases'] ?? 0),
        'spentUpgrader' => num_or($body, 'spentUpgrader', $state['spentUpgrader'] ?? 0),
        'battlesPlayed' => num_or($body, 'battlesPlayed', $state['battlesPlayed'] ?? 0),
        'battlesWon' => num_or($body, 'battlesWon', $state['battlesWon'] ?? 0),
        // json_decode(..., true) zamienia {} na pustą tablicę PHP, a
        // json_encode pustej tablicy daje z powrotem [] zamiast {} - trzeba
        // to jawnie utrzymać jako obiekt, inaczej klient (który traktuje
        // questClaims jak zwykły obiekt JS) dostałby tablicę zamiast obiektu.
        'questClaims' => (function () use ($body, $state) {
            $v = isset($body['questClaims']) && is_array($body['questClaims']) ? $body['questClaims'] : ($state['questClaims'] ?? []);
            return empty($v) ? new stdClass() : $v;
        })(),
        'claimedLevelRewards' => isset($body['claimedLevelRewards']) && is_array($body['claimedLevelRewards']) ? $body['claimedLevelRewards'] : ($state['claimedLevelRewards'] ?? []),
        'battleHistory' => isset($body['battleHistory']) && is_array($body['battleHistory']) ? $body['battleHistory'] : ($state['battleHistory'] ?? []),
        'levelWatermark' => max(num_or($body, 'levelWatermark', 0), $state['levelWatermark'] ?? 0),
        'xpScaleMigratedV2' => $isBrandNewAccount ? true : (bool) ($state['xpScaleMigratedV2'] ?? false),
        'adminOverrideAt' => (isset($state['adminOverrideAt']) && is_numeric($state['adminOverrideAt'])) ? $state['adminOverrideAt'] : null,
        'updatedAt' => now_ms(),
    ];

    $upd = $pdo->prepare('UPDATE users SET state = ? WHERE steamid = ?');
    $upd->execute([json_encode($newState), $me['steamid']]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'updatedAt' => $newState['updatedAt']]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('PUT /api/state błąd zapisu: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'storage_unavailable']);
}
