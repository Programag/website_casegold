<?php
/* Pojedyncze połączenie PDO (leniwie tworzone), używane przez wszystkie
   endpointy. */

function cs2_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            die('Brak config.php - skopiuj config.example.php jako config.php i uzupełnij dane.');
        }
        $cfg = require $path;
    }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cs2_config()['db'];
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

/* Bieżący czas w milisekundach - odpowiednik Date.now() z Node, bo
   `updatedAt`/`createdAt`/itd. w całym systemie są trzymane w ms. */
function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}
