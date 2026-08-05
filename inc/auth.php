<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/users.php';

function cs2_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = str_starts_with(cs2_config()['site_url'], 'https://');
        session_set_cookie_params([
            'lifetime' => 90 * 24 * 60 * 60, // 90 dni, tak jak cookie.maxAge w Node
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/* Odpowiednik req.user z Passportem - null, jeśli gość albo konto zniknęło. */
function current_user(): ?array {
    cs2_session_start();
    $steamid = $_SESSION['steamid'] ?? null;
    if (!$steamid) return null;
    return get_user($steamid);
}

function login_steamid(string $steamid): void {
    cs2_session_start();
    session_regenerate_id(true);
    $_SESSION['steamid'] = $steamid;
}

function logout_session(): void {
    cs2_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/* Odpowiednik requireAdmin() middleware z server.js - kończy request, jeśli
   wołający nie jest zalogowanym adminem. */
function require_admin(): array {
    $u = current_user();
    $adminId = cs2_config()['admin_steamid'];
    if (!$u || !$adminId || $u['steamid'] !== $adminId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    return $u;
}

function require_login_json(): array {
    $u = current_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_logged_in']);
        exit;
    }
    return $u;
}
