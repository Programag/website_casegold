<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

// POST jest dozwolony obok PUT wyłącznie dla navigator.sendBeacon() (patrz
// pushSync() w steam-auth.js) - beacon zawsze wysyła POST i nie pozwala
// ustawić metody, więc to jedyny sposób, żeby zapis "na wyjściu ze strony"
// mógł trafić do tego samego endpointu z identyczną logiką co zwykły PUT.
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'], true)) {
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
    // invCounter, w przeciwieństwie do xp/levelWatermark, do tej pory nie miał
    // dolnej bariery przez max() - nieszkodliwe, dopóki był czysto klienckim
    // licznikiem bez znaczenia poza deduplikacją w obrębie jednego zapisu.
    // Odkąd nowe, atomowe endpointy (patrz inc/state_ops.php) zaczynają nadawać
    // ID przedmiotom serwerowo i inne endpointy operują PO tych ID (sprzedaż,
    // blokada), spóźniony/przestarzały PUT z niższym invCounter mógłby cofnąć
    // licznik i doprowadzić do ponownego nadania już zajętego ID - stąd max().
    $invCounterFloor = ($state && isset($state['invCounter']) && is_numeric($state['invCounter'])) ? $state['invCounter'] : 0;

    $newState = [
        // balance/inventory/invCounter/level MUSZĄ paść wstecz na już
        // zapisaną wartość (tak jak każde inne pole niżej), a nie na twarde
        // 0/[] - inaczej jeden niekompletny/wadliwy push (np. urwane w
        // połowie żądanie) trwale zeruje saldo gracza zamiast po prostu nic
        // nie zmieniać w tym polu.
        'balance' => num_or($body, 'balance', $state['balance'] ?? 0),
        'inventory' => isset($body['inventory']) && is_array($body['inventory']) ? $body['inventory'] : ($state['inventory'] ?? []),
        'invCounter' => max(num_or($body, 'invCounter', $invCounterFloor), $invCounterFloor),
        'level' => num_or($body, 'level', $state['level'] ?? 0),
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
        // Kody prezentowe (api/redeem-gift-code.php) - ten sam wzorzec co
        // claimedLevelRewards wyżej: bierz z body jeśli klient je przysłał,
        // inaczej zostaw to, co JUŻ jest zapisane na serwerze - inaczej
        // zwykły, "nieświadomy" tego pola pełny push zerowałby listę
        // wykorzystanych kodów i pozwalał wykorzystać je ponownie.
        'redeemedGiftCodes' => isset($body['redeemedGiftCodes']) && is_array($body['redeemedGiftCodes']) ? $body['redeemedGiftCodes'] : ($state['redeemedGiftCodes'] ?? []),
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
