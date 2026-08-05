/* =========================================================================
   LOGOWANIE PRZEZ STEAM + SYNCHRONIZACJA KONTA
   -------------------------------------------------------------------------
   Ten skrypt MUSI być pierwszym <script> na stronie (przed nav-i18n.js i
   przed inline-owym skryptem strony), bo synchronicznie odpytuje /api/me
   zanim reszta kodu strony zdąży odczytać localStorage. Dzięki temu żadna
   z podstron nie musi znać backendu - dalej czytają/zapisują ten sam klucz
   localStorage co wcześniej, a ten plik po cichu przenosi te dane na serwer
   i z powrotem, gdy użytkownik jest zalogowany przez Steam.
   ========================================================================= */
(function () {
  const STATE_KEY = "cs2sim_state_v1";
  const DAILY_KEY = "cs2sim_daily_bonus_at";
  const DAILY_STREAK_KEY = "cs2sim_daily_streak";
  const FREE_CASE_KEY = "cs2sim_free_case_at";
  const LANG_KEY = "cs2sim_lang";
  // Ostatni updatedAt serwera, jaki ta karta faktycznie widziała (nie mylić z
  // updatedAt w STATE_KEY, który zmienia się przy KAŻDYM lokalnym zapisie).
  // Wysyłany przy każdym pushu, żeby serwer mógł wykryć, że ktoś/coś zmieniło
  // stan w międzyczasie (np. ręczna edycja salda z panelu admina) i odrzucić
  // spóźniony, nieświadomy tego push zamiast go bezmyślnie nadpisać.
  const SERVER_BASE_KEY = "cs2sim_server_base_at";
  // Ostatni adminOverrideAt, jaki ta konkretna przeglądarka już przyjęła -
  // patrz sekcja "admin override" niżej.
  const ADMIN_OVERRIDE_KEY = "cs2sim_admin_override_at";

  const STEAM_ICON_SVG = '<svg viewBox="0 0 24 24"><path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.03 4.524 4.524s-2.03 4.524-4.524 4.524h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.605.001 11.979.001zM7.54 18.21l-1.473-.61c.262.543.714.999 1.314 1.25 1.297.539 2.793-.076 3.332-1.375.263-.63.264-1.319.005-1.949s-.75-1.121-1.377-1.383c-.624-.26-1.29-.249-1.878-.03l1.523.63c.956.4 1.409 1.5 1.009 2.455-.397.957-1.497 1.41-2.454 1.012H7.54zm11.415-9.303c0-1.662-1.353-3.015-3.015-3.015-1.665 0-3.015 1.353-3.015 3.015 0 1.665 1.35 3.015 3.015 3.015 1.663 0 3.015-1.35 3.015-3.015zm-5.273-.005c0-1.252 1.013-2.266 2.265-2.266 1.249 0 2.266 1.014 2.266 2.266 0 1.251-1.017 2.265-2.266 2.265-1.253 0-2.265-1.014-2.265-2.265z"/></svg>';
  function steamIconBadge() { return `<span class="steam-icon-badge">${STEAM_ICON_SVG}</span>`; }

  function fetchMeSync() {
    try {
      const xhr = new XMLHttpRequest();
      xhr.open("GET", "/api/me", false); // synchroniczne - celowo, patrz komentarz na górze pliku
      xhr.send(null);
      if (xhr.status >= 200 && xhr.status < 300) return JSON.parse(xhr.responseText);
    } catch (e) {
      /* backend niedostępny (np. otwarcie pliku bez `node server.js`) - działamy jak gość */
    }
    return { loggedIn: false };
  }

  const me = fetchMeSync();
  window.__STEAM_AUTH__ = me;

  if (me.loggedIn && me.user && me.user.state) {
    const s = me.user.state;
    try {
      // Push na serwer jest debounce'owany (500ms) i wysyłany asynchronicznie
      // przy beforeunload - jeśli gracz kliknie kolejną stronę tuż po wygranej,
      // synchroniczne /api/me tej nowej strony potrafi odpowiedzieć zanim
      // serwer zdąży zapisać ten push. Bez tej straży świeży lokalny stan
      // (dopiero co wygrany przedmiot, zaktualizowane saldo) zostałby
      // nadpisany starszą kopią z serwera - dokładnie objaw "saldo wraca do
      // pierwotnego, przedmiotu nie ma w ekwipunku". Ufamy więc serwerowi
      // tylko wtedy, gdy jego updatedAt jest rzeczywiście świeższy niż to,
      // co mamy już lokalnie.
      let localUpdatedAt = 0;
      let localState = {};
      try { localState = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); localUpdatedAt = localState.updatedAt || 0; } catch (e2) {}
      const serverUpdatedAt = typeof s.updatedAt === "number" ? s.updatedAt : 0;
      localStorage.setItem(SERVER_BASE_KEY, String(serverUpdatedAt));
      // levelWatermark rośnie WYŁĄCZNIE przez Math.max, więc scalanie klient
      // <-> serwer jest odporne na wyścigi z definicji - w przeciwieństwie do
      // reszty stanu (balance/inventory/...), gdzie "świeższy wygrywa" jest
      // słuszną ochroną przed nadpisaniem, dla poziomu liczy się tylko to,
      // żeby nigdy nie spadł, niezależnie od tego, które zapisanie wygra
      // poniższe porównanie znaczników czasu.
      const mergedWatermark = Math.max(
        typeof localState.levelWatermark === "number" ? localState.levelWatermark : 0,
        typeof s.levelWatermark === "number" ? s.levelWatermark : 0
      );
      // Jednorazowa migracja skali xp (x10, patrz ensureLevelWatermark w
      // server.js) MUSI się przyjąć niezależnie od tego, kto jest "świeższy" -
      // localState.updatedAt jest ustawiany na Date.now() przy KAŻDYM lokalnym
      // zapisie (patrz readPersistentExtras niżej), więc w praktyce niemal
      // zawsze wygrywa z serwerowym znacznikiem. Bez tej gałęzi serwer mógłby
      // poprawnie przeskalować i trwale zapisać xp, a ta konkretna przeglądarka
      // nigdy by tej korekty nie odebrała - grałaby dalej na starej, 10x za
      // niskiej bazie, więc pasek postępu zostałby zamrożony na zawsze mimo
      // dalszego zdobywania EXP (dokładnie objaw "0 EXP, nic się nie zmienia").
      const localXpMigrated = !!localState.xpScaleMigratedV2;
      const serverXpMigrated = !!s.xpScaleMigratedV2;
      const adoptServerXp = serverXpMigrated && !localXpMigrated;
      // Ręczna korekta z panelu admina to świadome, autorytatywne działanie -
      // musi wygrać z KAŻDĄ z powyższych ochron (świeżość, Math.max na
      // wskaźniku poziomu, migracja skali xp), bo te istnieją tylko po to,
      // żeby chronić przed PRZYPADKOWĄ utratą postępu przy normalnej grze, a
      // nie po to, żeby blokować admina, który świadomie coś poprawia (np.
      // obniża błędnie przyznany poziom/EXP). Serwer zgłasza to przez
      // adminOverrideAt - jeśli jest nowszy niż to, co ta przeglądarka już
      // widziała, w pełni ufamy całemu stanowi z serwera, bez żadnego Math.max.
      const serverAdminOverrideAt = typeof s.adminOverrideAt === "number" ? s.adminOverrideAt : 0;
      let localAdminOverrideAt = 0;
      try { localAdminOverrideAt = Number(localStorage.getItem(ADMIN_OVERRIDE_KEY) || 0) || 0; } catch (e3) {}
      const adminJustOverrode = serverAdminOverrideAt > localAdminOverrideAt;
      if (adminJustOverrode) {
        localStorage.setItem(ADMIN_OVERRIDE_KEY, String(serverAdminOverrideAt));
        localStorage.setItem(STATE_KEY, JSON.stringify({
          balance: s.balance,
          inventory: s.inventory,
          invCounter: s.invCounter,
          level: typeof s.level === "number" ? s.level : 0,
          xp: typeof s.xp === "number" ? s.xp : 0,
          bestPull: s.bestPull || null,
          upgradeClicks: typeof s.upgradeClicks === "number" ? s.upgradeClicks : 0,
          casesOpened: typeof s.casesOpened === "number" ? s.casesOpened : 0,
          spentCases: typeof s.spentCases === "number" ? s.spentCases : 0,
          spentUpgrader: typeof s.spentUpgrader === "number" ? s.spentUpgrader : 0,
          battlesPlayed: typeof s.battlesPlayed === "number" ? s.battlesPlayed : 0,
          battlesWon: typeof s.battlesWon === "number" ? s.battlesWon : 0,
          questClaims: s.questClaims && typeof s.questClaims === "object" ? s.questClaims : {},
          claimedLevelRewards: Array.isArray(s.claimedLevelRewards) ? s.claimedLevelRewards : [],
          battleHistory: Array.isArray(s.battleHistory) ? s.battleHistory : [],
          levelWatermark: typeof s.levelWatermark === "number" ? s.levelWatermark : 0,
          xpScaleMigratedV2: !!s.xpScaleMigratedV2,
          updatedAt: serverUpdatedAt,
        }));
      } else if (serverUpdatedAt >= localUpdatedAt) {
        localStorage.setItem(STATE_KEY, JSON.stringify({
          balance: s.balance,
          inventory: s.inventory,
          invCounter: s.invCounter,
          level: typeof s.level === "number" ? s.level : 0,
          xp: typeof s.xp === "number" ? s.xp : 0,
          bestPull: s.bestPull || null,
          upgradeClicks: typeof s.upgradeClicks === "number" ? s.upgradeClicks : 0,
          casesOpened: typeof s.casesOpened === "number" ? s.casesOpened : 0,
          spentCases: typeof s.spentCases === "number" ? s.spentCases : 0,
          spentUpgrader: typeof s.spentUpgrader === "number" ? s.spentUpgrader : 0,
          battlesPlayed: typeof s.battlesPlayed === "number" ? s.battlesPlayed : 0,
          battlesWon: typeof s.battlesWon === "number" ? s.battlesWon : 0,
          questClaims: s.questClaims && typeof s.questClaims === "object" ? s.questClaims : {},
          claimedLevelRewards: Array.isArray(s.claimedLevelRewards) ? s.claimedLevelRewards : [],
          battleHistory: Array.isArray(s.battleHistory) ? s.battleHistory : [],
          levelWatermark: mergedWatermark,
          xpScaleMigratedV2: serverXpMigrated || localXpMigrated,
          updatedAt: serverUpdatedAt,
        }));
      } else if (adoptServerXp) {
        localState.xp = typeof s.xp === "number" ? s.xp : localState.xp;
        localState.xpScaleMigratedV2 = true;
        localState.levelWatermark = mergedWatermark;
        localStorage.setItem(STATE_KEY, JSON.stringify(localState));
      } else if (mergedWatermark > (localState.levelWatermark || 0)) {
        localState.levelWatermark = mergedWatermark;
        localStorage.setItem(STATE_KEY, JSON.stringify(localState));
      }
      if (s.dailyBonusAt) localStorage.setItem(DAILY_KEY, String(s.dailyBonusAt));
      if (typeof s.dailyStreak === "number") localStorage.setItem(DAILY_STREAK_KEY, String(s.dailyStreak));
      if (s.freeCaseAt) localStorage.setItem(FREE_CASE_KEY, String(s.freeCaseAt));
    } catch (e) {}
  }

  // ---- Push lokalnych zmian na serwer, gdy zalogowany ----
  let pushTimer = null;
  let nativeSetItem = null; // ustawiane niżej, przy instalacji monkey-patcha; używane do zapisu pomijającego auto-push
  function schedulePush() {
    if (!me.loggedIn) return;
    clearTimeout(pushTimer);
    pushTimer = setTimeout(pushNow, 500);
  }
  function pushNow() {
    let state = {};
    try { state = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    const payload = {
      balance: state.balance,
      inventory: state.inventory,
      invCounter: state.invCounter,
      level: typeof state.level === "number" ? state.level : 0,
      xp: typeof state.xp === "number" ? state.xp : 0,
      bestPull: state.bestPull || null,
      upgradeClicks: typeof state.upgradeClicks === "number" ? state.upgradeClicks : 0,
      casesOpened: typeof state.casesOpened === "number" ? state.casesOpened : 0,
      spentCases: typeof state.spentCases === "number" ? state.spentCases : 0,
      spentUpgrader: typeof state.spentUpgrader === "number" ? state.spentUpgrader : 0,
      battlesPlayed: typeof state.battlesPlayed === "number" ? state.battlesPlayed : 0,
      battlesWon: typeof state.battlesWon === "number" ? state.battlesWon : 0,
      questClaims: state.questClaims && typeof state.questClaims === "object" ? state.questClaims : {},
      claimedLevelRewards: Array.isArray(state.claimedLevelRewards) ? state.claimedLevelRewards : [],
      battleHistory: Array.isArray(state.battleHistory) ? state.battleHistory : [],
      levelWatermark: typeof state.levelWatermark === "number" ? state.levelWatermark : 0,
      dailyBonusAt: Number(localStorage.getItem(DAILY_KEY) || 0) || null,
      dailyStreak: Number(localStorage.getItem(DAILY_STREAK_KEY) || 0) || 0,
      freeCaseAt: Number(localStorage.getItem(FREE_CASE_KEY) || 0) || null,
      baseUpdatedAt: Number(localStorage.getItem(SERVER_BASE_KEY) || 0) || 0,
    };
    fetch("/api/state", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      credentials: "same-origin",
      keepalive: true,
    })
      .then((res) => {
        if (res.status === 409) {
          // Serwer ma nowszy stan niż ta karta widziała (np. admin zmienił
          // saldo) - ten push był oparty na nieaktualnych danych, więc został
          // odrzucony. Zamiast go ponawiać (co by znów nadpisało admina),
          // dociągamy świeży stan serwera i się nim zastępujemy.
          return res.json().then((body) => {
            const s = body && body.state;
            if (!s) return;
            const serverUpdatedAt = typeof s.updatedAt === "number" ? s.updatedAt : 0;
            localStorage.setItem(SERVER_BASE_KEY, String(serverUpdatedAt));
            let priorWatermark = 0;
            let priorAdminOverrideAt = 0;
            try {
              const priorState = JSON.parse(localStorage.getItem(STATE_KEY) || "{}");
              priorWatermark = priorState.levelWatermark || 0;
            } catch (e3) {}
            try { priorAdminOverrideAt = Number(localStorage.getItem(ADMIN_OVERRIDE_KEY) || 0) || 0; } catch (e4) {}
            const serverAdminOverrideAt = typeof s.adminOverrideAt === "number" ? s.adminOverrideAt : 0;
            // Ten 409 mógł wynikać właśnie z tego, że admin autorytatywnie
            // nadpisał stan (patrz merge w fetchMeSync wyżej) - wtedy
            // levelWatermark z serwera musi wygrać wprost, bez Math.max,
            // inaczej ręczne OBNIŻENIE poziomu przez admina zostałoby tu po
            // cichu zignorowane.
            if (serverAdminOverrideAt > priorAdminOverrideAt) {
              localStorage.setItem(ADMIN_OVERRIDE_KEY, String(serverAdminOverrideAt));
            }
            const trustServerWatermarkDirectly = serverAdminOverrideAt > priorAdminOverrideAt;
            const setItem = nativeSetItem || localStorage.setItem.bind(localStorage);
            setItem(STATE_KEY, JSON.stringify({
              balance: s.balance,
              inventory: s.inventory,
              invCounter: s.invCounter,
              level: typeof s.level === "number" ? s.level : 0,
              xp: typeof s.xp === "number" ? s.xp : 0,
              bestPull: s.bestPull || null,
              upgradeClicks: typeof s.upgradeClicks === "number" ? s.upgradeClicks : 0,
              casesOpened: typeof s.casesOpened === "number" ? s.casesOpened : 0,
              spentCases: typeof s.spentCases === "number" ? s.spentCases : 0,
              spentUpgrader: typeof s.spentUpgrader === "number" ? s.spentUpgrader : 0,
              battlesPlayed: typeof s.battlesPlayed === "number" ? s.battlesPlayed : 0,
              battlesWon: typeof s.battlesWon === "number" ? s.battlesWon : 0,
              questClaims: s.questClaims && typeof s.questClaims === "object" ? s.questClaims : {},
              claimedLevelRewards: Array.isArray(s.claimedLevelRewards) ? s.claimedLevelRewards : [],
              battleHistory: Array.isArray(s.battleHistory) ? s.battleHistory : [],
              levelWatermark: trustServerWatermarkDirectly
                ? (typeof s.levelWatermark === "number" ? s.levelWatermark : 0)
                : Math.max(priorWatermark, typeof s.levelWatermark === "number" ? s.levelWatermark : 0),
              xpScaleMigratedV2: !!s.xpScaleMigratedV2,
              updatedAt: serverUpdatedAt,
            }));
            console.error("[cs2sim] push /api/state odrzucony (409) - dane były nieaktualne, zsynchronizowano z serwerem.");
          });
        }
        if (!res.ok) { console.error("[cs2sim] push /api/state nie powiódł się:", res.status, payload); return; }
        return res.json().then((body) => {
          if (body && typeof body.updatedAt === "number") localStorage.setItem(SERVER_BASE_KEY, String(body.updatedAt));
        });
      })
      .catch((e) => {
        console.error("[cs2sim] push /api/state - błąd sieci:", e);
      });
  }

  // ---- EXP / poziomy ----
  // 10 zł wydane na skrzynki albo wygrane z bitwy = 1 EXP. Próg pierwszego
  // poziomu to 10 EXP; każdy kolejny próg rośnie o 10% względem poprzedniego
  // (klasyczna rosnąca krzywa poziomów, nie liniowa). Suma progów to szereg
  // geometryczny: cumulative(L) = 100 * (1.1^L - 1) - patrz xpForLevel niżej.
  const XP_PER_ZL = 1 / 10;
  function levelForXp(totalXp) {
    const xp = typeof totalXp === "number" && totalXp > 0 ? totalXp : 0;
    // log-based estimate first, then nudge to the exact threshold - floating
    // point error in the log/pow round-trip can land just under/over a
    // boundary (e.g. exactly 21 XP mis-floored to level 1 instead of 2).
    const EPS = 1e-9;
    let level = Math.floor(Math.log(xp / 1000 + 1) / Math.log(1.1) + EPS);
    while (xpForLevel(level + 1) - EPS <= xp) level++;
    while (level > 0 && xpForLevel(level) - EPS > xp) level--;
    return level;
  }
  function xpForLevel(level) {
    return 1000 * (Math.pow(1.1, level) - 1);
  }
  // ---- Poziom jako "wskaźnik wodny" (levelWatermark) - nigdy nie spada ----
  // xp jest jedynym prawdziwym źródłem danych, ale poziom WYŚWIETLANY (i
  // używany do odbioru nagród) opiera się na levelWatermark, nie na surowym
  // levelForXp(xp) - bo każda przyszła zmiana wzoru poziomów (albo błąd)
  // mogłaby chwilowo zaniżyć wyliczenie z xp, mimo że gracz nic nie stracił.
  // effectiveLevel() bierze wyższą z dwóch wartości i, jeśli świeże wyliczenie
  // z xp przebiło dotychczasowy wskaźnik, od razu go podbija i zapisuje -
  // czysty Math.max(), więc odporne na wyścigi bez żadnych specjalnych
  // przypadków przy synchronizacji z serwerem (patrz PUT /api/state).
  function readLevelWatermark() {
    try {
      const w = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").levelWatermark;
      return typeof w === "number" ? w : 0;
    } catch (e) {
      return 0;
    }
  }
  function effectiveLevel(totalXp) {
    const computed = levelForXp(totalXp);
    const watermark = readLevelWatermark();
    if (computed > watermark) {
      try {
        const s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}");
        s.levelWatermark = computed;
        localStorage.setItem(STATE_KEY, JSON.stringify(s));
        schedulePush();
      } catch (e) {}
      return computed;
    }
    return watermark;
  }
  function xpProgress(totalXp) {
    const xp = typeof totalXp === "number" && totalXp > 0 ? totalXp : 0;
    const level = effectiveLevel(xp);
    const base = xpForLevel(level);
    const next = xpForLevel(level + 1);
    // Gdy levelWatermark chroni gracza przed chwilowo zaniżonym wyliczeniem,
    // "base" dla tego poziomu może wypaść WYŻEJ niż jego prawdziwe xp -
    // dopóki xp go nie dogoni, pokazujemy po prostu "0% do następnego
    // poziomu" zamiast ujemnego postępu.
    const into = Math.max(0, xp - base);
    const needed = next - base;
    return { level, xp, into, needed, percent: needed > 0 ? Math.min(100, Math.max(0, (into / needed) * 100)) : 100 };
  }

  // ---- Nagrody za poziom: skin najbliższy 100 zł za poziom 1, każdy
  // kolejny poziom o 5% droższy niż poprzedni (100 * 1.05^(L-1)). Dobór
  // konkretnego przedmiotu jest deterministyczny (zawsze ten sam skin z
  // GENERAL_SKIN_DB dla danego poziomu), więc nie trzeba go zapamiętywać -
  // wystarczy pamiętać, KTÓRE poziomy zostały odebrane. To musi być zbiór
  // (nie samo "najwyższy odebrany poziom"), bo poziomy można odbierać
  // w dowolnej kolejności - gracz może np. najpierw kliknąć poziom 5,
  // a dopiero potem wrócić po 1-4; licznik "najwyższy odebrany" błędnie
  // uznałby wtedy 1-4 za odebrane, mimo że nigdy nie trafiły do ekwipunku.
  function levelRewardTargetPrice(level) {
    return 100 * Math.pow(1.05, level - 1);
  }
  function levelRewardItem(level) {
    if (typeof GENERAL_SKIN_DB === "undefined" || !Array.isArray(GENERAL_SKIN_DB) || level < 1) return null;
    const target = levelRewardTargetPrice(level);
    let best = null;
    let bestDiff = Infinity;
    GENERAL_SKIN_DB.forEach((it) => {
      const diff = Math.abs(it.price - target);
      if (diff < bestDiff) { bestDiff = diff; best = it; }
    });
    return best;
  }
  function readClaimedLevelRewards() {
    try {
      const arr = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").claimedLevelRewards;
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }
  // Marks a level's reward as claimed (persists + pushes) and hands back the
  // item so the caller (equipment.html) can drop it into its own inventory.
  // Refuses to double-claim or to claim a level not yet reached; any level
  // up to the player's current one can be claimed independently/out of order.
  function claimLevelReward(level) {
    const currentLevel = effectiveLevel((() => {
      try { return JSON.parse(localStorage.getItem(STATE_KEY) || "{}").xp || 0; } catch (e) { return 0; }
    })());
    if (level < 1 || level > currentLevel) return null;
    const claimed = readClaimedLevelRewards();
    if (claimed.includes(level)) return null;
    const item = levelRewardItem(level);
    if (!item) return null;
    let s = {};
    try { s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    const nextClaimed = Array.isArray(s.claimedLevelRewards) ? s.claimedLevelRewards.slice() : [];
    if (!nextClaimed.includes(level)) nextClaimed.push(level);
    s.claimedLevelRewards = nextClaimed;
    s.updatedAt = Date.now();
    try { localStorage.setItem(STATE_KEY, JSON.stringify(s)); } catch (e) {}
    schedulePush();
    return item;
  }

  // ---- Dzienny bonus (rosnąca passa) ----
  // Dzień 1 = 600 zł, każdy kolejny dzień +600 zł, aż do 3000 zł od dnia 5
  // (i tyle samo w każdym kolejnym dniu passy - próg 5 to pułap, nie reset).
  // Brak odbioru przez 48h zeruje passę (liczoną od DAILY_KEY, jak dotąd).
  const DAILY_BONUS_COOLDOWN_MS = 24 * 60 * 60 * 1000;
  const DAILY_STREAK_RESET_MS = 48 * 60 * 60 * 1000;
  const DAILY_BONUS_DAY_AMOUNT = 600;
  const DAILY_BONUS_MAX_DAYS = 5;
  function dailyBonusRewardForDay(day) {
    return Math.min(Math.max(day, 1), DAILY_BONUS_MAX_DAYS) * DAILY_BONUS_DAY_AMOUNT;
  }
  function dailyBonusStatus() {
    const lastClaim = Number(localStorage.getItem(DAILY_KEY) || 0);
    const prevStreak = Number(localStorage.getItem(DAILY_STREAK_KEY) || 0);
    const now = Date.now();
    const msSinceClaim = lastClaim ? now - lastClaim : Infinity;
    const effectiveStreak = msSinceClaim > DAILY_STREAK_RESET_MS ? 0 : prevStreak;
    const pendingDay = Math.min(effectiveStreak + 1, DAILY_BONUS_MAX_DAYS);
    return {
      effectiveStreak,
      pendingDay,
      reward: dailyBonusRewardForDay(pendingDay),
      canClaim: msSinceClaim >= DAILY_BONUS_COOLDOWN_MS,
      msLeft: Math.max(0, DAILY_BONUS_COOLDOWN_MS - msSinceClaim),
    };
  }
  function claimDailyBonus() {
    const status = dailyBonusStatus();
    if (!status.canClaim) return null;
    const newStreak = status.effectiveStreak + 1;
    let s = {};
    try { s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    s.balance = (typeof s.balance === "number" ? s.balance : 0) + status.reward;
    s.updatedAt = Date.now();
    try { localStorage.setItem(STATE_KEY, JSON.stringify(s)); } catch (e) {}
    try {
      localStorage.setItem(DAILY_KEY, String(Date.now()));
      localStorage.setItem(DAILY_STREAK_KEY, String(newStreak));
    } catch (e) {}
    schedulePush();
    return { reward: status.reward, newStreak, day: status.pendingDay };
  }
  function fmtDailyBonus(n) {
    return n.toLocaleString("pl-PL", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " zł";
  }
  let dailyBonusOnClaimedCb = null;
  function buildDailyBonusModal() {
    injectStyle();
    let overlay = document.getElementById("dailyBonusOverlay");
    if (overlay) return overlay;
    overlay = document.createElement("div");
    overlay.id = "dailyBonusOverlay";
    overlay.innerHTML = `
      <div class="db-panel">
        <button class="db-close" id="dbClose" aria-label="Zamknij">✕</button>
        <div class="db-header"><span class="db-gift">🎁</span><h2>Dzienny bonus</h2></div>
        <div class="db-days" id="dbDays"></div>
        <div class="db-streak">🔥&nbsp;Passa: <b id="dbStreakNum">0</b>&nbsp;dni z rzędu</div>
        <div class="db-reward-label">Do odebrania:</div>
        <div class="db-reward-amount" id="dbRewardAmount">+0,00 zł</div>
        <button class="db-claim-btn" id="dbClaimBtn">🎁 Odbierz bonus</button>
        <div class="db-note">Loguj się codziennie, żeby utrzymać passę i zbierać wyższe bonusy. Brak odbioru przez 48h resetuje passę.</div>
      </div>
    `;
    document.body.appendChild(overlay);
    const hide = () => overlay.classList.remove("show");
    overlay.addEventListener("click", (e) => { if (e.target === overlay) hide(); });
    document.getElementById("dbClose").onclick = hide;
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") hide(); });
    return overlay;
  }
  function renderDailyBonusModal() {
    const status = dailyBonusStatus();
    const daysEl = document.getElementById("dbDays");
    daysEl.innerHTML = "";
    for (let d = 1; d <= 4; d++) {
      const done = d <= status.effectiveStreak;
      const active = d === status.pendingDay;
      const tile = document.createElement("div");
      tile.className = "db-day-tile" + (done ? " done" : "") + (active ? " active" : "");
      tile.innerHTML = `
        <div class="db-day-label">DZIEŃ ${d}</div>
        <div class="db-day-icon">${done ? "✓" : fmtDailyBonus(dailyBonusRewardForDay(d))}</div>
      `;
      daysEl.appendChild(tile);
    }
    const day5 = document.createElement("div");
    day5.className = "db-day-tile db-day-5" + (status.pendingDay >= 5 ? " active" : "");
    day5.innerHTML = `
      <div class="db-day-label">DZIEŃ 5+ <span class="db-star">★</span></div>
      <div class="db-day-icon">${fmtDailyBonus(dailyBonusRewardForDay(5))}</div>
      <div class="db-day-tag">BONUS!</div>
    `;
    daysEl.appendChild(day5);

    document.getElementById("dbStreakNum").textContent = status.effectiveStreak;
    document.getElementById("dbRewardAmount").textContent = "+" + fmtDailyBonus(status.reward);

    const btn = document.getElementById("dbClaimBtn");
    btn.disabled = false;
    btn.textContent = "🎁 ODBIERZ BONUS";
    btn.onclick = () => {
      const result = claimDailyBonus();
      if (!result) return;
      btn.disabled = true;
      btn.textContent = "✓ Odebrano!";
      if (typeof dailyBonusOnClaimedCb === "function") dailyBonusOnClaimedCb(result.reward);
      setTimeout(() => {
        const ov = document.getElementById("dailyBonusOverlay");
        if (ov) ov.classList.remove("show");
      }, 1100);
    };
  }
  function openDailyBonusModal(onClaimed) {
    dailyBonusOnClaimedCb = onClaimed || null;
    const overlay = buildDailyBonusModal();
    renderDailyBonusModal();
    overlay.classList.add("show");
  }

  let lvlTooltipDismissWired = false;
  function refreshLevelBadge() {
    const wrap = document.querySelector(".avatar-wrap");
    const badge = wrap && wrap.querySelector(".lvl-badge");
    if (!badge || !me.loggedIn) return;
    let xp = 0;
    try { xp = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").xp || 0; } catch (e) {}
    const p = xpProgress(xp);
    const text = `${p.into.toFixed(2)} / ${p.needed.toFixed(2)} EXP (${p.percent.toFixed(1)}%) do poziomu ${p.level + 1}`;
    badge.textContent = String(p.level);
    badge.title = text; // hover on desktop
    let tip = wrap.querySelector(".lvl-tooltip");
    if (!tip) {
      tip = document.createElement("div");
      tip.className = "lvl-tooltip";
      wrap.appendChild(tip);
    }
    tip.textContent = text;
    // tapping the badge on mobile doesn't trigger a hover title, and it sits
    // inside .avatar-wrap (whose own click navigates to the profile) - stop
    // that from firing and show the same info as a small popover instead.
    badge.onclick = (e) => {
      e.stopPropagation();
      tip.classList.toggle("show");
    };
    if (!lvlTooltipDismissWired) {
      lvlTooltipDismissWired = true;
      document.addEventListener("click", () => {
        document.querySelectorAll(".lvl-tooltip.show").forEach((el) => el.classList.remove("show"));
      });
    }
  }

  // ---- Zapisz najlepszy drop + liczniki do topki (profil / leaderboard) ----
  // Każda strona ma swój własny saveState(), który serializuje tylko znane
  // sobie pola (balance/inventory/level/xp...) - żeby te dodatkowe pola nie
  // ginęły przy pierwszym takim zapisie po recordPull()/recordCasesOpened()/
  // recordUpgradeClick(), każdy saveState() powinien dograć świeże wartości
  // przez readPersistentExtras() tuż przed zapisem.
  function readBestPull() {
    try { return JSON.parse(localStorage.getItem(STATE_KEY) || "{}").bestPull || null; } catch (e) { return null; }
  }
  function readPersistentExtras() {
    try {
      const s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}");
      return {
        bestPull: s.bestPull || null,
        upgradeClicks: typeof s.upgradeClicks === "number" ? s.upgradeClicks : 0,
        casesOpened: typeof s.casesOpened === "number" ? s.casesOpened : 0,
        spentCases: typeof s.spentCases === "number" ? s.spentCases : 0,
        spentUpgrader: typeof s.spentUpgrader === "number" ? s.spentUpgrader : 0,
        battlesPlayed: typeof s.battlesPlayed === "number" ? s.battlesPlayed : 0,
        battlesWon: typeof s.battlesWon === "number" ? s.battlesWon : 0,
        // Zbiór claimedTiers per zadanie ("Zadania"/zadania.html) - patrz
        // claimQuestTier() niżej. Musi przetrwać KAŻDY zapis stanu tak samo
        // jak claimedLevelRewards, inaczej odebrana nagroda za zadanie
        // "odblokowałaby się" z powrotem przy pierwszym saveState() na innej
        // podstronie.
        questClaims: s.questClaims && typeof s.questClaims === "object" ? s.questClaims : {},
        claimedLevelRewards: Array.isArray(s.claimedLevelRewards) ? s.claimedLevelRewards : [],
        battleHistory: Array.isArray(s.battleHistory) ? s.battleHistory : [],
        // Musi przetrwać KAŻDY zapis stanu z dowolnej podstrony (equipment,
        // index, battle...), inaczej wskaźnik poziomu cofałby się do 0 przy
        // pierwszym zwykłym zapisie po tym, jak effectiveLevel() go podbije.
        levelWatermark: typeof s.levelWatermark === "number" ? s.levelWatermark : 0,
        // Musi też przetrwać KAŻDY zwykły zapis - inaczej wracałoby do false
        // przy pierwszym saveState() po synchronizacji migracji xp*10, i ta
        // migracja próbowałaby (nieszkodliwie, ale niepotrzebnie) "adoptować"
        // xp z serwera ponownie przy każdym kolejnym /api/me tej przeglądarki.
        xpScaleMigratedV2: !!s.xpScaleMigratedV2,
        updatedAt: Date.now(),
      };
    } catch (e) {
      return { bestPull: null, upgradeClicks: 0, casesOpened: 0, spentCases: 0, spentUpgrader: 0, battlesPlayed: 0, battlesWon: 0, questClaims: {}, claimedLevelRewards: [], battleHistory: [], levelWatermark: 0, xpScaleMigratedV2: false, updatedAt: Date.now() };
    }
  }
  // ---- Historia bitew Case Battle (do zakładki "Moje bitwy") ----
  // Lekkie podsumowanie każdej rozegranej bitwy - pełne dane rund/wyników
  // żyją tylko na serwerze (i wygasają), więc trzymamy tu tylko tyle, żeby
  // dało się pokazać listę i spróbować dociągnąć pełny replay po lobbyId.
  const BATTLE_HISTORY_MAX = 30;
  function warsawDateString(epochMs) {
    return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Warsaw" }).format(new Date(epochMs));
  }
  function readBattleHistory() {
    try {
      const arr = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").battleHistory;
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }
  function recordBattleHistory(entry) {
    if (!entry || !entry.lobbyId) return;
    let s = {};
    try { s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    const list = Array.isArray(s.battleHistory) ? s.battleHistory.slice() : [];
    const filtered = list.filter((e) => e.lobbyId !== entry.lobbyId);
    filtered.unshift({ ...entry, at: entry.at || Date.now() });
    // Wpisy z dzisiaj nigdy nie są obcinane limitem - "niefart dnia" liczy
    // dzisiejsze przegrane właśnie z tej tablicy, więc obcięcie starym
    // limitem potrafiło wyrzucić dzisiejszą przegraną przy każdej kolejnej
    // zagranej bitwie (także wygranej), fałszując licznik w topce.
    const todayStr = warsawDateString(Date.now());
    const todays = filtered.filter((e) => warsawDateString(e.at) === todayStr);
    const older = filtered.filter((e) => warsawDateString(e.at) !== todayStr);
    s.battleHistory = todays.concat(older.slice(0, Math.max(0, BATTLE_HISTORY_MAX - todays.length)));
    s.updatedAt = Date.now();
    try { localStorage.setItem(STATE_KEY, JSON.stringify(s)); } catch (e) {}
    schedulePush();
  }
  function recordPull(item) {
    if (!item || typeof item.price !== "number") return;
    const current = readBestPull();
    if (!current || item.price > current.price) {
      let state = {};
      try { state = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
      state.bestPull = { weapon: item.weapon, skin: item.skin, wear: item.wear, price: item.price, at: Date.now() };
      state.updatedAt = Date.now();
      try { localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) {}
    }
    schedulePush();
  }
  function incrementCounter(key, by) {
    let state = {};
    try { state = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    state[key] = (typeof state[key] === "number" ? state[key] : 0) + by;
    state.updatedAt = Date.now();
    try { localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) {}
    schedulePush();
  }
  function recordUpgradeClick() { incrementCounter("upgradeClicks", 1); }
  function recordCasesOpened(n) { incrementCounter("casesOpened", typeof n === "number" ? n : 1); }
  function recordCaseSpend(amount) { if(typeof amount === "number" && amount > 0) incrementCounter("spentCases", amount); }
  function recordUpgradeSpend(amount) { if(typeof amount === "number" && amount > 0) incrementCounter("spentUpgrader", amount); }
  function recordBattleResult(won) {
    let state = {};
    try { state = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    state.battlesPlayed = (typeof state.battlesPlayed === "number" ? state.battlesPlayed : 0) + 1;
    if (won) state.battlesWon = (typeof state.battlesWon === "number" ? state.battlesWon : 0) + 1;
    state.updatedAt = Date.now();
    try { localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) {}
    schedulePush();
  }

  // ---- "Zadania" (zadania.html): odbiór nagród za poziomy zadań ----
  // Nagrody to zawsze zł wprost na saldo (nie przedmiot, jak przy poziomach
  // gracza), więc mechanizm jest bliższy claimDailyBonus() niż
  // claimLevelReward(). questClaims[taskId] to liczba JUŻ odebranych poziomów
  // danego zadania (0 = żaden) - poziomy trzeba odbierać po kolei, więc sam
  // ten licznik jednoznacznie wyznacza, który poziom jest "następny".
  function readQuestClaims() {
    try {
      const c = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").questClaims;
      return c && typeof c === "object" ? c : {};
    } catch (e) {
      return {};
    }
  }
  function claimQuestTier(taskId, tierIndex, reward) {
    if (!taskId || typeof tierIndex !== "number" || typeof reward !== "number") return false;
    let s = {};
    try { s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
    const claims = s.questClaims && typeof s.questClaims === "object" ? { ...s.questClaims } : {};
    const already = typeof claims[taskId] === "number" ? claims[taskId] : 0;
    if (tierIndex !== already) return false; // tylko następny nieodebrany poziom, po kolei
    claims[taskId] = already + 1;
    s.questClaims = claims;
    s.balance = (typeof s.balance === "number" ? s.balance : 0) + reward;
    s.updatedAt = Date.now();
    try { localStorage.setItem(STATE_KEY, JSON.stringify(s)); } catch (e) {}
    schedulePush();
    return true;
  }

  // Monkey-patch jako dodatkowa siatka bezpieczeństwa - część przeglądarek
  // (zwłaszcza mobilny WebKit, czyli też "Chrome" na iPhonie) potrafi po
  // cichu ignorować nadpisanie localStorage.setItem, więc NIE polegamy już
  // wyłącznie na nim: saveState() na każdej stronie i funkcje powyżej wołają
  // schedulePush() jawnie. To tu zostaje tylko na wszelki wypadek.
  try {
    nativeSetItem = localStorage.setItem.bind(localStorage);
    localStorage.setItem = function (key, value) {
      nativeSetItem(key, value);
      if (key === STATE_KEY || key === DAILY_KEY || key === FREE_CASE_KEY) schedulePush();
    };
  } catch (e) {
    console.error("[cs2sim] nadpisanie localStorage.setItem nie powiodło się:", e.message);
  }
  window.addEventListener("beforeunload", () => { if (pushTimer) pushNow(); });

  // ---- UI: avatar + przycisk logowania w menu ustawień ----
  const DICT = {
    pl: {
      login: "Zaloguj przez Steam", logout: "Wyloguj", myProfile: "Mój profil",
      wallTitle: "Wymagane logowanie",
      wallDesc: "Zaloguj się przez Steam, aby korzystać z tej funkcji.",
      wallSteamBtn: "Zaloguj się przez Steam",
      wallNote: "Nie są wymagane żadne dane płatności. Symulator używa wirtualnej waluty.",
    },
    en: {
      login: "Log in with Steam", logout: "Log out", myProfile: "My profile",
      wallTitle: "Login required",
      wallDesc: "Log in with Steam to use this feature.",
      wallSteamBtn: "Log in with Steam",
      wallNote: "No payment details required. The simulator uses virtual currency.",
    },
  };
  function getLang() { return localStorage.getItem(LANG_KEY) === "en" ? "en" : "pl"; }

  function injectStyle() {
    if (document.getElementById("steamAuthStyle")) return;
    const style = document.createElement("style");
    style.id = "steamAuthStyle";
    style.textContent = `
      .avatar img{width:100%; height:100%; border-radius:50%; object-fit:cover;}
      .avatar-wrap{cursor:pointer;}
      .steam-icon-badge{
        display:inline-flex; align-items:center; justify-content:center; flex:none;
        width:20px; height:20px; border-radius:50%;
        background:linear-gradient(135deg,#1b2838,#2a475e);
      }
      .steam-icon-badge svg{width:13px; height:13px; fill:#66c0f4;}
      .lvl-badge.steam-badge{
        background:linear-gradient(135deg,#1b2838,#2a475e) !important;
        display:flex; align-items:center; justify-content:center;
        width:16px; height:16px; padding:0; border-radius:50%;
      }
      .lvl-badge.steam-badge svg{width:11px; height:11px; fill:#66c0f4;}
      .lvl-badge{cursor:pointer;}
      .lvl-tooltip{
        display:none; position:absolute; top:calc(100% + 8px); right:-8px; z-index:70;
        background:var(--panel,#141821); border:1px solid var(--line,#262c3a); border-radius:8px;
        padding:8px 12px; font-size:12px; color:var(--text,#e9ecf3); white-space:nowrap;
        box-shadow:0 12px 30px -10px rgba(0,0,0,.6); font-family:'Inter',sans-serif;
      }
      .lvl-tooltip.show{display:block;}
      .steam-auth-row{display:flex; align-items:center; gap:8px; border-top:1px solid var(--line);}
      .steam-auth-row button{flex:1;}
      #steamLoginBtn{display:flex; align-items:center; gap:8px;}
      #authWallOverlay{
        position:fixed; inset:0; z-index:9998; display:none;
        align-items:center; justify-content:center; padding:20px;
        background:rgba(6,8,13,.72); backdrop-filter:blur(6px);
        opacity:0; transition:opacity .18s ease;
      }
      #authWallOverlay.show{display:flex; opacity:1;}
      .auth-wall-panel{
        position:relative; width:100%; max-width:360px;
        background:var(--panel,#141821); border:1px solid var(--line,#262c3a);
        border-radius:16px; padding:32px 26px 26px; text-align:center;
        box-shadow:0 24px 60px -20px rgba(0,0,0,.7);
        transform:translateY(10px) scale(.98); transition:transform .18s ease;
      }
      #authWallOverlay.show .auth-wall-panel{transform:translateY(0) scale(1);}
      .auth-wall-close{
        position:absolute; top:12px; right:12px; width:28px; height:28px; border-radius:8px;
        background:var(--panel-2,#1b202b); border:1px solid var(--line,#262c3a); color:var(--muted,#7d879b);
        cursor:pointer; font-size:14px; line-height:1;
      }
      .auth-wall-close:hover{color:var(--text,#e9ecf3); border-color:var(--hazard,#ff9500);}
      .auth-wall-icon{
        width:64px; height:64px; margin:0 auto 18px;
        display:flex; align-items:center; justify-content:center; font-size:30px;
      }
      .auth-wall-icon img{
        width:100%; height:100%; object-fit:contain;
        animation:auth-wall-logo-glow 2.4s ease-in-out infinite;
      }
      @keyframes auth-wall-logo-glow{
        0%,100%{filter:drop-shadow(0 0 4px #ffd700) drop-shadow(0 0 9px rgba(255,215,0,.55));}
        50%{filter:drop-shadow(0 0 7px #ffd700) drop-shadow(0 0 16px rgba(255,215,0,.85));}
      }
      .auth-wall-panel h2{
        font-family:'Oswald',sans-serif; font-weight:700; text-transform:uppercase; letter-spacing:1px;
        font-size:20px; margin:0 0 8px; color:var(--text,#e9ecf3);
      }
      .auth-wall-panel p{
        font-family:'Inter',sans-serif; font-size:13.5px; color:var(--muted,#7d879b);
        margin:0 0 22px; line-height:1.5;
      }
      .auth-wall-steam-btn{
        display:flex; align-items:center; justify-content:center; gap:9px; width:100%;
        font-family:'Oswald',sans-serif; text-transform:uppercase; letter-spacing:1px;
        font-size:14.5px; font-weight:600; background:var(--hazard,#ff9500); color:#181000;
        border:none; padding:13px 18px; border-radius:9px; cursor:pointer;
        transition:transform .1s ease, box-shadow .1s ease;
      }
      .auth-wall-steam-btn:hover{transform:translateY(-1px); box-shadow:0 8px 20px -8px var(--hazard,#ff9500);}
      .auth-wall-note{
        font-family:'Inter',sans-serif; font-size:11px; color:var(--muted,#7d879b);
        margin-top:16px; line-height:1.5;
      }
      #dailyBonusOverlay{
        position:fixed; inset:0; z-index:9998; display:none;
        align-items:center; justify-content:center; padding:20px;
        background:rgba(6,8,13,.72); backdrop-filter:blur(6px);
        opacity:0; transition:opacity .18s ease;
      }
      #dailyBonusOverlay.show{display:flex; opacity:1;}
      .db-panel{
        position:relative; width:100%; max-width:460px;
        background:var(--panel,#141821); border:1px solid var(--line,#262c3a);
        border-radius:16px; padding:26px; text-align:center;
        box-shadow:0 24px 60px -20px rgba(0,0,0,.7);
        transform:translateY(10px) scale(.98); transition:transform .18s ease;
        font-family:'Inter',sans-serif;
      }
      #dailyBonusOverlay.show .db-panel{transform:translateY(0) scale(1);}
      .db-close{
        position:absolute; top:12px; right:12px; width:28px; height:28px; border-radius:8px;
        background:var(--panel-2,#1b202b); border:1px solid var(--line,#262c3a); color:var(--muted,#7d879b);
        cursor:pointer; font-size:14px; line-height:1;
      }
      .db-close:hover{color:var(--text,#e9ecf3); border-color:var(--hazard,#ff9500);}
      .db-header{display:flex; align-items:center; gap:10px; margin-bottom:20px; text-align:left;}
      .db-gift{font-size:22px;}
      .db-header h2{
        font-family:'Oswald',sans-serif; font-weight:700; text-transform:uppercase; letter-spacing:1px;
        font-size:19px; margin:0; color:var(--text,#e9ecf3);
      }
      .db-days{display:grid; grid-template-columns:repeat(5, 1fr); gap:8px; margin-bottom:16px;}
      .db-day-tile{
        background:var(--panel-2,#1b202b); border:1px solid var(--line,#262c3a); border-radius:10px;
        padding:10px 4px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px;
        min-height:66px;
      }
      .db-day-label{font-family:'JetBrains Mono',monospace; font-size:8.5px; font-weight:700; color:var(--muted,#7d879b); letter-spacing:.2px; white-space:nowrap;}
      .db-day-icon{font-family:'JetBrains Mono',monospace; font-size:10px; font-weight:700; color:var(--muted,#7d879b);}
      .db-day-tile.done{border-color:var(--good,#3ddc84);}
      .db-day-tile.done .db-day-label{color:var(--good,#3ddc84);}
      .db-day-tile.done .db-day-icon{color:var(--good,#3ddc84); font-size:15px;}
      .db-day-tile.active{border-color:var(--hazard,#ff9500); box-shadow:0 0 16px -4px var(--hazard,#ff9500);}
      .db-day-tile.active .db-day-label{color:var(--hazard,#ff9500);}
      .db-day-tile.active .db-day-icon{color:var(--hazard,#ff9500);}
      .db-day-5 .db-star{color:var(--r-gold,#ffd700); font-size:9px;}
      .db-day-5 .db-day-tag{font-family:'JetBrains Mono',monospace; font-size:7.5px; font-weight:700; color:var(--r-gold,#ffd700); margin-top:1px;}
      .db-day-5.active{border-color:var(--r-gold,#ffd700); box-shadow:0 0 18px -4px var(--r-gold,#ffd700);}
      .db-day-5.active .db-day-label, .db-day-5.active .db-day-icon{color:var(--r-gold,#ffd700);}
      .db-streak{
        background:var(--panel-2,#1b202b); border:1px solid var(--line,#262c3a); border-radius:10px;
        padding:12px; font-size:13.5px; color:var(--text,#e9ecf3); margin-bottom:20px;
      }
      .db-reward-label{font-size:13px; color:var(--muted,#7d879b); margin-bottom:4px;}
      .db-reward-amount{
        font-family:'JetBrains Mono',monospace; font-weight:800; font-size:30px; color:var(--good,#3ddc84);
        margin-bottom:20px;
      }
      .db-claim-btn{
        display:flex; align-items:center; justify-content:center; gap:9px; width:100%;
        font-family:'Oswald',sans-serif; text-transform:uppercase; letter-spacing:1px;
        font-size:14.5px; font-weight:700; background:linear-gradient(135deg,var(--hazard,#ff9500),#ff6a00); color:#181000;
        border:none; padding:14px 18px; border-radius:10px; cursor:pointer;
        transition:transform .1s ease, box-shadow .1s ease;
      }
      .db-claim-btn:hover{transform:translateY(-1px); box-shadow:0 8px 20px -8px var(--hazard,#ff9500);}
      .db-claim-btn:disabled{opacity:.6; cursor:default; transform:none; box-shadow:none;}
      .db-note{font-size:11px; color:var(--muted,#7d879b); margin-top:16px; line-height:1.5;}
    `;
    document.head.appendChild(style);
  }

  // ---- Blokada funkcji (otwieranie skrzynek, battle, upgrader) dla niezalogowanych ----
  function buildAuthWall() {
    injectStyle();
    let overlay = document.getElementById("authWallOverlay");
    if (overlay) return overlay;
    const t = DICT[getLang()];
    overlay = document.createElement("div");
    overlay.id = "authWallOverlay";
    overlay.innerHTML = `
      <div class="auth-wall-panel">
        <button class="auth-wall-close" id="authWallClose" aria-label="Zamknij">✕</button>
        <div class="auth-wall-icon"><img src="/casegold_logo.PNG" alt="Casegold" onerror="this.outerHTML='📦';"></div>
        <h2>${t.wallTitle}</h2>
        <p>${t.wallDesc}</p>
        <button class="auth-wall-steam-btn" id="authWallSteamBtn">${steamIconBadge()} ${t.wallSteamBtn}</button>
        <div class="auth-wall-note">${t.wallNote}</div>
      </div>
    `;
    document.body.appendChild(overlay);
    const hide = () => overlay.classList.remove("show");
    overlay.addEventListener("click", (e) => { if (e.target === overlay) hide(); });
    document.getElementById("authWallClose").onclick = hide;
    document.getElementById("authWallSteamBtn").onclick = () => { window.location.href = "/auth/steam"; };
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") hide(); });
    return overlay;
  }

  function showAuthWall() {
    buildAuthWall().classList.add("show");
  }

  function requireLogin() {
    if (me.loggedIn) return true;
    showAuthWall();
    return false;
  }

  function renderBalanceEarly() {
    const el = document.getElementById("balance");
    if (!el) return;
    let bal = 0;
    if (me.loggedIn) {
      bal = 50; // matches every page's hardcoded "50,00 zł" placeholder / START_BALANCE
      try {
        const s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}");
        if (typeof s.balance === "number") bal = s.balance;
      } catch (e) {}
    }
    el.textContent = bal.toLocaleString("pl-PL", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " zł";
  }

  function buildUI() {
    injectStyle();
    renderBalanceEarly();
    const t = DICT[getLang()];
    const avatarWrap = document.querySelector(".avatar-wrap");
    const avatar = avatarWrap && avatarWrap.querySelector(".avatar");
    const menu = document.getElementById("settingsMenu");

    if (me.loggedIn && me.user) {
      const profileUrl = me.user.slug ? `/profile/${encodeURIComponent(me.user.slug)}` : "/profile";
      if (avatar) {
        avatar.innerHTML = "";
        const img = document.createElement("img");
        img.src = me.user.avatar || "";
        img.alt = me.user.displayName || "Steam";
        avatar.appendChild(img);
        avatar.title = me.user.displayName || "";
      }
      if (avatarWrap) {
        avatarWrap.onclick = () => { window.location.href = profileUrl; };
        avatarWrap.title = t.myProfile;
        const badge = avatarWrap.querySelector(".lvl-badge");
        if (badge) {
          badge.classList.remove("steam-badge");
          refreshLevelBadge();
        }
      }

      if (menu && !document.getElementById("myProfileBtn")) {
        const btn = document.createElement("button");
        btn.id = "myProfileBtn";
        btn.textContent = `👤 ${t.myProfile}`;
        btn.onclick = () => { window.location.href = profileUrl; };
        menu.appendChild(btn);
      }

      if (menu && me.isAdmin && !document.getElementById("adminPanelBtn")) {
        const btn = document.createElement("button");
        btn.id = "adminPanelBtn";
        btn.textContent = "🛠 Panel admina";
        btn.onclick = () => { window.location.href = "/admin"; };
        menu.appendChild(btn);
      }

      if (menu && !document.getElementById("steamAuthRow")) {
        const row = document.createElement("div");
        row.className = "steam-auth-row";
        row.id = "steamAuthRow";
        row.innerHTML = `<button id="steamLogoutBtn">${t.logout} (${me.user.displayName || ""})</button>`;
        menu.appendChild(row);
        document.getElementById("steamLogoutBtn").onclick = () => {
          fetch("/auth/logout", { method: "POST", credentials: "same-origin" })
            .finally(() => { window.location.reload(); });
        };
      }
    } else {
      if (avatar) {
        avatar.innerHTML = "";
        avatar.title = t.login;
      }
      if (avatarWrap) {
        avatarWrap.title = t.login;
        avatarWrap.onclick = () => { window.location.href = "/auth/steam"; };
        const badge = avatarWrap.querySelector(".lvl-badge");
        if (badge) { badge.classList.add("steam-badge"); badge.innerHTML = STEAM_ICON_SVG; badge.title = t.login; }
      }
      if (menu && !document.getElementById("steamAuthRow")) {
        const row = document.createElement("div");
        row.className = "steam-auth-row";
        row.id = "steamAuthRow";
        row.innerHTML = `<button id="steamLoginBtn">${steamIconBadge()} ${t.login}</button>`;
        menu.appendChild(row);
        document.getElementById("steamLoginBtn").onclick = () => {
          window.location.href = "/auth/steam";
        };
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", buildUI);
  } else {
    buildUI();
  }

  window.SteamAuth = {
    getUser: () => (me.loggedIn ? me.user : null),
    refreshUI: buildUI,
    requireLogin,
    recordPull,
    readBestPull,
    readPersistentExtras,
    recordUpgradeClick,
    recordCasesOpened,
    recordCaseSpend,
    recordUpgradeSpend,
    recordBattleResult,
    readQuestClaims,
    claimQuestTier,
    schedulePush,
    xpPerZl: XP_PER_ZL,
    levelForXp,
    xpForLevel,
    xpProgress,
    effectiveLevel,
    readLevelWatermark,
    refreshLevelBadge,
    levelRewardTargetPrice,
    levelRewardItem,
    readClaimedLevelRewards,
    claimLevelReward,
    openDailyBonusModal,
    recordBattleHistory,
    readBattleHistory,
  };
})();
