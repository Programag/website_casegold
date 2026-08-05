<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/users.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// W wersji Node czyściło to cache procesu (pamięć) + Redis dla dzisiejszej
// i każdej innej daty już wczytanej w tym uruchomieniu. Tu nie ma cache'a
// procesu (każdy request jest bezstanowy, źródłem prawdy jest zawsze
// baza) - czyszczenie całej tabeli osiąga dokładnie ten sam efekt.
db()->exec('DELETE FROM daily_top');

echo json_encode(['ok' => true]);
