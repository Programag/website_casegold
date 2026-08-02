/* =========================================================================
   CASE BATTLE — serwerowe lobby czasu rzeczywistego (Socket.IO)
   -------------------------------------------------------------------------
   Zasada zaufania jest identyczna jak w wersji lokalnej (localStorage),
   tylko przeniesiona na serwer: gospodarz danej bitwy raz oblicza wyniki
   (rollFromCase po jego stronie, z pełną wiedzą o pulach przedmiotów) i
   wysyła gotową tablicę `results` do serwera, który tylko ją retransmituje
   wszystkim uczestnikom tego pokoju. Serwer nie zna zasad losowania skrzynek
   (dane przedmiotów żyją tylko w battle.html) — jego jedyna rola to być
   wspólnym, zawsze-tym-samym źródłem prawdy o tym kto jest w lobby i kiedy
   bitwa faktycznie wystartowała, żeby różne przeglądarki widziały to samo.
   ========================================================================= */
const crypto = require("crypto");

const BOT_NAMES = ["Bot Alfa", "Bot Bravo", "Bot Charlie"];
const MAX_ROUNDS = 40;
const MAX_PLAYERS = 4;
const MAX_COST = 1000000;
const DISCONNECT_GRACE_MS = 8000;
// Jak długo zakończona bitwa zostaje dostępna pod swoim linkiem (do
// ponownego obejrzenia), zanim zniknie z pamięci serwera.
const FINISHED_LOBBY_TTL_MS = 60 * 60 * 1000;

async function steamUserFromSocket(socket, readUsers) {
  const sess = socket.request.session;
  const steamid = sess && sess.passport && sess.passport.user;
  if (!steamid) return null;
  let users;
  try {
    users = await readUsers();
  } catch (e) {
    return null; // magazyn chwilowo niedostępny - traktuj jak gościa, nic nie zapisujemy
  }
  const u = users[steamid];
  if (!u) return null;
  return { steamid: u.steamid, displayName: u.displayName, avatar: u.avatar };
}

// Trwała migawka zakończonej bitwy w Redisie (jeśli skonfigurowany), żeby
// zakładka "Moje bitwy" mogła ją odtworzyć jeszcze długo po tym, jak wypadnie
// z pamięci procesu (FINISHED_LOBBY_TTL_MS) albo serwer przejdzie redeploy.
// Bez Redisa (lokalny dev) replay dalej działa tylko w oknie FINISHED_LOBBY_TTL_MS.
const BATTLE_SNAPSHOT_PREFIX = "cs2sim:battle:";
const BATTLE_SNAPSHOT_TTL_SECONDS = 7 * 24 * 60 * 60;

// ---------------------------------------------------------------------------
// "Codzienne najlepsze bitwy" - TOP 20 zakończonych bitew z danego dnia
// (czasu polskiego), wg tego, jak mocno JEDEN gracz (nie bot, nie host-bez-
// -przeciwnika) pomnożył swój koszt wejścia w tej bitwie. Liczone raz, w
// chwili zakończenia bitwy (battle:finish) z już gotowego `results` - serwer
// i tak zna wszystko potrzebne w tym momencie, więc nie trzeba nic doliczać
// później ani skanować historii graczy. Trzymane w pamięci procesu (klucz =
// data) i, jeśli jest Redis, dodatkowo tam - żeby przetrwało redeploy w
// trakcie dnia, tak samo jak migawki pojedynczych bitew wyżej.
// ---------------------------------------------------------------------------
const DAILY_TOP_PREFIX = "cs2sim:dailytop:";
const DAILY_TOP_TTL_SECONDS = 3 * 24 * 60 * 60;
const DAILY_TOP_STORE_MAX = 100; // trzymaj zapas ponad pokazywane 20, gdyby kiedyś było widać więcej
const DAILY_TOP_SHOWN = 20;

