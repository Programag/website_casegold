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

if (!process.env.STEAM_API_KEY) {
  console.error("Brak STEAM_API_KEY w .env - logowanie przez Steam nie zadziała.");
}
if (!process.env.SESSION_SECRET) {
  console.error("Brak SESSION_SECRET w .env.");
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Bardzo prosty magazyn kont (plik JSON). Wystarczający dla small-scale
// symulatora na fałszywą walutę - brak realnych transakcji finansowych.
// ---------------------------------------------------------------------------
const DATA_DIR = path.join(__dirname, "data");
const USERS_FILE = path.join(DATA_DIR, "users.json");

function readUsers() {
  try {
    return JSON.parse(fs.readFileSync(USERS_FILE, "utf8"));
  } catch (e) {
    return {};
  }
}

function writeUsers(users) {
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
    state: u.state || null,
  };
}

// ---------------------------------------------------------------------------
// Passport / Steam OpenID
// ---------------------------------------------------------------------------
passport.serializeUser((user, done) => done(null, user.steamid));
passport.deserializeUser((steamid, done) => {
  const users = readUsers();
  done(null, users[steamid] || null);
});

passport.use(
  new SteamStrategy(
    {
      returnURL: `${SITE_URL}/auth/steam/return`,
      realm: `${SITE_URL}/`,
      apiKey: process.env.STEAM_API_KEY,
    },
    (identifier, profile, done) => {
      const steamid = profile.id;
      const users = readUsers();
      const existing = users[steamid];
      users[steamid] = {
        steamid,
        displayName: profile.displayName,
        avatar: (profile.photos && profile.photos[2] && profile.photos[2].value) ||
          (profile.photos && profile.photos[0] && profile.photos[0].value) || null,
        profileUrl: profile._json && profile._json.profileurl,
        state: existing ? existing.state : null,
        createdAt: existing ? existing.createdAt : Date.now(),
      };
      writeUsers(users);
      done(null, users[steamid]);
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
  res.json({ loggedIn: true, user: publicUser(req.user) });
});

app.put("/api/state", (req, res) => {
  if (!req.user) return res.status(401).json({ error: "not_logged_in" });
  const body = req.body || {};
  const users = readUsers();
  const u = users[req.user.steamid];
  if (!u) return res.status(404).json({ error: "no_such_user" });

  u.state = {
    balance: typeof body.balance === "number" ? body.balance : 0,
    inventory: Array.isArray(body.inventory) ? body.inventory : [],
    invCounter: typeof body.invCounter === "number" ? body.invCounter : 0,
    level: typeof body.level === "number" ? body.level : 0,
    xp: typeof body.xp === "number" ? body.xp : 0,
    dailyBonusAt: typeof body.dailyBonusAt === "number" ? body.dailyBonusAt : null,
    freeCaseAt: typeof body.freeCaseAt === "number" ? body.freeCaseAt : null,
    updatedAt: Date.now(),
  };
  writeUsers(users);
  res.json({ ok: true });
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
