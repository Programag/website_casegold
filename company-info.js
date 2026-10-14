/* Dane sprzedawcy/administratora wyświetlane w regulamin.html i
   polityka-prywatnosci.html - JEDYNE miejsce do uzupełnienia. Wpisz wartości
   poniżej (puste pola zostają na stronie jako żółte "[do uzupełnienia]").
   Bez tych danych (nazwa firmy, adres, NIP) operatorzy płatności zwykle nie
   zweryfikują sklepu. */
const COMPANY = {
  name: "",     // pełna nazwa firmy, np. "Jan Kowalski CASEGOLD" lub "XYZ Sp. z o.o."
  address: "",  // adres siedziby/miejsca wykonywania działalności
  nip: "",      // NIP
  regon: "",    // REGON (jeśli posiadasz)
  krs: "",      // KRS (tylko spółki) - zostaw puste dla jednoosobowej działalności
  phone: "",    // telefon kontaktowy
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
