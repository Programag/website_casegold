require("dotenv").config();

const fs = require("fs");
const path = require("path");
const http = require("http");
const express = require("express");
const session = require("express-session");
const passport = require("passport");
const SteamStrategy = require("passport-steam").Strategy;
const { Server: SocketIOServer } = require("socket.io");
const attachBattleLobby = require("./battle-lobby");

const PORT = process.env.PORT || 3000;
const SITE_URL = (process.env.SITE_URL || `http://localhost:${PORT}`).replace(/\/+$/, "");
const IS_HTTPS = SITE_URL.startsWith("https://");
const ADMIN_STEAMID = process.env.ADMIN_STEAMID || null;

if (!process.env.STEAM_API_KEY) {
  console.error("Brak STEAM_API_KEY w .env - logowanie przez Steam nie zadziała.");
}
if (!process.env.SESSION_SECRET) {
  console.error("Brak SESSION_SECRET w .env.");
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Magazyn kont graczy.
//
// UWAGA: to musi leżeć na trwałym, sieciowym storage - Render (i podobne
// platformy) na darmowym planie nie dają dysku, więc lokalny plik JSON
// znika przy KAŻDYM redeployu, zerując balans/ekwipunek/statystyki
// WSZYSTKICH graczy. Domyślnie (i lokalnie, bez żadnej konfiguracji)
// dane wciąż lądują w pliku obok kodu - wygodne do developmentu. Gdy
// ustawione są UPSTASH_REDIS_REST_URL i UPSTASH_REDIS_REST_TOKEN (darmowe
// konto na upstash.com, patrz render.yaml), magazynem staje się Upstash
// Redis przez jego REST API - przeżywa redeploye, bo żyje poza kontenerem.
// ---------------------------------------------------------------------------
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, "data");
const USERS_FILE = path.join(DATA_DIR, "users.json");

const REDIS_URL = process.env.UPSTASH_REDIS_REST_URL;
const REDIS_TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN;
const USE_REDIS = !!(REDIS_URL && REDIS_TOKEN);
const REDIS_USERS_KEY = "cs2sim:users";

if (USE_REDIS) {
  console.log("Magazyn graczy: Upstash Redis (dane przetrwają redeploy).");
} else {
  console.warn(
    "Magazyn graczy: lokalny plik JSON (data/users.json) - zniknie przy " +
      "następnym redeployu na Render. Ustaw UPSTASH_REDIS_REST_URL i " +
      "UPSTASH_REDIS_REST_TOKEN, żeby dane były trwałe (patrz render.yaml)."
  );
}

async function redisCommand(args) {
  const res = await fetch(REDIS_URL, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${REDIS_TOKEN}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(args),
  });
  const data = await res.json();
  if (data.error) throw new Error(data.error);
  return data.result;
}

// Ogólne GET/SET z TTL na Upstash, do rzeczy poza kontami graczy (np. trwałe
// migawki zakończonych bitew - patrz battle-lobby.js). Poza USE_REDIS
// (lokalny dev bez Upstash) po prostu nie działają - wywołujący ma wtedy
// polegać wyłącznie na pamięci procesu, tak jak dotąd.
async function redisGet(key) {
  if (!USE_REDIS) return null;
  const raw = await redisCommand(["GET", key]);
  return raw ? JSON.parse(raw) : null;
}
async function redisSet(key, value, ttlSeconds) {
  if (!USE_REDIS) return;
  await redisCommand(["SET", key, JSON.stringify(value), "EX", String(ttlSeconds)]);
}

async function readUsers() {
  if (USE_REDIS) {
    // WAŻNE: NIE łapać tu błędu i zwracać {} - wywołujący (np. logowanie
    // przez Steam) traktowałby to jak "nikt jeszcze nie istnieje" i przy
    // zapisie skasowałby WSZYSTKICH graczy, zerując przy okazji stan tego,
    // kto się właśnie loguje. Przejściowy błąd odczytu musi się propagować
    // jako błąd, a nie udawać pustą bazę.
    const raw = await redisCommand(["GET", REDIS_USERS_KEY]);
    return raw ? JSON.parse(raw) : {};
  }
  try {
    return JSON.parse(fs.readFileSync(USERS_FILE, "utf8"));
  } catch (e) {
    return {};
  }
}

async function writeUsers(users) {
  if (USE_REDIS) {
    await redisCommand(["SET", REDIS_USERS_KEY, JSON.stringify(users)]);
    return;
  }
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.writeFileSync(USERS_FILE, JSON.stringify(users, null, 2));
}

