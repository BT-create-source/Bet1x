# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

"bet1x" is a multi-game prediction/casino web platform: color prediction (Sapre/Becone/Emred/VIP rooms),
an Aviator-style crash game, Teen Patti, Mines, plus a cashier (deposit/withdraw) and an admin dashboard
for house control. (The platform previously also included two cricket games — "Your Eleven" fantasy
cricket and "Boundary Baazi" cricket betting, wired to the cricketdata.org live match API — but both were
fully removed, including their backend routes, admin rigging consoles, and cricketdata.org integration.)
The `README.md` describes an earlier "pitch demo, no backend" mode (pure HTML/CSS/JS with localStorage);
the repo has since grown a real backend, so treat the README's "no backend" framing as historical/optional
rather than how the app currently runs.

## Commands

- **Run the main backend** (from repo root, any of these are equivalent — all just run `node backend/server.js`):
  `npm run dev` / `npm start` / `npm run backend`. Listens on `PORT` from `backend/.env` (default `5000`)
  and also serves the entire repo root as static files, so once it's running the whole site is reachable at
  `http://localhost:5000/` (e.g. `http://localhost:5000/index.html`).
- **Install backend deps**: `cd backend && npm install`.
- **Prisma** (schema at `backend/prisma/schema.prisma`, needs `DATABASE_URL` in `backend/.env`):
  `cd backend && npx prisma generate`, `npx prisma migrate dev`, `npx prisma studio`. The server boots and
  runs fine even without a reachable Postgres — see the JSON-fallback note below.
- **Tests**: two standalone suites, no Jest/Mocha — each boots the Express app in-process on an
  ephemeral port and drives it over real HTTP, writing to whatever `DATABASE_URL` points at (use a
  development database).
  - `npm test` (`backend/test_backend.js`) — security/regression: token forgery, cross-account wallet
    access, unauthenticated admin and rig endpoints, double-spend races, and static exposure of
    `.env`/`.git`/PHP source. Fast.
  - `npm run test:e2e` (`backend/test_e2e.js`) — the product itself: a full player journey through
    every game, checking the wallet arithmetic after each step, plus the deposit/withdrawal approval
    lifecycle. It waits on the real game loops (30s colour rounds, Aviator's flight), so budget about
    two minutes.
- No bundler/build step for the frontend — pages are plain HTML/CSS/JS served as-is.

## Architecture

**Static multi-page frontend, one file per surface.** Each game/page is a self-contained HTML file at the
repo root (`index.html` landing page, `aviator.html`, `teenpatti.html`, `mining.html`, `cashier.html`,
`admin.html`, `parity.html` admin monitor, and `win.html`/`win1.html`/`win2.html`/`win3.html` for the four
color-prediction rooms). They share
`assets/css/style.css` (all styling/design tokens), `assets/js/ui-common.js` (auth, wallet, server-clock
sync, and shared UI helpers), `assets/js/dummy-data.js` (fake data generators, a holdover from the original
pitch-demo mode), and `assets/js/sound-fx.js` (see below).

**`assets/js/sound-fx.js` is a self-contained sound engine — every effect is synthesized live via the Web
Audio API, so there are no audio files to manage.** It's included via a `<script>` tag right after
`ui-common.js` on every player-facing page and auto-injects its own floating mute toggle (persisted to
`localStorage['bet1x_sound_muted']`), plus a generic delegated click/hover listener so ordinary buttons get
feedback with zero per-page wiring. Individual games call `SoundFX.play('win' | 'lose' | 'betPlace' |
'cardDeal' | 'mineHit' | 'gemReveal' | 'cashout' | 'tick' | 'tickUrgent' | ...)` at their own win/loss/deal/
reveal moments — see the `SOUNDS` map in the file for the full list. `aviator.html` predates this file and
has its own bespoke sound system (engine hum pitched to the multiplier, its own `playSound()`/`AudioContext`)
that's deliberately left alone rather than merged in; `sound-fx.js` detects any page defining `window.playSound`
and skips its own generic auto-click layer there to avoid double-firing, while both systems still share the
one mute flag.

**The fetch interceptor is the key thing to understand before touching any frontend or API code.**
`ui-common.js` installs a global `window.fetch` override: any request whose URL contains `api/` or `.php` is
rewritten to `http://localhost:5000/api/...`, with the logged-in username and a Bearer token injected
automatically. Because of this, PHP-shaped calls scattered through the frontend (e.g.
`api/wallet.php?action=adjust`, `api/auth.php?action=login`) never hit PHP — they're transparently redirected
to the Express backend, which implements matching PHP-style routes purely for URL compatibility with that
older frontend code.

**`backend/server.js` is the real runtime and source of truth for game logic** — one monolithic Express app
covering auth, wallet, chat, admin stats, and full per-game engines: an Aviator crash-point
tick loop (`setInterval(tickAviator, 100)`), Color Prediction round settlement, a Teen Patti table/seat state
machine, and Mines. It uses Prisma/PostgreSQL
as the primary store, but nearly every DB call is wrapped in try/catch that falls back to flat JSON files in
`backend/data/*.json` (`readJsonTable`/`writeJsonTable`) if Prisma/Postgres is unreachable — so the app is
usable with zero database setup.

**House-edge "bot takeover" system**: `botTakeoverState` holds per-game (and one global) admin toggles with a
profit percentage; `shouldBotRigThisRound(gameKey)` uses a deterministic per-game round counter (not RNG) so
that, e.g., a 90% setting rigs exactly 9 of every 10 rounds. Aviator's crash point, Color Prediction's
winning number, and Teen Patti's winning seat all consult this before resolving a round. `admin.html` is the
control surface for these toggles/overrides, exposed via `/api/bot_status/:gameKey` and
`/api/bot_decide/:gameKey`, and per-game admin rig endpoints (e.g. `/api/mines/admin/rig`,
`/api/teenpatti/admin/rig`).

**The per-game folders (`aviator/`, `mining/`, `teenpati/`)
and root `api/*.php` are an older, parallel PHP implementation**, self-described in their own docblocks as
"Learning build" / demo versions. `backend/api/*.php` largely mirrors them, and some of the folder-level
files are thin forwarders (e.g. `mining/api.php` just does `require_once .../backend/api/mining.php`). Since
the frontend fetch interceptor always redirects to the Node backend, this PHP layer is not on the live code
path from the actual site — treat `backend/server.js` as the source of truth for gameplay/wallet logic unless
a task specifically calls for working on the PHP layer.

**Data model** (`backend/prisma/schema.prisma`): `User`, `Transaction`, `Deposit`, `Withdrawal`, `PaymentLog`,
`GameState` (generic `key`/JSON blob, used for per-room game state and the bot-takeover config),
`RecentResult`, `ChatMessage`, `TeenPattiRoom`/`TeenPattiSeat`, `GameBet`. The JSON fallback in
`backend/data/*.json` mirrors these tables one file per model (`users.json`, `transactions.json`,
`deposits.json`, `withdrawals.json`, `game_bets.json`, `chat.json`).

## Repository quirks to be aware of

- There is no `.gitignore` — `backend/node_modules/` (thousands of files) and `backend/.env`
  (containing `DATABASE_URL` and API keys) are committed to git. Don't assume these
  are safe to treat as secret, and avoid broad `git add -A`-style commits that sweep up more of
  `node_modules`.
