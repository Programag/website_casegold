<?php
/* Czyste adresy bez ".html" (np. /equipment zamiast /equipment.html).
   Hosting nie wykonuje reguł mod_rewrite, więc zamiast przepisywania adresów
   używamy ErrorDocument 404 z .htaccess: gdy przeglądarka prosi o /equipment,
   a takiego pliku nie ma, Apache wywołuje ten skrypt (REQUEST_URI zostaje
   oryginalny), a on odsyła zawartość equipment.html ze statusem 200.
   Zapytanie (?u=..., ?lobby=...) i #hash zostają po stronie przeglądarki,
   więc strony czytają je tak samo jak wcześniej. */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// Tylko pojedynczy segment bez końcowego ukośnika (/equipment) - przy
// /equipment/ ścieżki względne (steam-auth.js itd.) wskazywałyby na złe miejsce.
if (preg_match('#^/([A-Za-z0-9_-]+)$#', $path, $m)) {
    $file = __DIR__ . '/' . $m[1] . '.html';
    if (is_file($file)) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 - CASEGOLD</title></head>'
   . '<body style="font-family:sans-serif;background:#0c0e14;color:#e8eaf0;text-align:center;padding:80px 16px">'
   . '<h1>404</h1><p>Nie znaleziono strony.</p><p><a href="/" style="color:#ff9500">Wróć na stronę główną</a></p></body></html>';
