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

    // Dane sprzedawcy z panelu Przelewy24 (panel.przelewy24.pl ->
    // "Twoje sklepy" -> ustawienia sklepu -> zakładka "Dane API"). merchant_id
    // i pos_id są zwykle takie same. crc to osobny klucz z tej samej
    // zakładki (do podpisywania żądań), api_key to REST API key (Basic Auth
    // do wywołań register/verify) - NIE to samo, co crc, mimo że oba
    // wyglądają jak losowe ciągi znaków. Ustaw sandbox=true, dopóki nie
    // masz zweryfikowanego konta produkcyjnego - sandbox.przelewy24.pl ma
    // WŁASNE, testowe dane sprzedawcy (inne niż produkcyjne), które
    // dostajesz po rejestracji konta testowego na przelewy24.pl.
    'p24' => [
        'merchant_id' => 0,
        'pos_id' => 0,
        'crc' => 'your_p24_crc_key',
        'api_key' => 'your_p24_rest_api_key',
        'sandbox' => true,
    ],
];
