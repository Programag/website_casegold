<?php
/* Skopiuj ten plik jako "config.php" (bez ".example") i uzupełnij prawdziwymi
   danymi. config.php jest w .gitignore - NIE wgrywaj go do repozytorium,
   tylko bezpośrednio na hosting przez FTP, obok reszty plików. */
return [
    // Pełny adres strony BEZ ukośnika na końcu, np. "https://casegold.pl".
    // Używany do budowania linku powrotnego dla logowania Steam.
    'site_url' => 'https://your-domain.example',

    // Steam Web API key: https://steamcommunity.com/dev/apikey
    'steam_api_key' => 'your_steam_web_api_key',

    // Twój steamid64 - konto z dostępem do panelu admina.
    'admin_steamid' => 'your_steamid64',

    'db' => [
        'host' => 'localhost',
        'name' => 'your_db_name',
        'user' => 'your_db_user',
        'pass' => 'your_db_password',
    ],
];