// ---------------------------------------------------------------------------
// Drobne dane serwisowe niezwiązane z żadnym konkretnym graczem (na razie:
// data ostatniej wypłaty "Niefartu dnia" - patrz niżej). Ten sam magazyn co
// konta graczy (Redis albo lokalny plik), osobny klucz/plik.
// ---------------------------------------------------------------------------
const META_FILE = path.join(DATA_DIR, "meta.json");
const REDIS_META_KEY = "cs2sim:meta";

async function readMeta() {
  if (USE_REDIS) {
    const raw = await redisCommand(["GET", REDIS_META_KEY]);
    return raw ? JSON.parse(raw) : {};
  }
  try {
    return JSON.parse(fs.readFileSync(META_FILE, "utf8"));
  } catch (e) {
    return {};
  }
}
async function writeMeta(meta) {
  if (USE_REDIS) {
    await redisCommand(["SET", REDIS_META_KEY, JSON.stringify(meta)]);
    return;
  }
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.writeFileSync(META_FILE, JSON.stringify(meta, null, 2));
}

// ---------------------------------------------------------------------------
// readUsers()+writeUsers() to "przeczytaj CAŁY magazyn, zmień w pamięci,
// zapisz CAŁY magazyn z powrotem" - bez żadnej izolacji między żądaniami.
// Gdy dwa żądania, które modyfikują dane graczy (np. admin zmieniający komuś
// saldo w panelu i ten sam gracz kończący akurat otwieranie skrzynki),
// nakładają się w czasie, oba mogą przeczytać stan PRZED tym, jak
// którekolwiek zdąży go zapisać - kto zapisze jako drugi, bezmyślnie
// nadpisuje całym swoim (już nieaktualnym) stanem to, co właśnie zapisał
// pierwszy. Klasyczny "lost update"; dokładnie objaw "zmieniam komuś saldo,
// a jeśli w tej samej chwili coś otwiera, zmiana znika". Serializujemy więc
// KAŻDĄ sekcję czytaj-zmień-zapisz przez jedną wspólną kolejkę promisów, więc
// w danej chwili magazyn modyfikuje tylko jedno żądanie na raz - wystarczające
// zabezpieczenie dla jednego procesu Node (tak działa ten serwis na Render).
// ---------------------------------------------------------------------------
let usersWriteQueue = Promise.resolve();
function withUsersLock(fn) {
  const run = usersWriteQueue.then(fn, fn);
  usersWriteQueue = run.then(
    () => {},
    () => {}
  );
  return run;
}

// ---------------------------------------------------------------------------
// "Niefart dnia" - dzienny ranking najwięcej przegranych bitew Case Battle.
// Widoczna topka (GET /api/leaderboard, sekcja "niefart") pokazuje TOP 20
// wg liczby przegranych bitew Case Battle DZISIAJ (czasu polskiego, wg
// battleHistory - ten sam, lekki dziennik co zakładka "Moje bitwy", max 30
// ostatnich wpisów na gracza). Tuż po północy górna POŁOWA tej listy (10
// z 20) dostaje automatycznie 5000 zł do salda za dzień, który się właśnie
// skończył - patrz checkNiefartPayout niżej.
// ---------------------------------------------------------------------------
const NIEFART_REWARD = 5000;
const NIEFART_TOP_SHOWN = 20;
const NIEFART_TOP_REWARDED = 10;

function warsawDateString(epochMs) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Warsaw" }).format(new Date(epochMs));
}

function countLossesOnDate(u, dateStr) {
  const history = u.state && Array.isArray(u.state.battleHistory) ? u.state.battleHistory : [];
  return history.filter((e) => e && e.outcome === "loss" && typeof e.at === "number" && warsawDateString(e.at) === dateStr).length;
}

// Zwraca top NIEFART_TOP_SHOWN graczy wg liczby przegranych bitew w danym
// dniu (tylko ci z co najmniej jedną przegraną - "0 przegranych" nie ma co
// robić w topce niefartu).
function niefartRankingForDate(users, dateStr) {
  return Object.values(users)
    .filter((u) => u.slug)
    .map((u) => ({ steamid: u.steamid, slug: u.slug, displayName: u.displayName, avatar: u.avatar, value: countLossesOnDate(u, dateStr) }))
    .filter((row) => row.value > 0)
    .sort((a, b) => b.value - a.value)
    .slice(0, NIEFART_TOP_SHOWN);
}

