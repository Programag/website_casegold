/* Dane sprzedawcy/administratora wyświetlane w regulamin.html i
   polityka-prywatnosci.html - JEDYNE miejsce do uzupełnienia. Wpisz wartości
   poniżej (puste pola zostają na stronie jako żółte "[do uzupełnienia]").
   Bez tych danych (nazwa firmy, adres, NIP) operatorzy płatności zwykle nie
   zweryfikują sklepu. */
const COMPANY = {
  name: "Piotr Koenig",     // pełna nazwa firmy, np. "Jan Kowalski CASEGOLD" lub "XYZ Sp. z o.o."
  address: "Lwowska 7F, 05-120 Legionowo",  // adres siedziby/miejsca wykonywania działalności
  nip: "6911994221",      // NIP
  regon: "390748408",    // REGON (jeśli posiadasz)
  krs: "",      // KRS (tylko spółki) - zostaw puste dla jednoosobowej działalności
  phone: "601 760 222",    // telefon kontaktowy
};
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-company]").forEach((el) => {
    const v = COMPANY[el.dataset.company];
    if (v) {
      el.textContent = v;
      el.classList.remove("ph");
    }
  });
});
