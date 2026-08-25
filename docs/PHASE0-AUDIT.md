# Phase 0 Audit — Cricket Games Integration (Boundary Baazi + Your 11)

Status: **complete. Phase 1 is blocked on four decisions in §11.**
Date: 2026-08-23 · Repo: `Wormhole BWA59na` (bet1x) · Branch `master` @ `4ffce56`

Nothing in the repository has been modified for this audit. Baseline check before starting:
`npm run test:rig` → **96 passed, 0 failed**.

---

## 1. Frontend: framework, routing, state

There is **no framework, no bundler, no build step**. The frontend is plain HTML/CSS/JS.

- **Routing** is the filesystem. One self-contained `.html` file per surface at the repo root:
  `index.html`, `aviator.html`, `teenpatti.html`, `mining.html`, `cashier.html`,
  `win.html`/`win1.html`/`win2.html`/`win3.html` (the four colour rooms), plus `admin.html`,
  `superadmin.html`, `parity.html`. Navigation is `<a href="aviator.html">`.
- **State** is `localStorage` + module-scoped globals inside each page's inline `<script>`.
  There is no shared store. Keys in use: `bet1x_auth_token`, `bet1x_admin_token`,
  `bet1x_current_user`, `bet1x_wallet`, `bet1x_sound_muted`.
- **Shared code** is three script tags, always in this order:
  `assets/js/dummy-data.js` → `assets/js/ui-common.js` → `assets/js/sound-fx.js`.

**Consequence for this build:** a cricket game is `boundarybaazi.html` and `youreleven.html` at the
repo root, each a single file, each including the same three scripts in the same order. Do not
introduce React, a router, or a build step for these two pages — that would be a new toolchain the
rest of the site does not have.

### The fetch interceptor (read before writing any frontend call)