// Sprawdza (wywoływane co minutę, patrz setInterval przy starcie serwera),
// czy minęła już północ czasu polskiego od ostatniej wypłaty, i jeśli tak,
// nagradza górną połowę (10 z 20) niefartu ZA DZIEŃ, KTÓRY SIĘ WŁAŚNIE
// SKOŃCZYŁ. Idempotentne dzięki trwale zapisanej dacie ostatniej wypłaty
// (meta.niefartLastPayoutDate) - bezpieczne, gdy interwał odpali się kilka
// razy tuż po północy albo po restarcie serwera. Pierwsze uruchomienie po
// wdrożeniu tego mechanizmu celowo NIE wypłaca niczego za dzień, w którym
// serwer właśnie wystartował (za mało/nieznane dane) - czeka na najbliższą
// prawdziwą północ.
let niefartCheckRunning = false;
async function checkNiefartPayout() {
  if (niefartCheckRunning) return;
  niefartCheckRunning = true;
  try {
    const meta = await readMeta();
    if (!meta.niefartLastPayoutDate) {
      meta.niefartLastPayoutDate = warsawDateString(Date.now());
      await writeMeta(meta);
      return;
    }
    const yesterdayStr = warsawDateString(Date.now() - 24 * 60 * 60 * 1000);
    if (meta.niefartLastPayoutDate === yesterdayStr) return; // już wypłacone za wczoraj

    let winners = [];
    await withUsersLock(async () => {
      const users = await readUsers();
      winners = niefartRankingForDate(users, yesterdayStr).slice(0, NIEFART_TOP_REWARDED);
      if (winners.length === 0) return;
      winners.forEach((w) => {
        const u = users[w.steamid];
        if (!u || !u.state) return;
        u.state.balance = (typeof u.state.balance === "number" ? u.state.balance : 0) + NIEFART_REWARD;
        // Autorytatywna, serwerowa zmiana salda - klient musi ją przyjąć
        // bezwarunkowo przy najbliższej synchronizacji, tak samo jak przy
        // ręcznej edycji z panelu admina (patrz PUT /api/admin/users/:steamid
        // i merge w steam-auth.js) - inaczej lokalny cache przeglądarki
        // (prawie zawsze "świeższy" niż serwer) po cichu nadpisałby tę
        // nagrodę z powrotem przy pierwszym zwykłym zapisie stanu gracza.
        u.state.adminOverrideAt = Date.now();
        u.state.updatedAt = u.state.adminOverrideAt;
      });
      await writeUsers(users);
    });

    meta.niefartLastPayoutDate = yesterdayStr;
    meta.niefartLastWinners = winners.map((w) => ({ slug: w.slug, displayName: w.displayName, avatar: w.avatar, losses: w.value }));
    await writeMeta(meta);
    if (winners.length) {
      console.log(`Niefart dnia (${yesterdayStr}): nagrodzono ${winners.length} graczy kwotą ${NIEFART_REWARD} zł każdy.`);
    }
  } catch (e) {
    console.error("Błąd wypłaty niefartu dnia:", e.message);
  } finally {
    niefartCheckRunning = false;
  }
}
setInterval(checkNiefartPayout, 60 * 1000);
checkNiefartPayout(); // sprawdź też od razu przy starcie serwera, na wypadek gdyby północ minęła, gdy serwer nie działał

// ---------------------------------------------------------------------------
// Magazyn sesji logowania.
//
// express-session bez jawnie podanego `store` używa domyślnego MemoryStore,
// który trzyma sesje wyłącznie w RAM-ie tego jednego procesu. Redeploy
// zawsze startuje NOWY proces - MemoryStore startuje wtedy pusty i każdy
// dotąd zalogowany gracz w jednej chwili przestaje mieć ważną sesję. Strona
// wtedy traktuje go jak gościa (loggedIn:false), więc KAŻDA podstrona pokazuje
// saldo 0,00 zł mimo że dane na koncie w Redisie wcale nie zniknęły - to
// dokładnie objaw "po zmianie w kodzie saldo się zeruje wszystkim naraz".
// Sesje muszą więc żyć w tym samym trwałym magazynie co konta graczy.
class UpstashSessionStore extends session.Store {
  _key(sid) {
    return `cs2sim:sess:${sid}`;
  }
  _ttlSeconds(sessionData) {
    const maxAge = sessionData && sessionData.cookie && sessionData.cookie.maxAge;
    return Math.max(60, Math.floor((typeof maxAge === "number" ? maxAge : 90 * 24 * 60 * 60 * 1000) / 1000));
  }
  async get(sid, cb) {
    try {
      const raw = await redisCommand(["GET", this._key(sid)]);
      cb(null, raw ? JSON.parse(raw) : null);
    } catch (e) {
      cb(e);
    }
  }
  async set(sid, sessionData, cb) {
    try {
      await redisCommand(["SET", this._key(sid), JSON.stringify(sessionData), "EX", String(this._ttlSeconds(sessionData))]);
      if (cb) cb(null);
    } catch (e) {
      if (cb) cb(e);
    }
  }
  async destroy(sid, cb) {
    try {
      await redisCommand(["DEL", this._key(sid)]);
      if (cb) cb(null);
    } catch (e) {
      if (cb) cb(e);
    }
  }
  async touch(sid, sessionData, cb) {
    try {
      await redisCommand(["EXPIRE", this._key(sid), String(this._ttlSeconds(sessionData))]);
      if (cb) cb(null);
    } catch (e) {
      if (cb) cb(e);
    }
  }
}

