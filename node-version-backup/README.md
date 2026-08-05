# Kopia zapasowa wersji Node.js

Pełna migawka strony na dzień, w którym zaczęto migrację backendu na PHP —
działający Express + Socket.IO + Steam OAuth, dokładnie taki, jaki był
wdrożony na Render tuż przed przejściem na PHP.

## Jak wrócić do tej wersji

1. Skopiuj całą zawartość tego folderu do głównego katalogu repo (nadpisując
   pliki PHP).
2. `npm install`
3. Ustaw zmienne środowiskowe jak w `.env.example` (`STEAM_API_KEY`,
   `SESSION_SECRET`, `SITE_URL`, `PORT`, `ADMIN_STEAMID`, opcjonalnie
   `UPSTASH_REDIS_REST_URL` / `UPSTASH_REDIS_REST_TOKEN`).
4. `npm start` (albo wdróż z powrotem na Render/Railway/Fly.io - wymaga
   platformy, która utrzymuje stały proces Node.js, patrz `render.yaml`).

Ten folder nie jest importowany przez żaden plik PHP - jest tu wyłącznie
jako punkt powrotu, na wypadek gdyby migracja na PHP się nie sprawdziła.
