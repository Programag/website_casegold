<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/steam_openid.php';
require_once __DIR__ . '/inc/users.php';
require_once __DIR__ . '/inc/auth.php';

$steamid = steam_openid_verify();
if (!$steamid) {
    header('Location: /');
    exit;
}

$profile = steam_fetch_player_summary(cs2_config()['steam_api_key'], $steamid);
upsert_user_from_steam($steamid, $profile['displayName'], $profile['avatar'], $profile['profileUrl']);
login_steamid($steamid);

header('Location: /');
exit;