function publicUser(u) {
  if (!u) return null;
  return {
    steamid: u.steamid,
    displayName: u.displayName,
    avatar: u.avatar,
    profileUrl: u.profileUrl,
    createdAt: u.createdAt || null,
    slug: u.slug || null,
    state: u.state || null,
  };
}

// ---------------------------------------------------------------------------
// Poziom gracza jako "wskaźnik wodny" (levelWatermark) - nigdy nie może
// spaść. xp jest jedynym prawdziwym źródłem danych, ale każda przyszła
// zmiana wzoru poziomów (albo błąd) mogłaby chwilowo obniżyć poziom
// wyliczony z xp - levelWatermark pamięta najwyższy poziom, jaki gracz
// kiedykolwiek legalnie osiągnął, i to on (nie surowe wyliczenie z xp) jest
// pokazywany/używany do odbioru nagród. Rośnie wyłącznie przez Math.max,
// więc scalanie klient<->serwer jest odporne na wyścigi z definicji - w
// przeciwieństwie do poprzedniego mechanizmu (mutacja xp + porównanie
// znaczników czasu), który dało się łatwo "przegapić" przy złym timingu.
function xpForLevelServer(level) {
  return 1000 * (Math.pow(1.1, level) - 1);
}
function levelForXpServer(totalXp) {
  const xp = typeof totalXp === "number" && totalXp > 0 ? totalXp : 0;
  const EPS = 1e-9;
  let level = Math.floor(Math.log(xp / 1000 + 1) / Math.log(1.1) + EPS);
  while (xpForLevelServer(level + 1) - EPS <= xp) level++;
  while (level > 0 && xpForLevelServer(level) - EPS > xp) level--;
  return level;
}
// Jednorazowe (per konto), w pełni automatyczne zaszczepienie levelWatermark
// dla kont istniejących PRZED wprowadzeniem tego mechanizmu - w tym kont
// dotkniętych wcześniejszym podniesieniem progu 1. poziomu z 10 na 100 EXP.
// Brak liczbowego pola levelWatermark = konto jeszcze nigdy go nie miało,
// więc liczymy je raz z tego, co już wiemy (zapisany level i - na wypadek,
// gdyby próg poziomu kiedyś jeszcze wzrósł 10x - xp*10 jako dolna granica).
// Każdy kolejny zapis (PUT /api/state) już zawsze wpisuje liczbę, więc to
// się nigdy nie uruchamia drugi raz dla tego samego konta. Nowe konta mają
// swój pierwszy zapis stanu zawsze liczbowy (patrz PUT /api/state), więc
// nigdy tędy nie przechodzą - nie ma ryzyka podbicia poziomu komuś, kto
// nigdy nie grał pod starym wzorem.
//
// WAŻNE: przeskalowanie xp (x10 dla kont sprzed zmiany progu) to ODDZIELNY,
// niezależnie zaszczepiany krok - NIE wolno go chować za tym samym warunkiem
// co levelWatermark. Konta, które dostały watermark PRZED dodaniem tej
// korekty (czyli już mają liczbowy levelWatermark, ale ich xp nigdy nie
// zostało realnie przeskalowane), inaczej nigdy by tędy nie przeszły -
// watermark poprawnie pokazywałby odzyskany poziom, ale KAŻDE kolejne
// zdobyte EXP liczyłoby się względem xp, które nigdy nie dogoni progu tego
// poziomu - pasek postępu zamrożony na 0% na zawsze, mimo że gracz
// faktycznie zdobywa EXP (dokładnie objaw "nie da się zyskać exp"). Migracja
// jest idempotentna dzięki fladze xpScaleMigratedV2, więc bezpiecznie
// uruchamia się automatycznie raz na konto, tak jak wcześniej ręczny
// przycisk admina robił to na żądanie.
function ensureLevelWatermark(u) {
  if (!u.state) return;
  if (!u.state.xpScaleMigratedV2) {
    const rawXp = typeof u.state.xp === "number" ? u.state.xp : 0;
    u.state.xp = rawXp * 10;
    u.state.xpScaleMigratedV2 = true;
  }
  if (typeof u.state.levelWatermark === "number") return;
  const xp = typeof u.state.xp === "number" ? u.state.xp : 0;
  const recoveredLevel = levelForXpServer(xp);
  const storedLevel = typeof u.state.level === "number" ? u.state.level : 0;
  u.state.levelWatermark = Math.max(storedLevel, recoveredLevel, 0);
}

