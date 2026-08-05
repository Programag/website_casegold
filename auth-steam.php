<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/steam_openid.php';

// Bezpośrednia nazwa pliku - hosting nie ma działającego mod_rewrite,
// więc Steam musi odesłać przeglądarkę wprost na auth-steam-return.php.
$siteUrl = rtrim(cs2_config()['site_url'], '/');
header('Location: ' . steam_openid_login_url($siteUrl . '/auth-steam-return.php', $siteUrl . '/'));
exit;
