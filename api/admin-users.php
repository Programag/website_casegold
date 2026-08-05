<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');
require_admin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = db()->query('SELECT * FROM users')->fetchAll();
    $list = array_map(function ($row) {
        $u = row_to_user($row);
        $st = $u['state'] ?? [];
        return [
            'steamid' => $u['steamid'],
            'displayName' => $u['display_name'],
            'avatar' => $u['avatar'],
            'balance' => is_numeric($st['balance'] ?? null) ? $st['balance'] : 0,
            'level' => is_numeric($st['level'] ?? null) ? $st['level'] : 0,
            'xp' => is_numeric($st['xp'] ?? null) ? $st['xp'] : 0,
            'claimedLevelRewards' => is_array($st['claimedLevelRewards'] ?? null) ? $st['claimedLevelRewards'] : [],
            'invCount' => is_array($st['inventory'] ?? null) ? count($st['inventory']) : 0,
            'updatedAt' => is_numeric($st['updatedAt'] ?? null) ? $st['updatedAt'] : null,
            'boostedDrop' => $u['boosted_drop'],
        ];
    }, $rows);
    usort($list, fn($a, $b) => ($b['updatedAt'] ?? 0) <=> ($a['updatedAt'] ?? 0));
    echo json_encode(['users' => $list]);
    exit;
}

if ($method === 'PUT') {
    $steamid = $_GET['steamid'] ?? '';
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE steamid = ? FOR UPDATE');
        $stmt->execute([$steamid]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'no_such_user']);
            exit;
        }
        $u = row_to_user($row);
        $state = $u['state'] ?? ['balance' => 0, 'inventory' => [], 'invCounter' => 0, 'level' => 0, 'xp' => 0];

        $boostedDrop = $u['boosted_drop'];
        if (isset($body['boostedDrop']) && is_bool($body['boostedDrop'])) $boostedDrop = $body['boostedDrop'];

        if (isset($body['balance']) && is_numeric($body['balance'])) $state['balance'] = $body['balance'];
        if (isset($body['level']) && is_numeric($body['level'])) {
            $state['level'] = $body['level'];
            // Ręczna edycja admina to świadoma, autorytatywna korekta - w
            // przeciwieństwie do normalnej gry może też OBNIŻYĆ wskaźnik
            // wodny (patrz komentarz w server.js), więc bez max().
            $state['levelWatermark'] = $body['level'];
        }
        if (isset($body['xp']) && is_numeric($body['xp'])) $state['xp'] = $body['xp'];
        $state['xpScaleMigratedV2'] = true;

        if (isset($body['claimedLevelRewards']) && is_array($body['claimedLevelRewards'])) {
            $state['claimedLevelRewards'] = array_values(array_filter($body['claimedLevelRewards'], fn($n) => is_numeric($n) && is_finite($n)));
        }

        if (($body['resetQuests'] ?? false) === true) {
            $state['questClaims'] = new stdClass();
            $state['casesOpened'] = 0;
            $state['spentCases'] = 0;
            $state['spentUpgrader'] = 0;
            $state['battlesPlayed'] = 0;
            $state['battlesWon'] = 0;
            $state['upgradeClicks'] = 0;
        }

        $now = now_ms();
        $state['adminOverrideAt'] = $now;
        $state['updatedAt'] = $now;

        $upd = $pdo->prepare('UPDATE users SET state = ?, boosted_drop = ? WHERE steamid = ?');
        $upd->execute([json_encode($state), $boostedDrop ? 1 : 0, $steamid]);
        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'user' => [
                'steamid' => $u['steamid'],
                'displayName' => $u['display_name'],
                'balance' => $state['balance'],
                'level' => $state['level'],
                'xp' => $state['xp'],
                'boostedDrop' => $boostedDrop,
            ],
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('PUT /api/admin/users błąd: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'storage_unavailable']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