// ---------------------------------------------------------------------------
// Publiczne "slugi" profili (np. /profile/aleksio). Przypisywany raz przy
// pierwszym logowaniu i trzymany na stałe, nawet jeśli gracz zmieni nazwę
// wyświetlaną w Steam - żeby udostępniony link nigdy się nie zepsuł.
// ---------------------------------------------------------------------------
function slugify(name) {
  return String(name || "")
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 32);
}

function assignSlug(users, steamid, displayName) {
  const base = slugify(displayName) || "gracz";
  const taken = (s) => Object.values(users).some((u) => u.steamid !== steamid && u.slug === s);
  if (!taken(base)) return base;
  let n = 2;
  while (taken(`${base}-${n}`)) n++;
  return `${base}-${n}`;
}

function findUserBySlug(users, slug) {
  return Object.values(users).find((u) => u.slug === slug) || null;
}

// ---------------------------------------------------------------------------
// Passport / Steam OpenID
// ---------------------------------------------------------------------------
passport.serializeUser((user, done) => done(null, user.steamid));
passport.deserializeUser((steamid, done) => {
  readUsers()
    .then((users) => done(null, users[steamid] || null))
    .catch((e) => done(e));
});

passport.use(
  new SteamStrategy(
    {
      returnURL: `${SITE_URL}/auth/steam/return`,
      realm: `${SITE_URL}/`,
      apiKey: process.env.STEAM_API_KEY,
    },
    async (identifier, profile, done) => {
      try {
        const steamid = profile.id;
        const savedUser = await withUsersLock(async () => {
          const users = await readUsers();
          const existing = users[steamid];
          users[steamid] = {
            steamid,
            displayName: profile.displayName,
            avatar: (profile.photos && profile.photos[2] && profile.photos[2].value) ||
              (profile.photos && profile.photos[0] && profile.photos[0].value) || null,
            profileUrl: profile._json && profile._json.profileurl,
            slug: existing && existing.slug ? existing.slug : assignSlug(users, steamid, profile.displayName),
            state: existing ? existing.state : null,
            createdAt: existing ? existing.createdAt : Date.now(),
          };
          await writeUsers(users);
          return users[steamid];
        });
        done(null, savedUser);
      } catch (e) {
        done(e);
      }
    }
  )
);

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------
const app = express();
app.set("trust proxy", 1);
app.use(express.json({ limit: "512kb" }));

const sessionMiddleware = session({
  store: USE_REDIS ? new UpstashSessionStore() : undefined,
  secret: process.env.SESSION_SECRET,
  resave: false,
  saveUninitialized: false,
  cookie: {
    httpOnly: true,
    sameSite: "lax",
    secure: IS_HTTPS,
    maxAge: 90 * 24 * 60 * 60 * 1000, // 90 dni
  },
});
app.use(sessionMiddleware);
app.use(passport.initialize());
app.use(passport.session());

// ---- Auth routes ----
app.get("/auth/steam", passport.authenticate("steam"));

app.get(
  "/auth/steam/return",
  passport.authenticate("steam", { failureRedirect: "/" }),
  (req, res) => res.redirect("/")
);

app.post("/auth/logout", (req, res) => {
  req.logout(() => {
    res.json({ ok: true });
  });
});

// ---- Profile / state API ----
app.get("/api/me", (req, res) => {
  if (!req.user) return res.json({ loggedIn: false });
  const isAdmin = !!ADMIN_STEAMID && req.user.steamid === ADMIN_STEAMID;
  ensureLevelWatermark(req.user); // tania, deterministyczna operacja w pamięci - trwały zapis i tak nastąpi przy najbliższym PUT /api/state
  res.json({ loggedIn: true, user: publicUser(req.user), isAdmin });
});

// ---- Publiczny profil gracza (np. GET /api/profile/aleksio) ----
app.get("/api/profile/:slug", async (req, res) => {
  let users;
  try {
    users = await readUsers();
  } catch (e) {
    return res.status(503).json({ error: "storage_unavailable" });
  }
  const u = findUserBySlug(users, req.params.slug);
  if (!u) return res.json({ found: false });
  const st = u.state || {};
  res.json({
    found: true,
    profile: {
      slug: u.slug,
      displayName: u.displayName,
      avatar: u.avatar,
      profileUrl: u.profileUrl,
      createdAt: u.createdAt || null,
      // Najwyższa ze znanych wartości (surowe level, levelWatermark, i świeże
      // wyliczenie z xp) - publiczny profil nie powinien pokazywać niższego
      // poziomu niż to, co gracz faktycznie już wywalczył, nawet jeśli jego
      // przeglądarka jeszcze nie zdążyła zsynchronizować wskaźnika wodnego.
      level: Math.max(
        levelForXpServer(typeof st.xp === "number" ? st.xp : 0),
        typeof st.levelWatermark === "number" ? st.levelWatermark : 0,
        typeof st.level === "number" ? st.level : 0
      ),
      bestPull: st.bestPull || null,
    },
  });
});

