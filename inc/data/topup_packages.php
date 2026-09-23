<?php
/* Pakiety doładowania - jedyne źródło prawdy dla api/topup-create.php i
   webhooka Przelewy24 (api/topup-webhook.php). Lustrzane odbicie
   TOPUP_PACKAGES w steam-auth.js (ten sam plik generuje panel "Doładuj
   swoje konto"), ale to TEN plik rozstrzyga, ile PRAWDZIWYCH złotych
   pobrać i ile wirtualnej waluty doliczyć - klient przysyła tylko INDEKS
   klikniętego pakietu, nigdy kwotę, żeby nie dało się sfałszować ceny ani
   ilości doliczanej waluty. Kolejność ma znaczenie - indeks 0..4 musi się
   zgadzać z kolejnością TOPUP_PACKAGES w steam-auth.js.

   'priceGrosze' - cena w GROSZACH (Przelewy24 przyjmuje kwoty jako liczbę
   całkowitą groszy, nie złotówki z przecinkiem - stąd osobne pole zamiast
   liczenia round(price*100) w locie, żeby uniknąć niespodzianek
   zaokrągleń). 'virtualAmount' - ile wirtualnych złotych trafia na saldo
   gracza po potwierdzonej płatności. */
return [
    ['priceGrosze' => 499, 'virtualAmount' => 5000],
    ['priceGrosze' => 999, 'virtualAmount' => 12000],
    ['priceGrosze' => 2499, 'virtualAmount' => 28000],
    ['priceGrosze' => 4999, 'virtualAmount' => 64000],
    ['priceGrosze' => 9999, 'virtualAmount' => 140000],
];
