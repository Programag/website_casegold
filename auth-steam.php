<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/steam_openid.php';

// Bezpośrednia nazwa pliku - hosting nie ma działającego mod_rewrite,
// więc Steam musi odesłać przeglądarkę wprost na auth-steam-return.php.
$siteUrl = rtrim(cs2_config()['site_url'], '/');
// TYMCZASOWO: ?debug=1 w return_to, żeby diagnostyka w auth-steam-return.php
// zadziałała już przy PIERWSZYM naturalnym powrocie ze Steam (bez konieczności
// przeładowania URL-a, co i tak unieważnia token OpenID przy drugim użyciu).
// USUNĄĆ "?debug=1" stąd po znalezieniu przyczyny.
header('Location: ' . steam_openid_login_url($siteUrl . '/auth-steam-return.php?debug=1', $siteUrl . '/'));
exit;
