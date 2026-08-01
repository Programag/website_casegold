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

async function readUsers() {
  if (USE_REDIS) {
    try {
      const raw = await redisCommand(["GET", REDIS_USERS_KEY]);
      return raw ? JSON.parse(raw) : {};
    } catch (e) {
      console.error("Redis readUsers błąd:", e.message);
      return {};
    }
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
        done(null, users[steamid]);
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
  res.json({ loggedIn: true, user: publicUser(req.user), isAdmin });
});

// ---- Publiczny profil gracza (np. GET /api/profile/aleksio) ----
app.get("/api/profile/:slug", async (req, res) => {
  const users = await readUsers();
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
      level: typeof st.level === "number" ? st.level : 0,
      bestPull: st.bestPull || null,
    },
  });
});

// ---- Publiczna topka (saldo / kliknięcia upgrade'ów / otwarte skrzynki) ----
app.get("/api/leaderboard", async (req, res) => {
  const users = await readUsers();
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
  });
});

app.put("/api/state", async (req, res) => {
  if (!req.user) return res.status(401).json({ error: "not_logged_in" });
  const body = req.body || {};
  const users = await readUsers();
  const u = users[req.user.steamid];
  if (!u) return res.status(404).json({ error: "no_such_user" });

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

  u.state = {
    balance: typeof body.balance === "number" ? body.balance : 0,
    inventory: Array.isArray(body.inventory) ? body.inventory : [],
    invCounter: typeof body.invCounter === "number" ? body.invCounter : 0,
    level: typeof body.level === "number" ? body.level : 0,
    xp: typeof body.xp === "number" ? body.xp : 0,
    dailyBonusAt: typeof body.dailyBonusAt === "number" ? body.dailyBonusAt : null,
    freeCaseAt: typeof body.freeCaseAt === "number" ? body.freeCaseAt : null,
    bestPull,
    upgradeClicks: typeof body.upgradeClicks === "number" ? body.upgradeClicks : (u.state && u.state.upgradeClicks) || 0,
    casesOpened: typeof body.casesOpened === "number" ? body.casesOpened : (u.state && u.state.casesOpened) || 0,
    updatedAt: Date.now(),
  };
  try {
    await writeUsers(users);
    res.json({ ok: true });
  } catch (e) {
    res.status(503).json({ error: "storage_unavailable" });
  }
});

// ---- Admin: view/edit any player's name + balance ----
function requireAdmin(req, res, next) {
  if (!req.user || !ADMIN_STEAMID || req.user.steamid !== ADMIN_STEAMID) {
    return res.status(403).json({ error: "forbidden" });
  }
  next();
}

app.get("/api/admin/users", requireAdmin, async (req, res) => {
  const users = await readUsers();
  const list = Object.values(users).map((u) => ({
    steamid: u.steamid,
    displayName: u.displayName,
    avatar: u.avatar,
    balance: u.state ? u.state.balance : 0,
    level: u.state ? u.state.level : 0,
    xp: u.state ? u.state.xp : 0,
    invCount: u.state && Array.isArray(u.state.inventory) ? u.state.inventory.length : 0,
    updatedAt: u.state ? u.state.updatedAt : null,
  }));
  list.sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0));
  res.json({ users: list });
});

app.put("/api/admin/users/:steamid", requireAdmin, async (req, res) => {
  const users = await readUsers();
  const u = users[req.params.steamid];
  if (!u) return res.status(404).json({ error: "no_such_user" });
  const body = req.body || {};
  if (!u.state) u.state = { balance: 0, inventory: [], invCounter: 0, level: 0, xp: 0 };
  if (typeof body.balance === "number") u.state.balance = body.balance;
  if (typeof body.level === "number") u.state.level = body.level;
  if (typeof body.xp === "number") u.state.xp = body.xp;
  u.state.updatedAt = Date.now();
  try {
    await writeUsers(users);
    res.json({
      ok: true,
      user: { steamid: u.steamid, displayName: u.displayName, balance: u.state.balance, level: u.state.level, xp: u.state.xp },
    });
  } catch (e) {
    res.status(503).json({ error: "storage_unavailable" });
  }
});

// ---- Publiczna podstrona profilu, np. /profile/aleksio ----
app.get("/profile/:slug", (req, res) => {
  res.sendFile(path.join(__dirname, "profile.html"));
});

// ---- Static site ----
app.use(express.static(__dirname, { extensions: ["html"] }));

// ---- Real-time Case Battle lobbies ----
const httpServer = http.createServer(app);
const io = new SocketIOServer(httpServer);
io.engine.use(sessionMiddleware); // lets battle-lobby.js read socket.request.session
attachBattleLobby(io, { readUsers });

httpServer.listen(PORT, () => {
  console.log(`CS2SIM działa: ${SITE_URL} (port ${PORT})`);
});