// ---- Publiczna topka (saldo / kliknięcia upgrade'ów / otwarte skrzynki) ----
app.get("/api/leaderboard", async (req, res) => {
  let users;
  try {
    users = await readUsers();
  } catch (e) {
    return res.status(503).json({ error: "storage_unavailable" });
  }
  const list = Object.values(users)
    .filter((u) => u.slug)
    .map((u) => {
      const st = u.state || {};
      return {
        slug: u.slug,
        displayName: u.displayName,
        avatar: u.avatar,
        balance: typeof st.balance === "number" ? st.balance : 0,
        upgradeClicks: typeof st.upgradeClicks === "number" ? st.upgradeClicks : 0,
        casesOpened: typeof st.casesOpened === "number" ? st.casesOpened : 0,
      };
    });
  const top = (field) =>
    list
      .slice()
      .sort((a, b) => b[field] - a[field])
      .slice(0, 20)
      .map((u) => ({ slug: u.slug, displayName: u.displayName, avatar: u.avatar, value: u[field] }));
  res.json({
    balance: top("balance"),
    upgrades: top("upgradeClicks"),
    cases: top("casesOpened"),
    // Ranking "na żywo" za DZISIAJ (w toku) - patrz checkNiefartPayout dla
    // faktycznej, jednorazowej wypłaty za dzień, który się skończył.
    niefart: niefartRankingForDate(users, warsawDateString(Date.now())),
  });
});

