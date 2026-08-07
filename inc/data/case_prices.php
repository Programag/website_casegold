<?php
/* Ceny skrzynek (tryb standardowy, nie-Jester) - jedyne źródło prawdy dla
   kosztu otwarcia w api/apply-case-open.php. Klucz = nazwa pliku case_*.html
   bez rozszerzenia (identyfikator, który klient przesyła jako caseId).
   Portowane z CASE_PRICE w każdym case_*.html - jeśli tam się zmieni cena,
   trzeba ją zmienić też tutaj (obie strony są małe i rzadko się zmieniają,
   więc ręczne utrzymanie w zgodzie jest tu uzasadnione - w przeciwieństwie
   do GENERAL_SKIN_DB, to nie jest coś generowanego mechanicznie). */
return [
    'case_angel' => 18253.30,
    'case_cosmos' => 3501.80,
    'case_devil' => 6542.80,
    'case_diamond' => 108.00,
    'case_flower' => 12.40,
    'case_gold' => 20.30,
    'case_ice' => 1020.80,
    'case_jungle' => 22.00,
    'case_knifes' => 998.50,
    'case_lava' => 436.70,
    'case_neon' => 196.70,
    'case_noir' => 5.40,
    'case_poland' => 665.70,
    'case_restricted' => 9.60,
    'case_robot_sticker' => 198.40,
    'case_sand' => 9.20,
    'case_simulator' => 1.60,
    'case_slime' => 1056.70,
    'case_snow' => 71.80,
    'case_ultra' => 228.10,
    'case_waste' => 1921.90,
    'case_water' => 2.70,
    'case_web' => 3998.50,
];
