<?php
/* Ceny pakietów doładowania w PRAWDZIWYCH złotówkach - lustrzane odbicie
   TOPUP_PACKAGES w steam-auth.js (ten sam plik generuje panel "Doładuj
   swoje konto"). Jedyne źródło prawdy dla api/record-affiliate-deposit.php:
   klient przysyła tylko INDEKS klikniętego pakietu, nigdy samą cenę - żeby
   nie dało się sfałszować kwoty prowizji partnera. Kolejność ma znaczenie -
   indeks 0..4 musi się zgadzać z kolejnością TOPUP_PACKAGES w steam-auth.js. */
return [
    4.99,
    9.99,
    24.99,
    49.99,
    99.99,
];
