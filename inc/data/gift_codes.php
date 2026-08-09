<?php
/* Kody prezentowe rozdawane na serwerze Discord CASEGOLD, wpisywane na
   darmowe.html (api/redeem-gift-code.php). Klucz musi być WIELKIMI LITERAMI -
   normalizacja (trim + strtoupper) wpisanego kodu dzieje się w endpointcie,
   więc tu wystarczy pisać docelową, znormalizowaną postać.

   type "money" -> 'amount' dopisywane bezpośrednio do salda gracza.
   type "case"  -> losowy przedmiot z GIFT_CASE_POOL (inc/state_rewards.php),
   dokładnie tak samo jak przy zwykłej wygranej ze skrzynki.

   Żeby dodać nowy kod: dopisz kolejną linię niżej i wdróż (nowy kod działa
   od razu po deployu, stare kody nie znikają same - usuń ręcznie, jeśli mają
   przestać działać). Każdy kod może zostać wykorzystany raz na konto
   (redeemedGiftCodes w stanie gracza). */
return [
    'DISCORD500' => ['type' => 'money', 'amount' => 500],
    'DISCORD1000' => ['type' => 'money', 'amount' => 1000],
    'CASEGOLDCASE' => ['type' => 'case'],
];
