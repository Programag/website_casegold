<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/steam_openid.php';

// Ścieżka publiczna (przez .htaccess), nie nazwa pliku - Steam odsyła
// przeglądarkę dokładnie pod ten URL, .htaccess dopiero wtedy kieruje go
// z powrotem na auth-steam-return.php.
$siteUrl = rtrim(cs2_config()['site_url'], '/');
header('Location: ' . steam_openid_login_url($siteUrl . '/auth/steam/return', $siteUrl . '/'));
exit;