app.put("/api/state", async (req, res) => {
  if (!req.user) return res.status(401).json({ error: "not_logged_in" });
  const body = req.body || {};
  let result;
  try {
    // Cały odczyt-zmiana-zapis musi być zablokowany JEDNĄ wspólną kolejką
    // (patrz withUsersLock) - inaczej ten push i np. równoległa edycja salda
    // z panelu admina mogą oba przeczytać stan przed tym, jak którekolwiek
    // zdąży go zapisać, i ten, kto zapisze jako drugi, zgubi zmiany pierwszego.
    result = await withUsersLock(async () => {
      const users = await readUsers();
      const u = users[req.user.steamid];
      if (!u) return { status: 404, body: { error: "no_such_user" } };

      // Ochrona przed nadpisaniem ręcznej edycji z panelu admina (albo zapisu z
      // innej karty/urządzenia) przez spóźniony, "nieświadomy" tego push z tej
      // karty. Klient wysyła `baseUpdatedAt` - updatedAt serwera, jakie ostatnio
      // faktycznie widział. Jeśli serwer ma już coś nowszego niż ta baza, to
      // znaczy, że coś zmieniło stan w międzyczasie (np. admin) - odrzucamy ten
      // zapis zamiast bezmyślnie go nadpisywać i zwracamy aktualny stan serwera,
      // żeby klient mógł się na nowo zsynchronizować.
      if (
        u.state &&
        typeof u.state.updatedAt === "number" &&
        typeof body.baseUpdatedAt === "number" &&
        u.state.updatedAt > body.baseUpdatedAt
      ) {
        return { status: 409, body: { error: "stale_write", state: u.state } };
      }

      const bestPull =
        body.bestPull && typeof body.bestPull === "object" && typeof body.bestPull.price === "number"
          ? {
              weapon: String(body.bestPull.weapon || ""),
              skin: String(body.bestPull.skin || ""),
              wear: String(body.bestPull.wear || ""),
              price: body.bestPull.price,
              at: typeof body.bestPull.at === "number" ? body.bestPull.at : Date.now(),
            }
          : (u.state && u.state.bestPull) || null;

      // Zupełnie nowe konto (u.state === null) nigdy nie grało pod starym,
      // nieprzeskalowanym wzorem - jego xp jest od razu w aktualnej skali, więc
      // traktujemy je jako już zmigrowane, żeby ensureLevelWatermark nigdy go
      // przypadkiem nie pomnożyło x10 przy jego DRUGIM kontakcie z serwerem.
      const isBrandNewAccount = !u.state;
      ensureLevelWatermark(u); // no-op tylko dla u.state === null; dla reszty patrz komentarz przy funkcji
      // Jeśli powyższe dopiero co przeskalowało xp x10 (jednorazowa migracja
      // starych kont), ten push mógł powstać z wartości SPRZED korekty (klient
      // policzył go zanim zdążył zobaczyć poprawiony stan) - Math.max chroni
      // przed przypadkowym nadpisaniem świeżo przeskalowanego xp starszą,
      // 10x za niską liczbą z tego konkretnego zapisu.
      const xpFloor = u.state && typeof u.state.xp === "number" ? u.state.xp : 0;

      u.state = {
        balance: typeof body.balance === "number" ? body.balance : 0,
        inventory: Array.isArray(body.inventory) ? body.inventory : [],
        invCounter: typeof body.invCounter === "number" ? body.invCounter : 0,
        level: typeof body.level === "number" ? body.level : 0,
        xp: Math.max(typeof body.xp === "number" ? body.xp : 0, xpFloor),
        dailyBonusAt: typeof body.dailyBonusAt === "number" ? body.dailyBonusAt : null,
        dailyStreak: typeof body.dailyStreak === "number" ? body.dailyStreak : (u.state && u.state.dailyStreak) || 0,
        freeCaseAt: typeof body.freeCaseAt === "number" ? body.freeCaseAt : null,
        bestPull,
        upgradeClicks: typeof body.upgradeClicks === "number" ? body.upgradeClicks : (u.state && u.state.upgradeClicks) || 0,
        casesOpened: typeof body.casesOpened === "number" ? body.casesOpened : (u.state && u.state.casesOpened) || 0,
        spentCases: typeof body.spentCases === "number" ? body.spentCases : (u.state && u.state.spentCases) || 0,
        spentUpgrader: typeof body.spentUpgrader === "number" ? body.spentUpgrader : (u.state && u.state.spentUpgrader) || 0,
        battlesPlayed: typeof body.battlesPlayed === "number" ? body.battlesPlayed : (u.state && u.state.battlesPlayed) || 0,
        battlesWon: typeof body.battlesWon === "number" ? body.battlesWon : (u.state && u.state.battlesWon) || 0,
        questClaims: body.questClaims && typeof body.questClaims === "object" ? body.questClaims : (u.state && u.state.questClaims) || {},
        claimedLevelRewards: Array.isArray(body.claimedLevelRewards) ? body.claimedLevelRewards : (u.state && u.state.claimedLevelRewards) || [],
        battleHistory: Array.isArray(body.battleHistory) ? body.battleHistory : (u.state && u.state.battleHistory) || [],
        // Poziom jako wskaźnik wodny (patrz ensureLevelWatermark) - rośnie
        // wyłącznie przez Math.max z tego, co przysłał klient, i tego, co już
        // było zapisane. Nigdy nie może spaść, niezależnie od kolejności
        // zapisów - odporne na wyścigi z definicji, bez potrzeby porównywania
        // znaczników czasu jak przy pozostałych polach.
        levelWatermark: Math.max(typeof body.levelWatermark === "number" ? body.levelWatermark : 0, (u.state && u.state.levelWatermark) || 0),
        // Zachowane dla zgodności z ewentualnymi kontami zmigrowanymi starym
        // mechanizmem (mnożenie xp) - używane tylko jako wskazówka wewnątrz
        // ensureLevelWatermark, klient go już nie odczytuje ani nie wysyła.
        xpScaleMigratedV2: isBrandNewAccount ? true : !!(u.state && u.state.xpScaleMigratedV2),
        // Musi przetrwać każdy zwykły zapis z gry - to jedyny sposób, w jaki
        // KAŻDA przeglądarka gracza (nie tylko ta, z której akurat przyszedł ten
        // konkretny push) dowiaduje się, że admin autorytatywnie nadpisał stan
        // (patrz PUT /api/admin/users/:steamid i merge w steam-auth.js).
        adminOverrideAt: (u.state && typeof u.state.adminOverrideAt === "number") ? u.state.adminOverrideAt : null,
        updatedAt: Date.now(),
      };
      await writeUsers(users);
      return { status: 200, body: { ok: true, updatedAt: u.state.updatedAt } };
    });
  } catch (e) {
    console.error(`PUT /api/state błąd zapisu dla ${req.user.steamid}:`, e.message);
    return res.status(503).json({ error: "storage_unavailable" });
  }
  res.status(result.status).json(result.body);
});

// ---- Admin: view/edit any player's name + balance ----
function requireAdmin(req, res, next) {
  if (!req.user || !ADMIN_STEAMID || req.user.steamid !== ADMIN_STEAMID) {
    return res.status(403).json({ error: "forbidden" });
  }
  next();
}

app.get("/api/admin/users", requireAdmin, async (req, res) => {
  let users;
  try {
    users = await readUsers();
  } catch (e) {
    return res.status(503).json({ error: "storage_unavailable" });
  }
  const list = Object.values(users).map((u) => ({
    steamid: u.steamid,
    displayName: u.displayName,
    avatar: u.avatar,
    balance: u.state ? u.state.balance : 0,
    level: u.state ? u.state.level : 0,
    xp: u.state ? u.state.xp : 0,
    claimedLevelRewards: u.state && Array.isArray(u.state.claimedLevelRewards) ? u.state.claimedLevelRewards : [],
    invCount: u.state && Array.isArray(u.state.inventory) ? u.state.inventory.length : 0,
    updatedAt: u.state ? u.state.updatedAt : null,
  }));
  list.sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
  res.json({ users: list });
});

