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
  const FREE_CASE_KEY = "cs2sim_free_case_at";
  const LANG_KEY = "cs2sim_lang";

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
      localStorage.setItem(STATE_KEY, JSON.stringify({
        balance: s.balance,
        inventory: s.inventory,
        invCounter: s.invCounter,
        level: typeof s.level === "number" ? s.level : 0,
      }));
      if (s.dailyBonusAt) localStorage.setItem(DAILY_KEY, String(s.dailyBonusAt));
      if (s.freeCaseAt) localStorage.setItem(FREE_CASE_KEY, String(s.freeCaseAt));
    } catch (e) {}
  }

  // ---- Push lokalnych zmian na serwer, gdy zalogowany ----
  let pushTimer = null;
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
      dailyBonusAt: Number(localStorage.getItem(DAILY_KEY) || 0) || null,
      freeCaseAt: Number(localStorage.getItem(FREE_CASE_KEY) || 0) || null,
    };
    fetch("/api/state", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      credentials: "same-origin",
      keepalive: true,
    }).catch(() => {});
  }

  const nativeSetItem = localStorage.setItem.bind(localStorage);
  localStorage.setItem = function (key, value) {
    nativeSetItem(key, value);
    if (key === STATE_KEY || key === DAILY_KEY || key === FREE_CASE_KEY) schedulePush();
  };
  window.addEventListener("beforeunload", () => { if (pushTimer) pushNow(); });

  // ---- UI: avatar + przycisk logowania w menu ustawień ----
  const DICT = {
    pl: { login: "Zaloguj przez Steam", logout: "Wyloguj" },
    en: { login: "Log in with Steam", logout: "Log out" },
  };
  function getLang() { return localStorage.getItem(LANG_KEY) === "en" ? "en" : "pl"; }

  function injectStyle() {
    if (document.getElementById("steamAuthStyle")) return;
    const style = document.createElement("style");
    style.id = "steamAuthStyle";
    style.textContent = `
      .avatar img{width:100%; height:100%; border-radius:50%; object-fit:cover;}
      .avatar-wrap{cursor:pointer;}
      .steam-auth-row{display:flex; align-items:center; gap:8px; border-top:1px solid var(--line);}
      .steam-auth-row button{flex:1;}
      #steamLoginBtn{display:flex; align-items:center; gap:8px;}
    `;
    document.head.appendChild(style);
  }

  function buildUI() {
    injectStyle();
    const t = DICT[getLang()];
    const avatarWrap = document.querySelector(".avatar-wrap");
    const avatar = avatarWrap && avatarWrap.querySelector(".avatar");
    const menu = document.getElementById("settingsMenu");

    if (me.loggedIn && me.user) {
      if (avatar) {
        avatar.innerHTML = "";
        const img = document.createElement("img");
        img.src = me.user.avatar || "";
        img.alt = me.user.displayName || "Steam";
        avatar.appendChild(img);
        avatar.title = me.user.displayName || "";
      }
      if (avatarWrap) {
        avatarWrap.onclick = null;
        avatarWrap.title = "";
        const badge = avatarWrap.querySelector(".lvl-badge");
        if (badge) {
          let lvl = 0;
          try { lvl = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").level || 0; } catch (e) {}
          badge.textContent = String(lvl);
          badge.title = "";
        }
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
        if (badge) { badge.textContent = "🔑"; badge.title = t.login; }
      }
      if (menu && !document.getElementById("steamAuthRow")) {
        const row = document.createElement("div");
        row.className = "steam-auth-row";
        row.id = "steamAuthRow";
        row.innerHTML = `<button id="steamLoginBtn">🔑 ${t.login}</button>`;
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

  window.SteamAuth = { getUser: () => (me.loggedIn ? me.user : null), refreshUI: buildUI };
})();
