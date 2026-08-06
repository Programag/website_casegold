<?php
/* Pojedyncze połączenie PDO (leniwie tworzone), używane przez wszystkie
   endpointy. */

// Ten plik jest require'owany przez KAŻDY endpoint api/*.php jako pierwszy,
// więc to jedyne miejsce, które gwarantuje ustawienie tego globalnie. Bez
// display_errors=0 dowolne ostrzeżenie/notice PHP (np. na hostingu z innym
// php.ini niż lokalne środowisko testowe) trafia do treści odpowiedzi PRZED
// echo json_encode(...) - klient dostaje "warning-tekst\n{...}" zamiast
// czystego JSON-a, co fetch().json() traktuje jak błąd sieci, mimo że sam
// zapis do bazy (np. utworzenie lobby bitwy) już się powiódł. Stąd objaw
// "nie udało się utworzyć bitwy" + lobby mimo to widoczne w liczniku
// aktywnych bitew. Błędy nadal trafiają do logu PHP (error_log), tylko nie
// do treści odpowiedzi.
error_reporting(E_ALL);
ini_set('display_errors', '0');

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
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
    return $pdo;
}

/* Bieżący czas w milisekundach - odpowiednik Date.now() z Node, bo
   `updatedAt`/`createdAt`/itd. w całym systemie są trzymane w ms. */
function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}
