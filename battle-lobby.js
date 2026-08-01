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
const FINISHED_LOBBY_TTL_MS = 5 * 60 * 1000;

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

module.exports = function attachBattleLobby(io, { readUsers }) {
  const lobbies = {}; // id -> lobby
  const socketMeta = {}; // socket.id -> {lobbyId, tabId}
  const pendingFrees = {}; // `${lobbyId}:${tabId}` -> Timeout

  function publicLobbies() {
    return Object.values(lobbies).filter((l) => l.status === "lobby" && !l.private);
  }
  function broadcastList() {
    io.emit("battle:lobbies", publicLobbies());
  }
  function broadcastLobby(lobby) {
    io.to("lobby:" + lobby.id).emit("battle:lobby", lobby);
    if (lobby.status === "lobby" && !lobby.private) broadcastList();
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
      const { lobbyId, idx, tabId, name } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.status !== "lobby") return ack({ ok: false, reason: "not_found" });
      if (!lobby.slots[idx] || lobby.slots[idx].type !== "empty") return ack({ ok: false, reason: "taken" });
      if (lobby.slots.some((s) => s.tabId === tabId)) return ack({ ok: false, reason: "already_in" });
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
      broadcastLobby(lobby);
    });

    socket.on("battle:finish", (payload) => {
      const { lobbyId, tabId, outcome } = payload || {};
      const lobby = lobbies[lobbyId];
      if (!lobby || lobby.hostTabId !== tabId) return;
      lobby.status = "finished";
      lobby.outcome = outcome || null;
      broadcastLobby(lobby);
      setTimeout(() => {
        delete lobbies[lobbyId];
      }, FINISHED_LOBBY_TTL_MS);
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
