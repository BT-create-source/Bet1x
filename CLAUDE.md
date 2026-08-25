# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

"bet1x" is a multi-game prediction/casino web platform: color prediction (Sapre/Becone/Emred/VIP rooms),
an Aviator-style crash game, Teen Patti, Mines, plus a cashier (deposit/withdraw) and an admin dashboard
for house control. Two cricket games — "Your 11" fantasy cricket and "Boundary Baazi" ball-by-ball
prediction — are being **rebuilt** from scratch in `backend/lib/cricket/` against the Roanuz push
feed. An earlier pair of cricket games on the cricketdata.org API was fully removed first, so no code
is shared with them and nothing about that older integration still applies. The rebuild is additive
and gated behind `CRICKET_ENABLED`: with the flag off, not one route is mounted and not one timer
starts. See `docs/CRICKET-BUILD-BRIEF.md` (the live progress tracker), `docs/YOUR11-SCOPE.md` and
`docs/PHASE0-AUDIT.md`.

Inside `backend/lib/cricket/`, one shared pipeline feeds both games: `collector.js` (the single push
webhook) writes every ball to a permanent append-only log (`event-log.js`), and `live-state.js`
rebuilds all derived state by **recomputing from the whole log on every update**, never by
incrementing — which is what makes a dropped or out-of-order delivery harmless. On top of that sit
`contests.js` + `scoring.js` + `credits.js` + `house-entry.js` (Your 11) and `boundary.js` (Boundary
Baazi). Every tunable number lives in `config-store.js` and reports as awaiting client sign-off until
an operator edits it. Only Your 11 has a house-edge path, keyed `youreleven`; Boundary Baazi is a
parimutuel pool with no house position, so it has no rig path at all and `test_rigging.js` asserts
that positively.
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
- **Tests**: three standalone suites, no Jest/Mocha. The first two boot the Express app in-process on
  an ephemeral port and drive it over real HTTP, writing to whatever `DATABASE_URL` points at (use a
  development database); the third needs neither a server nor a database.
  - `npm test` (`backend/test_backend.js`) — security/regression: token forgery, cross-account wallet
    access, unauthenticated admin and rig endpoints, double-spend races, and static exposure of
    `.env`/`.git`/PHP source. Fast.
  - `npm run test:e2e` (`backend/test_e2e.js`) — the product itself: a full player journey through
    every game, checking the wallet arithmetic after each step, plus the deposit/withdrawal approval
    lifecycle. It waits on the real game loops (30s colour rounds, Aviator's flight), so budget about
    two minutes.
  - `npm run test:rig` (`backend/test_rigging.js`) — the house-edge engine: asserts that a configured
    percentage actually means that proportion, in the right unit for each game (live tables for Teen
    Patti, per-room cycles for Colour Prediction, rounds for Aviator, live players for Mines), that no
    room/table/player is starved or favoured, and that disabling the bot stops everything. Drives the
    engine functions directly via `require('./server.js')._houseEdgeInternals`, so it runs in about a
    second. This is the suite that would have caught the historical "configured 50% behaved like 80%"
    bugs; add a case here before changing anything in the takeover engine.
  - `npm run test:cricket` (`backend/test_cricket.js`) — the cricket pipeline and the Your 11 money
    maths. Needs neither a server nor a database: it drives the modules directly against an
    in-memory store. Asserts the guarantee the whole design rests on (a shuffled, duplicated event
    stream produces byte-identical state), extras/wicket-attribution arithmetic, webhook signature
    rejection, the 90-second stall detector, and — for contests — prize-table validation, lineup
    legality, tie-aware ranking, and that allocated prizes always total exactly the pool.
  - `npm run test:cricket:e2e` (`backend/test_cricket_e2e.js`) — both cricket games played over real
    HTTP against a real Express app, wallet and database, with `CRICKET_ENABLED` forced on and a
    simulated push feed hitting the actual (unsigned, dev-mode) webhook route — the same kind of
    full-journey check `test_e2e.js` does for the other games, which didn't exist for cricket before
    2026-08-24. Covers the confirmed-XI auto-substitution end to end (two independent entries, one
    of them budget-constrained), the Boundary Baazi lock/resolve cycle including a wide colliding
    with its own legal re-bowl, the innings-break transition, the reconciliation hold on
    `match_end` and an operator's forced settle past it, and settlement idempotency — reconciling
    every wallet balance to the paisa throughout. Also proves the OTHER Roanuz delivery shape (a
    real gzip-compressed full-match snapshot, redelivered to check idempotency) over the real
    webhook route. Needs a real dev database; run it before trusting a change to either game's
    money path.
  - `node backend/cricket_validate.js --demo` — a self-verifying demo of the Section 9 parallel-run
    validation harness (`backend/lib/cricket/parallel-validation.js`), built before there was any
    live match to point it at. `node backend/cricket_validate.js <fixtureKey>` runs it for real once
    a fixture has recorded ball events and an `official_scorecard`.
- **Cricket has no paid Roanuz access yet, so the whole pipeline is built to make that a non-issue.**
  `backend/lib/cricket/roanuz.js` never calls `fetch` directly — every call goes through
  `roanuz-transport.js`'s `HttpTransport` (real network) or `MockTransport` (canned,
  documentation-shaped responses, no network). `config.ROANUZ_TRANSPORT` picks between them and, left
  unset, resolves itself from whether `ROANUZ_API_TOKEN`/`ROANUZ_PROJECT_KEY` are set — dropping in
  real credentials is the entire flip from mock to live. `snapshot-adapter.js` similarly adapts
  Roanuz's documented full-match-state push payload into the discrete per-ball events the rest of
  the pipeline already expects, at the boundary, so nothing downstream needed to change. See the
  2026-08-24 amendments in `docs/CRICKET-BUILD-BRIEF.md` for what's confirmed against Roanuz's real
  docs vs. still a documented best guess, and that file's Section 12 for the exact go-live checklist.
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
profit percentage. A game key absent from `botTakeoverState` is never active — not even under the
global switch — which is both what stops a URL-supplied `:gameKey` typo drawing real rig decisions
and what guarantees Boundary Baazi has no rig path. The percentage is applied in whichever unit is actually concurrent for that game, which
differs per game and is the thing to get right when touching any of this:
- **Aviator** — one global round and one tick loop, so there are no concurrent instances; the percentage
  applies to successive rounds via `shouldBotRigThisRound('aviator')`, a bucketed 100-slot bag (not RNG)
  that is exact over every cycle and avoids local clustering. When a round is rigged,
  `pickAviatorCrashPoint` sets the crash point from the stake actually exposed, and an in-flight erosion
  check crashes the moment a cash-out starts eating the round's profit. Both can only lower a crash point,
  never raise one, and `AVIATOR_SMART_CRASH=false` reverts to the original fixed random band.
- **Colour Prediction** — four rooms on 30s/60s/180s/300s clocks, each drawing from its **own** 100-slot
  cycle (`shouldBotRigThisRound('color_guess', 'color_guess:<room>')`), so the fast room cannot consume the
  slow room's rigged slots. `calculateColorOptimalOutcome` then picks the genuinely max-profit number.
- **Teen Patti** — six concurrent tables, so the percentage means *tables*: `refreshInstanceTargeting`
  selects P% of the tables that currently have a real player, and being selected **is** the rig decision.
  There is deliberately no second probability roll and no separate room-arming pass — stacking two
  percentage mechanisms here is what once turned a configured 50% into "8 of 10 games".
- **Mines** — one board per player, so a live player *is* a live game; `refreshBotTargeting` picks P% of
  live players and every reveal by a targeted player busts.

`GET /api/admin/rig-audit` (admin-only) reports observed-vs-configured percentages per game and per room
from `backend/lib/rig-audit.js`, which records every decision as it happens. Aviator's crash point, Color Prediction's
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
