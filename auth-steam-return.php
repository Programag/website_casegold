<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/steam_openid.php';
require_once __DIR__ . '/inc/users.php';
require_once __DIR__ . '/inc/auth.php';

$debugInfo = null;
$steamid = steam_openid_verify($debugInfo);
if (!$steamid) {
    // TYMCZASOWA diagnostyka logowania Steam - USUNĄĆ po znalezieniu przyczyny.
    if (($_GET['debug'] ?? '') === '1') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Weryfikacja Steam nie powiodła się.\n\n";
        echo json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: /');
    exit;
}

$profile = steam_fetch_player_summary(cs2_config()['steam_api_key'], $steamid);
upsert_user_from_steam($steamid, $profile['displayName'], $profile['avatar'], $profile['profileUrl']);
login_steamid($steamid);

header('Location: /');
exit;