module.exports = function attachBattleLobby(io, { readUsers, redisGet, redisSet, useRedis, warsawDateString }) {
  function persistFinishedLobby(lobby) {
    if (!useRedis) return;
    redisSet(BATTLE_SNAPSHOT_PREFIX + lobby.id, lobby, BATTLE_SNAPSHOT_TTL_SECONDS).catch((e) => {
      console.error(`Nie udało się zapisać migawki bitwy ${lobby.id} w Redisie:`, e.message);
    });
  }
  const dailyTopCache = {}; // dateStr -> posortowana, ucięta tablica wpisów
  async function getDailyTopList(dateStr) {
    if (dailyTopCache[dateStr]) return dailyTopCache[dateStr];
    let list = [];
    if (useRedis) {
      try {
        const stored = await redisGet(DAILY_TOP_PREFIX + dateStr);
        if (Array.isArray(stored)) list = stored;
      } catch (e) {
        console.error(`Nie udało się odczytać dziennej topki bitew (${dateStr}) z Redisa:`, e.message);
      }
    }
    dailyTopCache[dateStr] = list;
    return list;
  }
  // Wśród miejsc "host"/"player" (czyli realni gracze, nie boty i nie puste
  // miejsca) znajduje tego, kto najbardziej pomnożył koszt wejścia - to on
  // reprezentuje tę bitwę w codziennej topce.
  function bestNonBotResult(lobby) {
    let best = null;
    lobby.slots.forEach((slot, i) => {
      if (slot.type !== "host" && slot.type !== "player") return;
      const res = lobby.results && lobby.results[i];
      if (!res || typeof res.total !== "number" || !lobby.cost) return;
      const multiplier = res.total / lobby.cost;
      if (!best || multiplier > best.multiplier) {
        best = { multiplier, winAmount: res.total, playerName: slot.name, playerAvatar: slot.avatar || null };
      }
    });
    return best;
  }
  async function recordDailyTopBattle(lobby) {
    const best = bestNonBotResult(lobby);
    if (!best || !(best.winAmount > 0)) return;
    const finishedAt = lobby.finishedAt || Date.now();
    const dateStr = warsawDateString(finishedAt);
    const list = await getDailyTopList(dateStr);
    list.push({
      lobbyId: lobby.id,
      cost: lobby.cost,
      rounds: lobby.rounds,
      totalPlayers: lobby.totalPlayers,
      teams: !!lobby.teams,
      finishedAt,
      ...best,
    });
    list.sort((a, b) => b.multiplier - a.multiplier);
    if (list.length > DAILY_TOP_STORE_MAX) list.length = DAILY_TOP_STORE_MAX;
    dailyTopCache[dateStr] = list;
    if (useRedis) {
      redisSet(DAILY_TOP_PREFIX + dateStr, list, DAILY_TOP_TTL_SECONDS).catch((e) => {
        console.error(`Nie udało się zapisać dziennej topki bitew (${dateStr}) w Redisie:`, e.message);
      });
    }
  }
  const lobbies = {}; // id -> lobby
  const socketMeta = {}; // socket.id -> {lobbyId, tabId}
  const pendingFrees = {}; // `${lobbyId}:${tabId}` -> Timeout

  // "lobby" = poczekalnia (widoczna z przyciskiem Dołącz), "running" = bitwa
  // w toku (widoczna tylko do oglądania, bez wolnych miejsc) - obie trafiają
  // do publicznej listy, żeby "w trakcie" na stronie battle miało co pokazać.
  function publicLobbies() {
    return Object.values(lobbies).filter((l) => (l.status === "lobby" || l.status === "running") && !l.private);
  }
  function broadcastList() {
    io.emit("battle:lobbies", publicLobbies());
  }
  function broadcastLobby(lobby) {
    io.to("lobby:" + lobby.id).emit("battle:lobby", lobby);
    if ((lobby.status === "lobby" || lobby.status === "running") && !lobby.private) broadcastList();
  }
  function findSlotByTabId(lobby, tabId) {
    return lobby.slots.findIndex((s) => s.tabId === tabId);
  }
  function cancelPendingFree(lobbyId, tabId) {
    const key = lobbyId + ":" + tabId;
    if (pendingFrees[key]) {
      clearTimeout(pendingFrees[key]);
      delete pendingFrees[key];
    }
  }
  function schedulePendingFree(lobbyId, tabId) {
    const key = lobbyId + ":" + tabId;
    cancelPendingFree(lobbyId, tabId);
    pendingFrees[key] = setTimeout(() => {
      delete pendingFrees[key];
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.status !== "lobby") return;
      if (lobby.hostTabId === tabId) {
        delete lobbies[lobbyId];
        io.to("lobby:" + lobbyId).emit("battle:closed", { lobbyId });
        broadcastList();
        return;
      }
      const idx = findSlotByTabId(lobby, tabId);
      if (idx !== -1) {
        lobby.slots[idx] = { type: "empty" };
        broadcastLobby(lobby);
      }
    }, DISCONNECT_GRACE_MS);
  }

  io.on("connection", async (socket) => {
    const user = await steamUserFromSocket(socket, readUsers);
    socket.emit("battle:lobbies", publicLobbies());

    socket.on("battle:create", (cfg, ack) => {
      ack = typeof ack === "function" ? ack : () => {};
      if (!user) return ack({ ok: false, reason: "not_logged_in" });
      if (!cfg || typeof cfg !== "object") return ack({ ok: false });
      const tabId = String(cfg.tabId || "");
      const rounds = Array.isArray(cfg.rounds) ? cfg.rounds.slice(0, MAX_ROUNDS).map(String) : [];
      if (!tabId || rounds.length === 0) return ack({ ok: false });
      const totalPlayers = Math.max(2, Math.min(MAX_PLAYERS, Number(cfg.totalPlayers) || 2));
      const hostSlotIdx = Math.max(0, Math.min(totalPlayers - 1, Number(cfg.hostSlotIdx) || 0));
      const cost = Math.max(0, Math.min(MAX_COST, Number(cfg.cost) || 0));

      const id = "lobby-" + crypto.randomBytes(6).toString("hex");
      const hostName = user ? user.displayName : String(cfg.guestName || "Ty (Host)").slice(0, 18);
      const lobby = {
        id,
        hostTabId: tabId,
        rounds,
        mode: String(cfg.mode || "standard"),
        teams: !!cfg.teams,
        shared: !!cfg.shared,
        private: !!cfg.private,
        cost,
        totalPlayers,
        slots: Array.from({ length: totalPlayers }, (_, i) =>
          i === hostSlotIdx
            ? { type: "host", name: hostName, tabId, steamid: user ? user.steamid : null, avatar: user ? user.avatar : null }
            : { type: "empty" }
        ),
        status: "lobby",
        results: null,
        stakedTotal: cost,
        createdAt: Date.now(),
      };
      lobbies[id] = lobby;
      socket.join("lobby:" + id);
      socketMeta[socket.id] = { lobbyId: id, tabId };
      ack({ ok: true, lobby });
      broadcastLobby(lobby);
    });

    socket.on("battle:join", (payload, ack) => {
      ack = typeof ack === "function" ? ack : () => {};
      if (!user) return ack({ ok: false, reason: "not_logged_in" });
      const { lobbyId, idx, tabId, name } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.status !== "lobby") return ack({ ok: false, reason: "not_found" });
      if (!lobby.slots[idx] || lobby.slots[idx].type !== "empty") return ack({ ok: false, reason: "taken" });
      if (lobby.slots.some((s) => s.tabId === tabId)) return ack({ ok: false, reason: "already_in" });
      // Osobny tabId (nowa karta/zakładka) nie znaczy osobny gracz - bez tego
      // to samo konto Steam mogło zająć 2+ miejsc w swojej własnej bitwie,
      // grając samo przeciwko sobie (gwarantowana wygrana / podział puli).
      if (user && lobby.slots.some((s) => s.steamid === user.steamid)) return ack({ ok: false, reason: "already_seated" });
      const playerName = user ? user.displayName : String(name || "Gracz").slice(0, 18);
      lobby.slots[idx] = { type: "player", name: playerName, tabId: String(tabId || ""), steamid: user ? user.steamid : null, avatar: user ? user.avatar : null };
      lobby.stakedTotal = (lobby.stakedTotal || lobby.cost) + lobby.cost;
      socket.join("lobby:" + lobbyId);
      socketMeta[socket.id] = { lobbyId, tabId: String(tabId || "") };
      cancelPendingFree(lobbyId, tabId);
      ack({ ok: true, lobby });
      broadcastLobby(lobby);
    });

    socket.on("battle:resume", (payload, ack) => {
      ack = typeof ack === "function" ? ack : () => {};
      const { lobbyId, tabId } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || !lobby.slots.some((s) => s.tabId === tabId)) return ack({ ok: false });
      socket.join("lobby:" + lobbyId);
      socketMeta[socket.id] = { lobbyId, tabId };
      cancelPendingFree(lobbyId, tabId);
      ack({ ok: true, lobby });
    });

    // Odsłona bitwy pod jej bezpośrednim linkiem (/battle?lobby=<id>) -
    // niezależnie od tego, czy odwiedzający ma tam miejsce. Działa dla
    // lobby w dowolnym stanie (poczekalnia/w trakcie/zakończona) i dla
    // prywatnych bitew też - sama znajomość (nieodgadywalnego) ID wystarczy,
    // tak samo jak przy każdym linku do udostępnienia.
    socket.on("battle:getLobby", async (payload, ack) => {
      ack = typeof ack === "function" ? ack : () => {};
      const lobbyId = (payload || {}).lobbyId;
      let lobby = lobbies[lobbyId];
      if (!lobby && useRedis) {
        try {
          lobby = await redisGet(BATTLE_SNAPSHOT_PREFIX + lobbyId);
        } catch (e) {
          console.error(`Nie udało się odczytać migawki bitwy ${lobbyId} z Redisa:`, e.message);
        }
      }
      if (!lobby) return ack({ ok: false, reason: "not_found" });
      socket.join("lobby:" + lobby.id);
      ack({ ok: true, lobby });
    });

    socket.on("battle:addBot", (payload) => {
      const { lobbyId, idx, tabId } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.hostTabId !== tabId || lobby.status !== "lobby") return;
      if (!lobby.slots[idx] || lobby.slots[idx].type !== "empty") return;
      const used = lobby.slots.filter((s) => s.type === "bot").map((s) => s.name);
      const name = BOT_NAMES.find((n) => !used.includes(n)) || `Bot ${idx + 1}`;
      lobby.slots[idx] = { type: "bot", name, tabId: null };
      broadcastLobby(lobby);
    });

    socket.on("battle:submitResults", (payload) => {
      const { lobbyId, tabId, results } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.hostTabId !== tabId || lobby.status !== "lobby") return;
      if (lobby.slots.some((s) => s.type === "empty")) return;
      if (!Array.isArray(results) || results.length !== lobby.slots.length) return;
      lobby.results = results;
      lobby.status = "running";
      // Znacznik czasu, od którego każda przeglądarka (obecna od początku,
      // dołączająca w trakcie, albo oglądająca link ze spektatorem) liczy,
      // która runda właśnie leci - dzięki temu wszyscy widzą tę samą rundę
      // w tej samej chwili, zamiast każdy odtwarzał od rundy 1 od momentu,
      // gdy sam się połączył.
      lobby.startedAt = Date.now();
      broadcastLobby(lobby);
    });

    socket.on("battle:finish", (payload) => {
      const { lobbyId, tabId, outcome } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.hostTabId !== tabId) return;
      lobby.status = "finished";
      lobby.outcome = outcome || null;
      lobby.finishedAt = Date.now();
      broadcastLobby(lobby);
      broadcastList(); // usuń z publicznej listy "otwarte/w trakcie" - bitwa się skończyła
      persistFinishedLobby(lobby);
      recordDailyTopBattle(lobby).catch((e) => {
        console.error(`Nie udało się zapisać bitwy ${lobby.id} do codziennej topki:`, e.message);
      });
      setTimeout(() => {
        delete lobbies[lobbyId];
      }, FINISHED_LOBBY_TTL_MS);
    });

    // "Codzienne najlepsze bitwy" - TOP 20 na DZIŚ (czasu polskiego), zawsze
    // liczone na żywo z bieżącej daty w chwili zapytania, bez parametrów -
    // zakładka pokazuje wyłącznie dzisiejszy dzień.
    socket.on("battle:dailyTop", async (payload, ack) => {
      ack = typeof ack === "function" ? ack : () => {};
      const dateStr = warsawDateString(Date.now());
      const list = await getDailyTopList(dateStr);
      ack({ ok: true, date: dateStr, entries: list.slice(0, DAILY_TOP_SHOWN) });
    });

    socket.on("battle:leave", (payload) => {
      const { lobbyId, tabId } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby) return;
      if (lobby.hostTabId === tabId && lobby.status === "lobby") {
        delete lobbies[lobbyId];
        io.to("lobby:" + lobbyId).emit("battle:closed", { lobbyId });
        broadcastList();
        return;
      }
      const idx = findSlotByTabId(lobby, tabId);
      if (idx !== -1 && lobby.status === "lobby") {
        lobby.slots[idx] = { type: "empty" };
        broadcastLobby(lobby);
      }
    });

    socket.on("disconnect", () => {
      const meta = socketMeta[socket.id];
      delete socketMeta[socket.id];
      if (meta) schedulePendingFree(meta.lobbyId, meta.tabId);
    });
  });
};