`assets/js/ui-common.js:34` replaces `window.fetch` globally. Any URL containing `api/` or `.php` is
rewritten onto `window.BET1X_API_BASE` (the page's own origin) and has the session token attached as
`Authorization: Bearer …`, read from `bet1x_auth_token` — or `bet1x_admin_token` when the page sets
`window.BET1X_ADMIN_CONSOLE`.

Two things follow:

1. New pages get auth for free. Just `fetch('api/cricket/…')`.
2. Any client-side call to a URL containing `api/` is captured and pointed at our own backend.
   A browser therefore **cannot** reach Roanuz directly even by accident — which happens to enforce
   Ground Rule 4 for free, and is worth keeping rather than working around.

---

## 2. Reference pattern: how an existing game works end-to-end

**Mines** is the cleanest reference (single-player, no long-lived table state) — use it as the
template. Teen Patti is the closest structural analogue for anything with rooms/seats.

| Layer | Where | Shape |
|---|---|---|
| Page | `mining.html` | one file, inline `<script>`, no polling loop |
| Transport | global `fetch` override | POST JSON to `api/mines/*` |
| Routes | `backend/server.js:4613–5163` | `/state`, `/start`, `/reveal`, `/cashout`, `/admin/rig` |
| Money in | `debitWallet(user.id, bet)` (`server.js:176`) | conditional `updateMany` — atomic, no double-spend |
| Ledger | `prisma.transaction.create({ id: newRecordId('MINES'), … })` | written **immediately after** the debit; if the ledger write throws, the debit is explicitly reversed (`server.js:4700–4726`) |
| Money out | `creditWallet(user.id, payout)` (`server.js:187`) | |
| Bet record | `GameBet` row | `{ username, game, bet_amount, payout, status, metadata }` |
| Session state | in-memory map `MINES_USER_SESSIONS` | claimed **synchronously before the first `await`** to close a double-click race |

Three conventions from `mines/start` that any new money-moving endpoint must copy, because each one
exists as a fix for a bug that actually cost money in this codebase:

- **Validate before debiting.** `validateStake()` (`server.js:196`) enforces `MIN_BET`/`MAX_BET`.
- **Debit atomically.** Never read-check-write. `debitWallet` returns `null` on insufficient funds.
- **Reverse the debit if the ledger write fails**, and log loudly if the reversal also fails.
- **Use `newRecordId(prefix)`** (`server.js:165`) for every ledger id. Never `Date.now()` alone —
  the comment there documents ₹400 destroyed in load testing by exactly that.

---

## 3. Backend: framework, API style, route organisation

- **Express 4** (`backend/package.json`), one monolithic file: **`backend/server.js`, 5,832 lines**.
  Entry: `npm run dev` / `start` / `backend` — all `node backend/server.js`.
- **REST-ish**, JSON bodies, no GraphQL, no router modules. Every route is `app.get`/`app.post`
  registered directly on the app, grouped by banner comment (`// MINES`, `// TEEN PATTI`, …).
- Middleware order (`server.js:247–341`): helmet → compression → cors → `express.json({limit:'256kb'})`
  → `auth.attachSession` → `requestLogger` → `apiLimiter` on `/api/`.
- Same process **also serves the whole repo root as static files** (`server.js:5722`), so the site is
  at `http://localhost:5000/`. Unmatched `/api/*` returns JSON 404; everything else falls back to
  `index.html`.
- Extracted modules are few and recent: `backend/lib/auth.js`, `lib/logger.js`, `lib/rig-audit.js`,
  and `backend/config.js`.

**Consequence:** the shared cricket pipeline should be **new files under `backend/lib/`**
(collector, event log, live-state builder, scoring), with only thin route registrations added to
`server.js`. That keeps Ground Rule 2 — nothing existing is refactored — while not adding another
thousand lines to an already 5.8k-line file.

---

## 4. Database and ORM

- **Prisma 5.22 + PostgreSQL**, schema at `backend/prisma/schema.prisma` (105 lines).
- Models: `User`, `Transaction`, `Deposit`, `Withdrawal`, `PaymentLog`, `GameState`,
  `RecentResult`, `ChatMessage`, `TeenPattiRoom`, `TeenPattiSeat`, `GameBet`.
- **There is no `prisma/migrations/` directory.** The schema has been applied with `db push`, not
  migrations — but root `package.json` maps `prisma:migrate` to `migrate deploy`, which fails
  against a project with no migrations. Adding cricket tables forces a choice; see §11.
- **Two schema idioms are already in use for game state**, and they are the right precedents:
  - **`GameState`** — generic `key` (String, PK) + `data` (Json). Used for per-room colour state and
    the bot-takeover config. Good for singleton/config blobs (scoring rules, credit weights).
  - **Dedicated tables** — `TeenPattiRoom`/`TeenPattiSeat` with real columns and indexes. Right for
    anything queried by more than its key: the ball event log, fixtures, squads, credits, entries.

### The JSON fallback, and why the event log must not use it

Nearly every DB call is wrapped in `try/catch` falling back to flat files in `backend/data/*.json`
via `readJsonTable`/`writeJsonTable` (`server.js:51–74`), gated by `ALLOW_JSON_FALLBACK`
(default: on in dev, off in production).

`writeJsonTable` **serialises and rewrites the entire file on every write**. That is fine for the
existing tables. It is wrong for a permanently-growing, append-only ball event log — the one thing
Section 4 of the brief says must be persisted forever and never re-fetched. Recommendation: the
cricket event log is **Postgres-only**, guarded by the existing `requireDatabase` middleware
(`server.js:347`), and returns an honest 503 rather than silently writing a second source of truth.

---

## 5. Real-time delivery — **none exists**

Searched the entire repo for `WebSocket`, `socket.io`, `EventSource`, `text/event-stream`, SSE.
**Zero hits.** There is no `ws` or `socket.io` dependency in `backend/package.json`.

Every "live" surface is **client-side `setInterval` polling**:

| Page | Cadence |
|---|---|
| `aviator.html:951` | 250 ms |
| `win*.html:539` | 300 ms |
| `teenpatti.html:754` | 2 s |
| `index.html:316` (chat) | 3 s |
| `parity.html`, `superadmin.html` | 6 s |

`apiLimiter` is 600 req/min per IP and is **skipped entirely in development** precisely because
these loops are so noisy (`server.js:329`).

**This is the single largest gap between the brief and the codebase.** Section 5.5 assumes an
existing fan-out layer to reuse; there isn't one, and Section 9 asks for it to be load-tested at
marquee-match concurrency. Ball-by-ball prediction on a 250 ms poll would be both a poor experience
and a serious load problem at scale.

**Recommendation: build one SSE endpoint** (`GET /api/cricket/stream/:matchKey`, `text/event-stream`)
as part of the shared core. SSE, not WebSocket, because: the data flow is strictly server→client;
it needs no new dependency (plain `res.write` on Express); it survives the existing reverse-proxy
setup; and it degrades to the polling pattern the rest of the site already uses if a client can't
hold the connection. This is **additive** — no existing page changes.

---

## 6. Accounts, auth, wallet — **exists and must be reused.** Contests — **do not exist.**

### What exists (reuse it)

- **Accounts:** `User { id, username, email, password (bcrypt), wallet_balance }`.
  Signup/login at `/api/auth/*` and the PHP-shaped `/api/auth.php`.
- **Auth:** `backend/lib/auth.js`. HMAC-SHA256-signed tokens (`v1.<payload>.<sig>`), verified in
  constant time, with `role: 'user' | 'admin'`. Guards: `requireAuth`, `requireAdmin`.
  **`auth.actingUsername(req)` is the only trustworthy username on a money-moving request** — a
  client-supplied `username` field is ignored for players (admins may act on behalf of a user).
- **Wallet ledger:** `User.wallet_balance` as the balance, `Transaction` as the append-only ledger
  (`type: 'Deposit' | 'Withdrawal'`, `details` string, `status`). `debitWallet`/`creditWallet` are
  atomic. `GameBet` records per-bet outcomes.
- **Cashier:** deposit/withdrawal request → operator approval in `admin.html` → credit/debit.

So the brief's hard stop ("if no wallet/currency infrastructure exists, stop") is **not** triggered.
Cricket entry fees and payouts go through `debitWallet`/`creditWallet` + a `Transaction` row, exactly
like Mines, and appear in the existing admin stats automatically — `classifyGameplayTransaction`
(`server.js:1759`) routes a transaction to a game by matching its `details` string, so the
`details` wording is load-bearing, not cosmetic.

### What does **not** exist (this is the gap)

**There is no contest, entry-pool, prize-distribution, or deferred-settlement concept anywhere in
the codebase.** Every existing game is single-player against the house, settled within seconds, with
one wallet debit and at most one wallet credit.

Your 11 is structurally different: N users pay into a shared pool, results are unknown for hours,
one settlement pass pays a ranked prize breakdown to many users at once, and it must be idempotent.
None of that has a precedent to copy here.

Per the brief's own instruction that settlement logic "deserves its own explicit scoping, not an
improvised addition on the side of a data-integration task" — **this is flagged, not improvised.**
See §11.

---

## 7. Design system

`assets/css/style.css`, CSS custom properties on `:root`. Dark, navy-and-gold casino palette.

```
--bg #0b0e1d   --bg-soft #10142a   --surface #141833   --border #262b4a
--text #edeffa  --text-dim #8790b8  --text-faint #5b628a
--green #2ed47a  --red #ff4b6e  --violet #a56bff  --gold #ffc53d   (+ *-soft 12% alpha variants)
--font-display 'Space Grotesk'   --font-body 'Inter'   --font-mono 'JetBrains Mono'
--radius-sm 10px  --radius 16px  --radius-lg 22px   --shadow-card 0 8px 30px rgba(0,0,0,.35)
--max-width 1180px
```

Existing primitives to reuse rather than reinvent: `.game-card-item` / `.game-card-img` /
`.game-card-title` (the home grid), `.sub-navbar-item` / `.sub-navbar-link`,
`.mobile-bottom-nav-item`, `.exchange-menu-item`, `.footer` (`index.html:189`).

There is **no leaderboard component anywhere on the site** — Your 11 needs one built from scratch;
base it on the existing card + table styling in `admin.html`. "Live" states are conveyed with
`--green`/`--red` accents and the `*-soft` background tints.

Adding two games means adding entries in **four** navigation surfaces in `index.html`: the
sub-navbar (`:61`), the game-card grid (`:100`), the exchange menu (`:148`, duplicated at `:437`),
and the mobile bottom nav (`:385`).

**Sound:** `assets/js/sound-fx.js` synthesises everything via Web Audio — no audio files. Include the
script and call `SoundFX.play('win' | 'lose' | 'betPlace' | …)`; generic button feedback is
automatic.

---

## 8. Config and secrets

`backend/config.js` (269 lines) is the **single** place `process.env` is read. It validates at boot
and **`process.exit(1)`s in production** if a security-critical value is missing or is a dev
placeholder. `backend/.env` is loaded via dotenv; `backend/.env.example` documents every key with
prose.

Third-party keys follow one pattern: read in `config.js` → validated → exported on the config object
→ documented in `.env.example`. **No key is ever read directly in `server.js`.**

New keys to add, following that pattern exactly:

```
ROANUZ_API_TOKEN=          # REQUIRED in production once cricket is enabled
ROANUZ_PROJECT_KEY=
ROANUZ_WEBHOOK_SECRET=     # verifies the push feed's signature
ROANUZ_TOURNAMENT_IDS=     # comma-separated; config.js already has a list() helper
CRICKET_ENABLED=false      # the Section 9 feature flag
```

Two notes:

- `backend/.env` **is committed to git history** (`DEPLOYMENT.md` §1 documents this and instructs
  rotation). `.gitignore` now exists (52 lines) and stops new commits, but treat anything already in
  history as public. **Do not put the Roanuz token in a committed file.**
- `CLAUDE.md` currently states there is no `.gitignore`. That is stale — one was added in `4ffce56`.

**Outbound HTTP:** there is no `axios` or `node-fetch` dependency, and `server.js` currently makes
no outbound calls at all. Node 24 is in use, so **global `fetch` is available** — the fixtures and
squad sync should use it rather than adding a dependency.

---

## 9. Deployment

- `Dockerfile` + `docker-compose.yml` (app + Postgres); `npm run docker:build` / `docker:up` /
  `docker:logs`. Alternatively `node backend/server.js` behind a reverse proxy.
- `DEPLOYMENT.md` (201 lines) is the runbook: rotate secrets → fill `.env` → `prisma migrate deploy`
  → set `TRUST_PROXY` → start.
- **Logging/alerting:** `backend/lib/logger.js` only. Levelled console output — single-line JSON in
  production, human-readable in development. **There is no Slack, email, PagerDuty, or Sentry
  integration.**

**Consequence for the 90-second stall alert (Section 5.6):** there is no notification channel to
route it to. A `logger.error` alone will not be seen by anyone. Recommendation: emit
`logger.error` **and** expose the feed's health as state on an admin endpoint so `admin.html` can
show a visible red indicator — the operator console is the only channel this platform actually has.

**Also:** the Roanuz push feed needs a **publicly reachable HTTPS webhook URL**. Local development
will need a tunnel, and the deployment will need that path routed through the proxy.

---

## 10. Prior art: cricket was here before, and was removed

Commit `4ffce56` ("20 Aug") deleted a previous Your Eleven + Boundary Baazi implementation, along
with a football game. Recovered from history:

- `boundarybaazi.html`, cricket-team/cricket-player pages, `assets/youreleven_bg.jpg`,
  `assets/boundarybaazi_bg.png` (the background art is recoverable from git if wanted).
- `backend/api/cricket_player.php`, `cricket_team.php` + `_logic.php` counterparts.
- Node routes `/api/cricket/matches`, `/api/cricket/submit-team`, `/api/cricket/history`,
  `/api/boundarybaazi/place-bet` (old `server.js:4494–4760`).
- Bot-takeover keys `youreleven` and `boundary`, and transaction `details` strings
  `'Fantasy Cricket Entry Fee'`, `'Fantasy Cricket Payout'`, `'Boundary Baazi Bet'`,
  `'Boundary Baazi Win'` in `classifyGameplayTransaction`.

**None of it is reusable, and this matters more than it first appears.** The old implementation was
**simulated**: a hardcoded `CRICKET_PLAYER_POOL` array, results generated locally, and the payout
decided by the house-edge engine (`isBotTakeoverActive('youreleven') && isUserTargeted(...)`). The
one live-data attempt (`838200a`, CricAPI/cricketdata.org) was also removed. The provider in this
brief — Roanuz — is a different vendor entirely.

What *is* worth reusing: the transaction `details` wording above, so historical rows still classify
correctly, and the background art.

---

## 11. Conflicts and decisions blocking Phase 1

Ground Rule 1 says stop and flag rather than guess. Four things:

### 11.1 — BLOCKING · The house-edge rigging engine vs. genuine scoring

This platform's central admin feature is the **bot takeover engine**: an operator sets a profit
percentage per game and the engine forces outcomes to hit it — Aviator crash points, colour-room
winning numbers, Teen Patti winning seats, Mines busts. It has its own audit system
(`backend/lib/rig-audit.js`, `GET /api/admin/rig-audit`), its own test suite (`npm run test:rig`,
96 assertions), and a dedicated console in `admin.html`. **The previous cricket games were wired
into it**, which is why they used a simulated player pool: you can only rig an outcome you generate.

The brief specifies the opposite: scores recomputed from a permanent log of real deliveries, and
settlement reconciled against the provider's official scorecard.

**These cannot both be true.** Rigging a fantasy contest whose inputs are real balls means
falsifying either the event log or the payout — and the brief's own reconciliation step (Section 6)
is designed to detect exactly that discrepancy and halt.

Three coherent options, and the client must pick one:

- **(A) Genuinely fair.** Cricket sits outside the takeover engine. `botTakeoverState` gets no
  `youreleven`/`boundary` keys. Revenue comes from rake on entry fees. *Recommended* — it is the only
  option consistent with the brief as written, and the only one where reconciliation means anything.
- **(B) Rigged at the payout layer.** Scoring stays honest, but contest results are adjusted. This
  contradicts Sections 6 and 9 and makes the reconciliation step actively misleading. If chosen, the
  brief needs rewriting, not working around.
- **(C) Rake configurable, outcomes never.** (A), plus an admin-tunable rake percentage in the same
  config table as the scoring rules. A middle path that keeps the operator a revenue lever without
  touching results.

I have not written any code that assumes an answer.

### 11.2 — BLOCKING · Contest and settlement infrastructure does not exist (§6)

Wallet and ledger exist and will be reused. **Multi-entrant contests, prize breakdowns, and deferred
bulk settlement do not, and have no precedent in this codebase.** The brief itself designates this as
needing separate scoping. Concretely, Phase 1 needs a decision on: contest formats (single-entry vs
multi-entry, guaranteed vs fill-or-cancel), the prize-distribution table, refund policy on an
abandoned match, and whether payouts are one `Transaction` per winner (consistent with the existing
ledger — recommended) or a batched row.

### 11.3 — Non-blocking, needs a decision before Phase 1 code · Prisma migrations

There is no `prisma/migrations/` directory, but `npm run prisma:migrate` runs `migrate deploy`.
Either (a) initialise migrations now with the cricket tables as the first one — cleaner, but the
baseline must be squared with the live database, or (b) keep using `db push` and fix the script's
label. **(a) is recommended** given the brief requires a permanent event log; unversioned schema
changes to a permanent ledger are a bad combination.

### 11.4 — Non-blocking, must be resolved before public launch · Webhook plumbing

Three concrete details the Section 5 collector will hit:

- `express.json()` is registered **globally at `server.js:301` with no `verify` hook**, so the raw
  request body is discarded before any handler sees it. HMAC signature verification on the Roanuz
  push feed needs the raw bytes — this requires a scoped `express.raw()` on the webhook path
  *registered before* the global JSON parser. Small change, but it touches shared middleware, so it
  needs review under Ground Rule 2.
- The body limit is 256 kb. Confirm Roanuz push payloads fit.
- `apiLimiter` (600/min) applies to everything under `/api/`. The webhook must be excluded, or a
  burst of ball events during a fast over could be rate-limited and dropped.

### 11.5 — Flagged per Section 10, no action taken

Real-money fantasy sports in India carries GST-on-entry-fee, TDS-on-winnings, and state-level
legality exposure (several states restrict or ban it outright). This compounds an exposure the repo
already documents: `DEPLOYMENT.md` §1 states plainly that the existing house-side outcome controls,
run against paying players, constitute fraud in most jurisdictions and breach standard payment-
processor terms. Adding fantasy sports on top of that does not create the problem, but it does
enlarge it. **Legal advice, not code.** Raised and left with the client, as the brief instructs.

---

## 12. Where the brief's assumptions differ from what's here

| Brief assumes | Reality | Follow |
|---|---|---|
| An existing real-time layer to reuse (5.5) | None. Polling only. | Build one SSE endpoint, additive (§5) |
| A logging/notification channel for alerts (5.6) | Console logger only | `logger.error` + visible admin indicator (§9) |
| Contest/entry-fee infra to extend (3, 6) | Wallet yes, contests no | Blocked — §11.2 |
| A component library | CSS custom properties + utility classes | Reuse `.game-card-item` etc. (§7) |
| An existing cache layer (5.5) | None | In-memory, as the brief's fallback allows |
| Standard folder structure per game | One HTML file per surface, one 5.8k-line server | `backend/lib/` modules + thin routes (§3) |

---

## 13. Proposed Phase 1 scope (for approval, not yet started)

Contingent on §11.1 and §11.2 being answered. Nothing here touches an existing game.

1. `config.js` + `.env.example`: Roanuz keys, tournament ID list, `CRICKET_ENABLED` flag.
2. Prisma models: `CricketFixture`, `CricketSquadPlayer`, `CricketBallEvent` (append-only, unique on
   the provider's event id), `CricketPlayerCredit`, `CricketScoringRule`.
3. `backend/lib/cricket/` — `collector.js` (webhook receiver + dedupe), `event-log.js`,
   `live-state.js` (full recompute, never increment), `fixtures-sync.js`, `health.js` (90 s stall).
4. Route registrations in `server.js` behind `CRICKET_ENABLED`, plus the SSE fan-out endpoint.
5. A test suite in the house style — a standalone `backend/test_cricket.js`, no test framework,
   driving the pipeline functions directly the way `test_rigging.js` does.

**Exit criteria for Phase 1:** `npm test`, `npm run test:e2e`, and `npm run test:rig` all still pass,
and every existing page loads and plays unchanged with `CRICKET_ENABLED=false`.
