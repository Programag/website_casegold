<?php
/* Kody prezentowe rozdawane na serwerze Discord CASEGOLD, wpisywane na
   darmowe.html (api/redeem-gift-code.php). Klucz musi być WIELKIMI LITERAMI -
   normalizacja (trim + strtoupper) wpisanego kodu dzieje się w endpointcie,
   więc tu wystarczy pisać docelową, znormalizowaną postać.

   type "money" -> 'amount' dopisywane bezpośrednio do salda gracza.
   type "case"  -> +'count' (domyślnie 1) darmowych otwarć KONKRETNEJ
   skrzynki ('caseId' - musi być kluczem w inc/data/case_prices.php).
   Gracz odbiera nagrodę na darmowe.html, a faktycznie otwiera skrzynkę (z
   jej prawdziwą tabelą przedmiotów) na case_*.html przyciskiem
   "Otwórz za darmo" - patrz useFreeCredit w api/apply-case-open.php.
   'label' to nazwa wyświetlana w komunikacie sukcesu (ta sama, co
   CASE_NAME na danej stronie case_*.html).

   'addedAt' - data dodania kodu (string do strtotime, np. 'YYYY-MM-DD').
   Kod automatycznie WYGASA 5 dni po tej dacie (GIFT_CODE_TTL_DAYS w
   api/redeem-gift-code.php) - po tym terminie zwraca błąd "code_expired",
   tak jakby kod już nie istniał, ale z osobnym komunikatem na darmowe.html.
   Wymagane dla każdego kodu.

   Żeby dodać nowy kod: dopisz kolejną linię niżej z dzisiejszą datą jako
   'addedAt' i wdróż (nowy kod działa od razu po deployu i wygasa
   automatycznie po 5 dniach - nie trzeba go ręcznie usuwać). Każdy kod może
   zostać wykorzystany raz na konto (redeemedGiftCodes w stanie gracza). */
return [
    'ULTRASYF' => ['type' => 'case', 'caseId' => 'case_ultra', 'label' => 'Ultra Case', 'addedAt' => '2026-08-09'],
    'DARMOWE2000' => ['type' => 'money', 'amount' => 2000, 'addedAt' => '2026-08-09'],
];
