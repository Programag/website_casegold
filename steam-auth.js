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
        xp: typeof s.xp === "number" ? s.xp : 0,
        bestPull: s.bestPull || null,
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
      xp: typeof state.xp === "number" ? state.xp : 0,
      bestPull: state.bestPull || null,
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

  // ---- Zapisz najlepszy drop (do profilu) ----
  // Każda strona ma swój własny saveState(), który serializuje tylko znane
  // sobie pola (balance/inventory/level/xp...) - żeby najlepszy drop nie
  // ginął przy pierwszym takim zapisie po recordPull(), każdy saveState()
  // powinien dograć świeżą wartość przez readBestPull() tuż przed zapisem.
  function readBestPull() {
    try { return JSON.parse(localStorage.getItem(STATE_KEY) || "{}").bestPull || null; } catch (e) { return null; }
  }
  function recordPull(item) {
    if (!item || typeof item.price !== "number") return;
    const current = readBestPull();
    if (!current || item.price > current.price) {
      let state = {};
      try { state = JSON.parse(localStorage.getItem(STATE_KEY) || "{}"); } catch (e) {}
      state.bestPull = { weapon: item.weapon, skin: item.skin, wear: item.wear, price: item.price, at: Date.now() };
      try { localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) {}
    }
  }

  const nativeSetItem = localStorage.setItem.bind(localStorage);
  localStorage.setItem = function (key, value) {
    nativeSetItem(key, value);
    if (key === STATE_KEY || key === DAILY_KEY || key === FREE_CASE_KEY) schedulePush();
  };
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
        width:64px; height:64px; margin:0 auto 18px; border-radius:16px;
        background:var(--hazard-dim,#3a2712); border:1px solid var(--hazard,#ff9500);
        display:flex; align-items:center; justify-content:center; font-size:30px;
        box-shadow:0 0 24px -6px var(--hazard,#ff9500);
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
        <div class="auth-wall-icon">📦</div>
        <h2>${t.wallTitle}</h2>
        <p>${t.wallDesc}</p>
        <button class="auth-wall-steam-btn" id="authWallSteamBtn">🔑 ${t.wallSteamBtn}</button>
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
    let bal = 50; // matches every page's hardcoded "50,00 zł" placeholder / START_BALANCE
    try {
      const s = JSON.parse(localStorage.getItem(STATE_KEY) || "{}");
      if (typeof s.balance === "number") bal = s.balance;
    } catch (e) {}
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
      const profileUrl = me.user.slug ? `/profile/${encodeURIComponent(me.user.slug)}` : "/profile.html";
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
          let lvl = 0;
          try { lvl = JSON.parse(localStorage.getItem(STATE_KEY) || "{}").level || 0; } catch (e) {}
          badge.textContent = String(lvl);
          badge.title = "";
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
        btn.onclick = () => { window.location.href = "/admin.html"; };
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

  window.SteamAuth = { getUser: () => (me.loggedIn ? me.user : null), refreshUI: buildUI, requireLogin, recordPull, readBestPull };
})();