app.put("/api/admin/users/:steamid", requireAdmin, async (req, res) => {
  const body = req.body || {};
  let result;
  try {
    // Patrz komentarz przy PUT /api/state - ta sama blokada, bo inaczej ta
    // edycja i np. równoległy push gracza (kończące się otwieranie skrzynki)
    // mogą oba przeczytać stan przed tym, jak którekolwiek zdąży go zapisać,
    // i ten, kto zapisze jako drugi, zgubi zmiany pierwszego - dokładnie
    // objaw "zmieniam komuś saldo, a jeśli coś w tej chwili robi, znika".
    result = await withUsersLock(async () => {
      const users = await readUsers();
      const u = users[req.params.steamid];
      if (!u) return { status: 404, body: { error: "no_such_user" } };
      if (!u.state) u.state = { balance: 0, inventory: [], invCounter: 0, level: 0, xp: 0 };
      if (typeof body.balance === "number") u.state.balance = body.balance;
      if (typeof body.level === "number") {
        u.state.level = body.level;
        // W przeciwieństwie do normalnej gry (gdzie levelWatermark rośnie
        // WYŁĄCZNIE przez Math.max, żeby chronić przed przypadkowym/chwilowym
        // zaniżeniem), ręczna edycja w panelu admina to świadoma, autorytatywna
        // korekta - musi móc też OBNIŻYĆ poziom (np. po cofnięciu błędnie
        // przyznanego EXP), więc ustawiamy wskaźnik wodny wprost, bez Math.max.
        u.state.levelWatermark = body.level;
      }
      if (typeof body.xp === "number") u.state.xp = body.xp;
      // Wartości wpisane ręcznie przez admina są z definicji już w aktualnej
      // skali - kolejna automatyczna migracja x10 (ensureLevelWatermark) nie
      // powinna ich już nigdy tykać.
      u.state.xpScaleMigratedV2 = true;
      if (Array.isArray(body.claimedLevelRewards)) {
        u.state.claimedLevelRewards = body.claimedLevelRewards.filter((n) => typeof n === "number" && isFinite(n));
      }
      // Znacznik "admin właśnie autorytatywnie nadpisał ten stan" - klient
      // (steam-auth.js) używa go, żeby w pełni zaufać serwerowi przy następnej
      // synchronizacji, z pominięciem zwykłej ochrony "świeższy/wyższy wygrywa"
      // (ta ochrona jest słuszna przy normalnej grze, ale nie może blokować
      // świadomej korekty admina).
      u.state.adminOverrideAt = Date.now();
      u.state.updatedAt = u.state.adminOverrideAt;
      await writeUsers(users);
      return {
        status: 200,
        body: {
          ok: true,
          user: { steamid: u.steamid, displayName: u.displayName, balance: u.state.balance, level: u.state.level, xp: u.state.xp },
        },
      };
    });
  } catch (e) {
    return res.status(503).json({ error: "storage_unavailable" });
  }
  res.status(result.status).json(result.body);
});

// ---- Publiczna podstrona profilu, np. /profile/aleksio ----
app.get("/profile/:slug", (req, res) => {
  res.sendFile(path.join(__dirname, "profile.html"));
});

// ---- Static site ----
// Bez jawnego Cache-Control przeglądarki (zwłaszcza mobilny Safari) potrafią
// same zdecydować, że stary HTML/JS/CSS jest "świeży" na podstawie samych
// nagłówków Last-Modified, i trzymać go z pamięci podręcznej nawet po
// zwykłym odświeżeniu strony - użytkownik utyka wtedy na starej wersji kodu
// bez żadnego komunikatu o błędzie. "no-cache" nie wyłącza cache'a, tylko
// wymusza rewalidację (If-None-Match/ETag) przy każdym wczytaniu strony.
app.use(
  express.static(__dirname, {
    extensions: ["html"],
    setHeaders: (res, filePath) => {
      if (/\.(html|js|css)$/i.test(filePath)) {
        res.setHeader("Cache-Control", "no-cache");
      }
    },
  })
);

// ---- Real-time Case Battle lobbies ----
const httpServer = http.createServer(app);
const io = new SocketIOServer(httpServer);
io.engine.use(sessionMiddleware); // lets battle-lobby.js read socket.request.session
attachBattleLobby(io, { readUsers, redisGet, redisSet, useRedis: USE_REDIS });

httpServer.listen(PORT, () => {
  console.log(`CS2SIM działa: ${SITE_URL} (port ${PORT})`);
});
