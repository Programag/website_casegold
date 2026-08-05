/* =========================================================================
   TRYB JESTER — WSPÓLNY PLIK
   -------------------------------------------------------------------------
   W trybie Jester każdy przedmiot w skrzynce ma identyczne prawdopodobieństwo
   wylosowania: P = 1/N (rozkład jednostajny), zamiast wag opartych na cenie
   (start/end na osi 1..100000).

   Cena skrzynki w tym trybie jest przeliczana tak, żeby zachować TĘ SAMĄ
   marżę serwisu (M), jaką ma skrzynka w trybie standardowym:
     M = C_standard / EV_ważone(standard) − 1
     C_Jester = średnia(cen wszystkich N przedmiotów) × (1 + M)
   ========================================================================= */

/* Zwraca cenę skrzynki w trybie Jester na podstawie listy przedmiotów
   (każdy musi mieć pola price, start, end) i standardowej ceny skrzynki. */
function computeJesterPrice(items, normalPrice){
  const N = items.length;
  if(N === 0) return normalPrice;
  const evWeighted = items.reduce((s, it) => s + it.price * ((it.end - it.start + 1) / 100000), 0);
  const avgPrice = items.reduce((s, it) => s + it.price, 0) / N;
  const M = evWeighted > 0 ? (normalPrice / evWeighted - 1) : 0;
  return avgPrice * (1 + M);
}

/* Losuje przedmiot z jednostajnym prawdopodobieństwem 1/N. */
function rollItemUniform(items){
  return items[Math.floor(Math.random() * items.length)];
}

/* Jednostajna szansa (w %) na pojedynczy przedmiot dla N-elementowej puli. */
function jesterChancePercent(items){
  return items.length ? 100 / items.length : 0;
}
