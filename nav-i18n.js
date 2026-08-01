/* =========================================================================
   PRZEŁĄCZNIK JĘZYKA (Polski / English) DLA WSPÓLNEGO GÓRNEGO PASKA NAWIGACJI
   -------------------------------------------------------------------------
   Wstrzykuje przełącznik PL/EN do menu ustawień (#settingsMenu) na każdej
   podstronie i tłumaczy nawigację, przyciski resetu/ekwipunku i Daily Bonus.
   Wybór jest zapisywany w localStorage pod tym samym kluczem co na stronie
   głównej (index.html), więc jest spójny między podstronami. Strony mogą
   zdefiniować `window.onNavLangChange(lang)` żeby dotłumaczyć własną,
   specyficzną treść przy zmianie języka.
   ========================================================================= */
(function(){
  const LANG_KEY = "cs2sim_lang";
  const DICT = {
    pl: {
      cases:"Skrzynki", free:"Darmowe", battle:"Case Battle", upgrader:"Upgrader",
      tasks:"Zadania", inventory:"Ekwipunek",
      inventoryBtn:"Ekwipunek", dailyBtn:"🎁 Daily Bonus",
    },
    en: {
      cases:"Cases", free:"Free", battle:"Case Battle", upgrader:"Upgrader",
      tasks:"Tasks", inventory:"Inventory",
      inventoryBtn:"Inventory", dailyBtn:"🎁 Daily Bonus",
    }
  };

  function getLang(){ return localStorage.getItem(LANG_KEY) === "en" ? "en" : "pl"; }

  function applyNavLang(lang){
    const t = DICT[lang];
    const map = [
      ['a[href="index.html"]', t.cases],
      ['a[href="darmowe.html"]', t.free],
      ['a[href="battle.html"]', t.battle],
      ['a[href="upgrade.html"]', t.upgrader],
      ['button[data-soon]', t.tasks],
      ['a[href="equipment.html"]', t.inventory],
    ];
    map.forEach(([sel, label]) => {
      document.querySelectorAll(`.nav-links ${sel}`).forEach(el => { el.textContent = label; });
    });
    const invBtn = document.getElementById("inventoryBtn");
    if(invBtn) invBtn.textContent = t.inventoryBtn;
    const dailyBtn = document.getElementById("dailyBonusBtn");
    if(dailyBtn && !dailyBtn.disabled) dailyBtn.textContent = t.dailyBtn;
    document.querySelectorAll(".lang-opt").forEach(btn => btn.classList.toggle("active", btn.dataset.lang === lang));
  }

  function setLang(lang){
    localStorage.setItem(LANG_KEY, lang);
    applyNavLang(lang);
    if(typeof window.onNavLangChange === "function") window.onNavLangChange(lang);
  }

  function injectStyle(){
    if(document.getElementById("navLangStyle")) return;
    const style = document.createElement("style");
    style.id = "navLangStyle";
    style.textContent = `
      .lang-row{display:flex; border-top:1px solid var(--line);}
      .lang-row .lang-opt{flex:1; text-align:center; font-size:12px; padding:9px 6px; background:none; border:none; color:var(--text); cursor:pointer; font-family:'Inter',sans-serif;}
      .lang-row .lang-opt:hover{background:var(--panel-2); color:var(--hazard);}
      .lang-row .lang-opt.active{color:var(--hazard); background:var(--hazard-dim);}
    `;
    document.head.appendChild(style);
  }

  function injectLangRow(){
    const menu = document.getElementById("settingsMenu");
    if(!menu || document.getElementById("langRow")) return;
    const row = document.createElement("div");
    row.className = "lang-row";
    row.id = "langRow";
    row.innerHTML = `
      <button class="lang-opt" id="langPl" data-lang="pl">🇵🇱 Polski</button>
      <button class="lang-opt" id="langEn" data-lang="en">🇬🇧 English</button>
    `;
    menu.appendChild(row);
    document.getElementById("langPl").onclick = () => setLang("pl");
    document.getElementById("langEn").onclick = () => setLang("en");
  }

  function initNavLang(){
    injectStyle();
    injectLangRow();
    applyNavLang(getLang());
  }

  window.NavLang = { getLang, setLang, applyNavLang, initNavLang };

  if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", initNavLang);
  } else {
    initNavLang();
  }
})();
