/* =========================================================================
   ZAMIENNIK SOCKET.IO - AJAX POLLING (wersja PHP, bez WebSocketów)
   -------------------------------------------------------------------------
   PHP na zwykłym hostingu współdzielonym nie utrzymuje stałego procesu ani
   WebSocketów, więc Case Battle w czasie rzeczywistym działa tu przez
   krótkie odpytywanie AJAX zamiast Socket.IO. Ten plik odtwarza DOKŁADNIE
   tę samą, minimalną powierzchnię API (`socket.on(event, cb)` /
   `socket.emit(event, payload, ack)`), której battle.html faktycznie
   używa - dzięki temu cała reszta pliku (renderowanie UI, animacje,
   losowanie skrzynek, cała logika gry) zostaje NIETKNIĘTA, zmienia się
   tylko transport na samym dole.
   ========================================================================= */
const socket = (function () {
  const listeners = {};
  let lobbyPollTimer = null;
  let listPollTimer = null;
  let polledLobbyId = null;
  let polledTabId = null;
  let lastLobbyJson = null;
  let lastListJson = null;

  function on(event, cb) {
    (listeners[event] = listeners[event] || []).push(cb);
  }
  function fire(event, ...args) {
    (listeners[event] || []).forEach((cb) => {
      try { cb(...args); } catch (e) { console.error('[battle-poll] listener błąd:', event, e); }
    });
  }

  const ENDPOINTS = {
    'battle:create': '/api/battle-create.php',
    'battle:join': '/api/battle-join.php',
    'battle:leave': '/api/battle-leave.php',
    'battle:resume': '/api/battle-resume.php',
    'battle:getLobby': '/api/battle-get-lobby.php',
    'battle:addBot': '/api/battle-add-bot.php',
    'battle:submitResults': '/api/battle-submit-results.php',
    'battle:finish': '/api/battle-finish.php',
    'battle:dailyTop': '/api/battle-daily-top.php',
  };

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {}),
    });
    return res.json();
  }

  function emit(event, payload, ack) {
    const url = ENDPOINTS[event];
    if (!url) { console.error('[battle-poll] nieznane zdarzenie:', event); return; }
    // battle-get-lobby.php jest GET-owy (ta sama ścieżka co ciągłe
    // odpytywanie w pollLobbyOnce, patrz niżej) - jednorazowe wywołanie
    // przez battle:getLobby (np. podgląd cudzej bitwy z linku) musi więc
    // iść jako GET z query stringiem, nie POST z JSON-em jak reszta zdarzeń.
    const request = event === 'battle:getLobby'
      ? fetch(`${url}?${new URLSearchParams({ id: (payload && payload.lobbyId) || '' })}`, { credentials: 'same-origin' }).then((r) => r.json())
      : postJson(url, payload);
    request.then((res) => {
      if (typeof ack === 'function') ack(res);
      if (event === 'battle:leave') { stopLobbyPolling(); return; }
      if (res && res.ok && res.lobby) {
        startLobbyPolling(res.lobby.id, (payload && payload.tabId) || null);
      }
    }).catch((e) => {
      console.error('[battle-poll] emit błąd:', event, e);
      if (typeof ack === 'function') ack({ ok: false, reason: 'network_error' });
    });
  }

  function startLobbyPolling(lobbyId, tabId) {
    if (polledLobbyId === lobbyId) {
      if (tabId) polledTabId = tabId;
      return;
    }
    stopLobbyPolling();
    polledLobbyId = lobbyId;
    polledTabId = tabId || null;
    lastLobbyJson = null;
    pollLobbyOnce();
  }
  function stopLobbyPolling() {
    if (lobbyPollTimer) clearTimeout(lobbyPollTimer);
    lobbyPollTimer = null;
    polledLobbyId = null;
    polledTabId = null;
  }
  async function pollLobbyOnce() {
    const id = polledLobbyId;
    if (!id) return;
    try {
      const qs = new URLSearchParams({ id });
      if (polledTabId) qs.set('tabId', polledTabId);
      const res = await fetch(`/api/battle-get-lobby.php?${qs}`, { credentials: 'same-origin' });
      const data = await res.json();
      if (polledLobbyId !== id) return; // w międzyczasie przełączyliśmy się gdzie indziej
      if (!data.ok) {
        fire('battle:closed', { lobbyId: id });
        stopLobbyPolling();
        return;
      }
      const json = JSON.stringify(data.lobby);
      if (json !== lastLobbyJson) {
        lastLobbyJson = json;
        fire('battle:lobby', data.lobby);
      }
    } catch (e) {
      // przejściowy błąd sieci - po prostu spróbuj przy kolejnym pollu
    }
    if (polledLobbyId === id) lobbyPollTimer = setTimeout(pollLobbyOnce, 1500);
  }

  async function pollListOnce() {
    try {
      const res = await fetch('/api/battle-lobbies.php', { credentials: 'same-origin' });
      const list = await res.json();
      const json = JSON.stringify(list);
      if (json !== lastListJson) {
        lastListJson = json;
        fire('battle:lobbies', list);
      }
    } catch (e) {
      // spróbuj przy kolejnym pollu
    }
    listPollTimer = setTimeout(pollListOnce, 3000);
  }

  pollListOnce();
  // "connect" w Socket.IO oznaczał żywe połączenie - przy pollingu nie ma
  // realnego uzgadniania, więc odpalamy to raz, od razu po starcie strony
  // (asynchronicznie, żeby reszta battle.html zdążyła się najpierw podpiąć
  // przez socket.on("connect", ...) zanim to wystrzeli).
  setTimeout(() => fire('connect'), 0);

  return { on, emit };
})();
