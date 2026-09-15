# Master Build Brief — Cricket Games Integration (Boundary Baazi + Your 11)

> Working copy of the master prompt, created 2026-08-23 because the brief existed only as a chat
> message and there was no file to track progress in. The Section 11 checklist below is the live
> progress tracker. If the canonical brief lives somewhere else, say so and the ticks move there.

## Amendments

- **2026-08-23 — Health monitor alerting deferred.** Section 5.6: detect and log the 90-second
  stall as planned, but do **not** wire it to an alert channel (Slack/SMS/email/push) yet. Logging
  only. Note the consequence: until a channel exists, a stalled feed is visible only to someone
  reading the process logs. The visible admin-console indicator recommended in
  `docs/PHASE0-AUDIT.md` §9 is deferred along with it.
- **2026-08-23 — Cricket rigging decided.** Your 11 is riggable via a house entrant in the
  leaderboard; Boundary Baazi is structurally non-riggable. Full spec in `docs/YOUR11-SCOPE.md` §4.
- **2026-08-23 — Contest scope approved.** All six open sign-off items resolved on the recommended
  option: rig percentage counts contests (per-match ledger); opponent teams stay private until the
  match ends; the house entry pays its own entry fee; fill-or-cancel is the default fill policy;
  the Section 8 edge-case table is adopted as proposed; the house never enters Practice, Private or
  Head-to-Head contests. See `docs/YOUR11-SCOPE.md` §0.
- **2026-08-23 — Your 11 contest layer landed (backend).** `backend/lib/cricket/contests.js` now
  covers contest creation with a validated prize table, entry with lineup validation, the
  confirmed-XI lock, fill-or-cancel voiding, refunds, and idempotent settlement — all reusing
  server.js's own wallet helpers via an injected adapter rather than a second implementation.
  Section 11's Your 11 box stays **unticked**: the two things still missing from it are the house
  entry (`docs/YOUR11-SCOPE.md` §4, needs the hindsight lineup back-solver) and the player-facing
  UI (§7). Nothing here has run against a real match.
- **2026-08-23 — Prize `pct` read as per-rank.** The worked example in `docs/YOUR11-SCOPE.md` §1
  does not sum to 100 under either reading. The implementation treats `pct` as the share won by
  **each rank** in a band (the standard fantasy model) and validates
  `Σ (to − from + 1) × pct = 100 ± 0.01`. Consequence if the client intended per-band: only
  `validatePrizeBreakup` and `pctForRank` change, but every stored prize table would need
  rewriting. Worth confirming before any contest is created in production.
- **2026-08-23 — Your 11 house entry landed (backend).** `backend/lib/cricket/house-entry.js`.
  The percentage counts contests, drawn from each match's own 100-slot bag via server.js's existing
  `shouldBotRigThisRound` — one mechanism, no second roll. Guardrails (never Practice/Private/H2H,
  minimum field size, one entry per contest, never rank 1) are pure functions with a test each. The
  entry pays its own fee through the same `joinContest` a real user takes, and its lineup is
  back-solved from real per-player points and re-validated by the user validator. `test_rigging.js`
  gains a Your 11 section and a positive assertion that no rig path exists for Boundary Baazi.
  Still unticked: nothing has run against a real match, and the Your 11 UI does not exist.
- **2026-08-23 — Two bugs found and fixed outside the cricket code.**
  1. `collector.js` iterated a `Map` as if it yielded keys, so `types` was unbound and every webhook
     delivery threw after the durable write — fixtures never advanced and no state was ever fanned
     out. Found by the ingest tests.
  2. `isBotTakeoverActive` (server.js) treated an *unregistered* game key as active whenever the
     global master switch was on. `/api/bot_status/:gameKey` and `/api/bot_decide/:gameKey` take
     that key straight from the URL, so a typo or invented name drew real rig decisions from a bag
     created on the spot. Unknown keys are now inactive. This is also what makes "Boundary Baazi has
     no rig path" a guarantee rather than a convention.
- **2026-08-23 — House account is required config.** `CRICKET_HOUSE_ACCOUNT` must name a real user
  account, because the house entry pays its own entry fee into the pool from that wallet. Blank —
  the default — disables the house entry entirely rather than letting it enter for free.
- **2026-08-23 — Boundary Baazi landed (backend).** `backend/lib/cricket/boundary.js`. One market
  per delivery, keyed `innings:over:ball` and unique per fixture, so a redelivered feed message
  finds the existing market instead of opening a second one. The lock is driven by the feed's
  ball-start event and nothing else — there is no timer anywhere in the file. Resolution is
  idempotent at the round (`resolved_at`) and the bet (`paid_at`).
- **2026-08-23 — Boundary Baazi is PARIMUTUEL, not fixed-odds.** Section 1 of
  `docs/YOUR11-SCOPE.md` says rake is "the only revenue on Boundary Baazi", which only holds for a
  pool game. So: every stake on a delivery joins one pool, the house takes `rake_pct`, and whoever
  backed the actual outcome splits the rest in proportion to stake. This is also *why* the game is
  structurally non-riggable — the house holds no position on any outcome, so there is no ball it
  would prefer. Fixed odds would have handed it an exposure and a reason to manage one. Flagging it
  because the brief never states the market type outright and this is a product decision.
- **2026-08-23 — A delivery that resolves without ever locking is VOIDED, not settled.** If no
  ball-start event arrives for a ball, there is no moment at which betting provably closed before
  it, so settling would risk honouring bets placed after the outcome was already visible on a
  delayed broadcast. Every stake is refunded instead. Configurable (`on_missing_lock`), defaulting
  to `void`. **Consequence worth confirming before launch:** if the Roanuz push feed turns out not
  to send a ball-start event per delivery, this voids every market. It cannot be verified without
  credentials and a live match, and it is the first thing to check when one is available.
- **2026-08-23 — Roanuz credentials not yet available.** No API token, project key or webhook
  secret exists. The pipeline is being built and tested against a replayed feed. Checklist items
  below that require a *live* feed or a *real* match stay unticked until credentials are supplied
  and a fixture has actually run.
- **2026-08-24 — Two gaps closed: `CricketSquadPlayer.in_confirmed_xi` was never actually set, and
  the §3 "player replaced in confirmed XI" edge case had a documented decision but no code.**
  `roanuz.syncSquad`'s own comment said the toss-time `lineup` event sets that flag; nothing did —
  every squad row stayed `in_confirmed_xi: false` forever, which `house-entry.buildPool` silently
  papered over with a `confirmed.length >= 11 ? confirmed : squad` fallback (so the house pool was
  drawing from the *whole* squad, not the XI that actually played) and `contests.scoreEntries`
  papered over the other way (an empty confirmed list reads as "unknown," which
  `scoring.scorePlayer` then treats as "everyone counts"). `collector.js` now calls the new
  `roanuz.applyConfirmedXi(fixtureKey, state.confirmed_xi)` on every `lineup` event, before
  `contests.lockContestsForFixture` runs — recomputed in full from the event's own player list each
  time, so a redelivered lineup event is a no-op rather than a drift risk, same discipline as the
  live-state builder. With that flag now real, `lockContestsForFixture` runs the §3 fix: any entry
  containing a player the confirmed XI excludes is auto-substituted with the highest-credit
  same-team, same-role bench player who *is* in the XI and not already on that entry, re-checked
  against the credit budget (`contests.substituteExcludedPlayers`, pure and unit-tested). Captaincy
  travels with the seat. If no legal replacement exists (rare — an empty bench for that team/role,
  or nothing left that fits the budget), the entry is left as picked and logged loudly; that
  player scores zero for that slot at settlement, which is the documented fallback short of voiding
  the entry. 9 new tests in `test_cricket.js`; all 215 cricket tests and all 116 rig tests still
  pass.
- **2026-08-24 — Roanuz credentials supplied, and a real bug found in the auth path.** The
  project key and API key exist now; `roanuz.js` was posting the token exchange to
  `/v5/cricket/<project>/auth/`, which does not exist (plain infrastructure 404) — the real
  endpoint is `/v5/core/<project>/auth/` (confirmed live: it returns Roanuz's own structured error
  payload, which only a real route would). Fixed, and `getAccessToken` now logs the response body
  on failure, not just the status code. Against the corrected endpoint the account gets
  `403 "Access is limited to specific user groups"` — the project key is recognised as real, but not
  yet entitled to call this at all. That is a Roanuz-side account/licence-activation issue, not a
  code or credentials problem, and nothing else here can be verified until it clears.
- **2026-08-24 — Both games driven end to end over real HTTP for the first time, and two more real
  bugs found and fixed along the way.** `backend/test_cricket_e2e.js` (new; `npm run
  test:cricket:e2e`) boots the actual Express app with `CRICKET_ENABLED` forced on and plays a full
  match through the real webhook, real contests, real wallet and a real database — the same kind of
  full-journey proof `test_e2e.js` gives the other games, which cricket never had before. Building
  it surfaced two gaps unit tests couldn't see, because both only show up when a full sequence of
  events is replayed against real derived state:
  1. **The opening delivery of every innings had no Boundary Baazi market.** `nextDeliveryKey` is
     derived from `current_innings`, which only existed once a ball had actually been bowled — so
     there was no way to name "delivery 1" before it happened, and after an innings break
     `current_innings` still pointed at the just-finished innings until its replacement's first ball
     arrived. `resolveRound` finding no round for either case failed silently (`{ok:true,
     reason:'no_market'}` — no log line, nothing). Fixed in `live-state.js`: a `match_start` or
     `innings_break` event now seeds an empty entry for the innings about to begin, counted
     order-independently (`inningsStartMarkers`, resolved after the full event-log walk) rather than
     inline mid-loop, because `orderEvents` sorts a marker with no over/ball of its own to the very
     end of a full-log replay — counting "what I've seen so far" at that point would double-count
     real innings that already exist rather than seed the missing one.
  2. **A wide or no-ball could silently swallow the market for the legal ball that follows it.**
     Cricket's own over.ball numbering does not advance on an extra, so the re-bowled legal delivery
     at that same position was targeted by `boundary.advance()`'s key reconstruction as if it were
     the *same* delivery as the extra — resolving the extra consumed the round, and the real
     delivery's market either silently vanished (`already_resolved`) or, worse, never opened at all
     (`openNextRound` hit the same already-taken key). Fixed two ways: `advance()` now resolves
     whichever round is actually pending (`status: open|locked`) for the fixture rather than
     rebuilding a key from the ball's own reported over/ball — there is at most one at a time by
     construction — and `nextDeliveryKey` gained a trailing delivery-*attempt* counter
     (`innings:over:ball:attempt`) so the key itself no longer collides between an extra and its
     re-bowl. `questionFor`'s human-facing "Over X.Y" label still reads only the first two
     components, so the visible label is untouched — only the underlying uniqueness changed.
  Both are unit-tested (`test_cricket.js`, including a shuffled full-log-recompute case for the
  first one) and exercised for real in the E2E run: 221 unit tests, 116 rig tests, 107 backend
  regression tests, and 99 assertions in the new E2E suite all pass. `CRICKET_ENABLED` was left off
  in the real `backend/.env` throughout — none of this touched the live site.
- **2026-08-24 — No paid Roanuz access "for a while" is now a design constraint, not a temporary
  gap: the whole pipeline is built so flipping to production is a config change, never a rewrite.**
  Real research against Roanuz's own public docs (cited inline below and in the code) found the
  earlier build's assumptions about the webhook were wrong in ways worth fixing regardless of when
  access clears, plus a genuine missing step:
  1. **A transport abstraction now sits between `roanuz.js` and the network**
     (`backend/lib/cricket/roanuz-transport.js`): `HttpTransport` (real `fetch`, against
     `ROANUZ_AUTH_BASE_URL`/`ROANUZ_BASE_URL`, both now env-configurable rather than hardcoded) and
     `MockTransport` (canned, documentation-shaped responses — auth, tournament fixtures, a squad,
     a subscribe acknowledgement, player stats — no network call, ever). `config.ROANUZ_TRANSPORT`
     selects between them and **resolves itself** from whether `ROANUZ_API_TOKEN`/
     `ROANUZ_PROJECT_KEY` are set when left unset — so dropping in real credentials is the entire
     flip from mock to live, and a production boot with `CRICKET_ENABLED=true` now refuses to start
     if it would still resolve to mock (config.js). Every function in `roanuz.js` goes through the
     active transport; nothing elsewhere in the pipeline knows or cares which one is active.
  2. **Webhook payloads are gzip-compressed.** Roanuz's own handler example
     (`sports.dev.roanuz.com/v5/pages/match-webhook`) decompresses with `gzip.GzipFile` before
     parsing JSON — the existing `collector.parseBody` would have failed on every single real
     delivery. Fixed: detected by the gzip magic bytes rather than assumed, so a body that happens
     to arrive uncompressed still parses.
  3. **A match has to be explicitly subscribed to push delivery before any event for it will ever
     arrive** — `POST /match/{key}/subscribe/` with `{method: "web_hook"}`, confirmed against the
     same page. Nothing in the codebase called this. `roanuz.syncFixtures` now subscribes every
     fixture it syncs, and a manual admin endpoint
     (`POST /api/cricket/admin/subscribe/:fixtureKey`) covers a hand-created fixture or a retry.
  4. **No documented signature/secret mechanism exists for webhook deliveries** — Roanuz's docs
     describe no signing header at all, only that their console accepts an arbitrary URL to
     deliver to. `collector.verifySignature` now accepts EITHER an HMAC header (kept for
     forward-compatibility, unconfirmed either way) OR the configured `ROANUZ_WEBHOOK_SECRET`
     embedded in the registered URL itself (`?secret=...` or an `x-roanuz-secret` header) — the
     latter is the one mechanism the docs actually support existing at all. **Still flagged as
     needing confirmation once real account access exists** — this is a considered design decision
     under documented uncertainty, not a verified fact.
  5. **The push payload itself looks like the full current match state on every update** —
     documented top-level shape includes `score`, `players`, `innings`, `toss`, `status_overview`,
     and (for "MG100" matches specifically — a coverage tier the docs never say is automatically
     included with the ₹200/match Match Via Push purchase, worth confirming) a `related_balls`
     array with "full detail for every delivery, including batsman, bowler, outcome, dismissal
     type." Rather than rewrite the deeply-tested event-log/live-state/scoring layers around a
     snapshot model, `backend/lib/cricket/snapshot-adapter.js` adapts AT THE BOUNDARY: a snapshot
     is turned into the same discrete, normalize.js-shaped events a per-ball delivery would have
     been, each given a stable content-derived `event_id` — so redelivering the same snapshot
     (which will happen on every update, since it always resends the whole state) dedupes at the
     event log's EXISTING unique index, with no new dedup logic needed. `collector.extractEvents`
     detects which shape arrived and routes accordingly; the original discrete-event path (and
     everything the E2E suite already proved about it) is untouched. **Exact `related_balls` field
     names could not be extracted from Roanuz's public docs** (not rendered as literal JSON to a
     crawler) — the aliases used are a documented best reading of the visible text, in the same
     `normalize._pick`-with-multiple-aliases style already used everywhere else in this pipeline for
     exactly this kind of uncertainty, and just as contained to fix if wrong.
  6. **The Section 9 parallel-run validation harness is built now, against no live match, exactly
     so it isn't built under time pressure at launch.** `backend/lib/cricket/parallel-validation.js`
     (`validateSnapshot`, pure; `validateFixture`, DB-backed) reuses `scoring.reconcile` — the same
     comparison settlement already makes — as an on-demand report, callable via
     `GET /api/cricket/admin/validate/:fixtureKey` or `node backend/cricket_validate.js
     <fixtureKey>`. `node backend/cricket_validate.js --demo` proves the harness itself today,
     against a synthetic match, with no database required.
  All of it is genuinely exercised, not just written: `roanuz.syncFixtures` was run end to end
  against the mock transport into the real database (fixture upserted, subscribed, verified by
  direct query); the gzip+snapshot path was driven through the REAL webhook route in
  `test_cricket_e2e.js` with a real gzip-compressed payload, including a redelivery-doesn't-double-
  count check; the validation harness's demo mode is self-verifying (a correct scorecard, a
  deliberately wrong one, and a missing one, each producing the right report). Full suite after all
  of it: 239 unit tests, 116 rig tests, 107 backend regression tests, and 107 E2E assertions, all
  passing. See Section 12 below for the exact go-live checklist this produces.
  Sources: [Get started](https://www.cricketapi.com/v5/docs/guides/first-step) ·
  [Authentication](https://sports.dev.roanuz.com/v5/docs/auth-rest-api) ·
  [Match via Push webhook](https://sports.dev.roanuz.com/v5/pages/match-webhook) ·
  [Match REST API](https://sports.dev.roanuz.com/v5/docs/match-rest-api) ·
  [Match Ball-by-Ball API](https://roanuz.com/newsroom/match-ball-by-ball-api).
- **2026-08-25 — Roanuz account re-checked, still 403.** Re-ran the exact same auth call
  (`getAccessToken` via a real `fetchFixtures` request, live transport, real credentials from
  `backend/.env`) a day after the 2026-08-24 finding. Identical response:
  `403 {"code":"A-403-0","msg":"Access is limited to specific user groups"}`. No code or config
  changed on this end between the two checks, so this rules out a local mistake — it is still purely
  a Roanuz-side entitlement question. Nothing else in Section 11/12 can move until this clears; see
  Section 12 step 1 for the support contact.
- **2026-08-25 — The Section 12 step 8 gap ("no automatic writer" for `official_scorecard`) is now
  closed on the operator side.** There was no way to populate `CricketFixture.official_scorecard`
  at all — the field existed, `parallel-validation.js` and `contests.settleContest`'s reconciliation
  hold both already read it, but nothing ever wrote it outside a test. Added
  `POST /api/cricket/admin/fixtures/:fixtureKey/scorecard` (`backend/lib/cricket/index.js`) —
  admin-only, same `{ players: { [player_key]: { runs, balls_faced, wickets, runs_conceded } } }`
  shape `scoring.reconcile` already reads elsewhere, 404s on an unknown fixture key, 400s on a
  malformed body. `admin.html`'s Cricket Ops tab gained a matching "Settlement validation" card
  (fixture key + JSON textarea + Record/Validate buttons) so an operator doesn't need to hand-craft
  the request. This does not touch the still-open question of whether Roanuz's own feed or a REST
  call supplies this field automatically — that still needs confirming per step 8 — it only means
  the manual fallback path the brief already anticipated now actually exists, rather than being a
  documented gap with no code behind it. Verified three ways: a new `test_cricket_e2e.js` section
  (write → persisted read-back via Prisma → `GET /api/cricket/admin/validate/:fixtureKey` correctly
  flags deliberately-wrong reference figures as a mismatch rather than waving them through, plus the
  404/400 cases), and a live browser smoke test through the actual `admin.html` UI against a
  throwaway server instance (port 5901, cricket forced on, Roanuz credentials blanked so no real
  network call could happen, real dev-database fixture created and validated through the real
  buttons) — the real `backend/.env` and the real dev server were not touched by either check. Full
  suite after: 239 unit tests, 116 rig tests, 107 backend regression tests, 113 E2E assertions (was
  107), all passing.

---

## 0. Read this entire brief before writing any code

You are integrating two new cricket games into an existing website that already has 3–4 working games. The single most important constraint on this project is: **the existing games must keep working exactly as they do today.** Everything below is additive. If anything here conflicts with how the existing codebase actually works, the existing codebase wins — stop and flag the conflict rather than guessing.

## 1. What you're building

Two games, sharing one live-data pipeline:

- **Your 11** — a fantasy cricket game. Users pick 11 players within a credit budget before a match starts, nominate a captain and vice-captain, and earn points as those players perform. Standard Dream11-style mechanic.
- **Boundary Baazi** — a ball-by-ball prediction game. Users predict the outcome of the next delivery (runs / wicket / dot / boundary, etc.) before it is bowled, and are scored on accuracy.

Both games run off the same underlying live cricket data. There is no separate data cost per game — one subscription covers both.

## 2. Ground rules (non-negotiable)

1. **Audit before you build.** Phase 0 below is mandatory and comes first.
2. **Additive, not destructive.** Do not modify, refactor, or "clean up" any existing game's code. Extend shared libraries only where doing so is clearly safe and reviewed.
3. **Match the existing product.** Visual design, navigation patterns, component library, naming conventions, folder structure, and state-management approach must follow what Phase 0 finds — not generic defaults.
4. **One data source, fanned out once.** The site's own server talks to the cricket data provider exactly once per match. No game, no client, no browser ever calls the provider directly. Cost must not scale with user count — 100 users and 100,000 users must cost the same.
5. **Ship in small, working increments.** After every phase below, the existing site and all existing games must still build, run, and pass whatever tests/checks already exist. Do not proceed to the next phase if this isn't true.
6. **Everything score/money-related is config, not constant.** Point values, credit-pricing weights, and lock timings must live in an editable config/table, not hardcoded — they will need tuning after real matches.
7. **Spend guardrail: buy exactly one thing.** See Section 4. Do not enable or purchase any other paid product from the data provider without this being explicitly revisited with the client.

## 3. Phase 0 — Audit (do this first, produce a short written report)

Before writing a single line of new game code, inspect the existing repository and answer:

- Frontend framework/library, routing approach, and state management in use.
- How an existing game is structured end-to-end (pick one existing game as the reference pattern) — file/folder layout, how it talks to the backend, how it renders live/changing state if it has any.
- Backend framework, API style (REST/GraphQL/RPC), and how routes are organized.
- Database and ORM in use, and how an existing game's schema is modeled.
- Whether real-time delivery (WebSocket/SSE/polling) already exists anywhere in the codebase — reuse it if so, don't build a second one.
- Whether user accounts, authentication, and a wallet/points/currency ledger already exist. If the existing games have contests, entry fees, or a virtual currency, **that infrastructure must be reused and extended for cricket, not rebuilt.** If no such infrastructure exists, stop and report back before proceeding — wallet and settlement logic is the highest-risk code in this entire project and deserves its own explicit scoping, not an improvised addition on the side of a data-integration task.
- The site's design system: colors, type scale, spacing, component primitives, and how "live" states, cards, and leaderboards are styled elsewhere on the site, if at all.
- Environment/config conventions (.env structure, secrets handling, how other third-party API keys are stored).
- Deployment process and how a change reaches production.

Report these findings before proceeding to Phase 1. Where anything below assumes a pattern that doesn't match what you actually found, follow what you found, not this document.

## 4. Data provider setup — Roanuz Cricket API

Provider: **Roanuz** (cricketapi.com), **Standard licence** (free to hold, pay per use — not a subscription).

**Buy exactly one product: the Match Via Push API — ₹200 per match, roughly ₹3,800/month at 20 matches/month after Roanuz's standard volume discount.** This delivers every ball of a live match to a webhook/WebSocket/Firebase endpoint on the server within a second or two of the real delivery, including the confirmed playing XI once the toss happens.

**Do NOT purchase, enable, or call:**
- Fantasy Match Points API (₹350/match) — replaced by an in-house scoring engine, Section 6.
- Fantasy Match Credits API (₹250/match) — replaced by an in-house credit algorithm, Section 6.
- Fixtures API, the paid convenience endpoint (₹2,000/month) — replaced by the free tournament-level endpoint below.

**Use these free endpoints** (free per tournament on the Standard licence) for everything else:
- Tournament Fixtures — match schedule.
- Tournament Team — full squads.
- Tournament Tables / Tournament Stats / Featured Matches — supporting data as needed.
- Tournament Player Stats (₹10/player) — a **one-time** cold-start seed for career stats when a tournament begins, to bootstrap the credit algorithm before any live data exists for that tournament's players. This is the only other paid call in the system, and it's a one-off per tournament, not recurring.

**Licence constraints to design around, not to spend around:**
- Match history on the Standard licence is retained for **8 weeks only**. Persist every ball event **permanently** in the site's own database as it arrives — do not rely on being able to re-fetch history later. This permanent event log is also what both games' scoring and the credit algorithm read from.
- Roughly 64 concurrent requests per token and ~1,200 unique accessible resources per month on this licence — comfortably enough for the fixtures/squad sync frequencies below, but don't add high-frequency polling loops on top of the push feed.
- The Standard licence requires a **visible attribution credit to Roanuz** somewhere in the product (e.g. footer or an "about the data" panel). Add this as a real UI element, not a code comment — confirm exact placement/wording with the client, since this is a visible product decision, not just a technical one.

Config to add (adapt names to whatever .env conventions Phase 0 finds already in use): the Roanuz API token/project key, the push webhook URL and its verification secret, and a maintained list of tournament IDs currently being covered.

## 5. Shared core (built once, used by both games)

This is the pipeline both games sit on top of. Build it as its own module, independent of either game's UI.

1. **Fixtures sync** — scheduled job pulling Tournament Fixtures for each configured tournament ID, a few times a day. Key stored fixtures by the provider's own match ID, never by date+teams, so a reschedule updates the existing row instead of creating a duplicate.
2. **Squad sync** — pulls Tournament Team per fixture once squads are announced. Store the full squad separately from the **confirmed Playing XI**, which arrives via the push feed at the toss. Games gate on the confirmed-XI event, not on the squad list and not on a wall-clock time.
3. **Match event collector** — the single webhook/WebSocket receiver for the Match Via Push feed. Every incoming ball event is written to an **append-only, permanent event log**, keyed by the provider's own event ID, so a duplicate delivery is a no-op, not a double-write.
4. **Live state builder** — derives current score, wickets, overs, and per-player figures by **recomputing from the full event log on every update**, never by incrementing a running total from the last known state. This is what makes a dropped or out-of-order message harmless instead of silently corrupting every score downstream.
5. **Cache + fan-out** — the derived state is cached (existing cache layer if Phase 0 finds one, otherwise in-memory) and pushed to connected clients over the site's own WebSocket/SSE layer. Neither game's frontend calls Roanuz, directly or indirectly.
6. **Health monitor** — if no event arrives for **90 seconds** while a covered match is known to be live, raise an alert through whatever logging/notification channel Phase 0 finds already in use. A silent stall is the failure mode most likely to actually happen in production, and the easiest one to miss without this. *(Amended 2026-08-23: log only, no alert channel yet — see Amendments above.)*

## 6. Your 11 — fantasy XI, build list

- **Credit algorithm.** A player's credit value is a weighted function of recent form — e.g. average fantasy points over their last N matches and their average in the current tournament, recency-weighted, clamped to a sensible range and rounded to the nearest 0.5. Cold-start new/unseen players from the one-off Tournament Player Stats seed (Section 4). **Treat the exact weights and clamp range as config, not constants** — they need tuning against real selection behaviour (Section 9), and there is no single "correct" value to hit, only a well-balanced one. Store credits per player per match in an editable table with an admin override, so an obviously wrong price can be fixed by changing a number, not redeploying code.
- **Scoring engine.** Reads the permanent event log for a match and computes each player's fantasy points **by recomputing the full total on every update**, not by incrementing per ball. Point values (runs, boundaries, wickets, catches, run-outs, stumpings, maiden overs, strike-rate/economy bonuses and penalties, duck penalty, captain/vice-captain multipliers) must be a **config table with placeholder default values explicitly flagged as needing confirmation from the client/product owner before launch.** Do not treat any specific numbers in your implementation as final without that sign-off — this is business logic, not a technical detail.
- **Explicit handling for the edge cases in Section 8** — this is where in-house scoring engines actually break, not on the ordinary balls.
- **Team lock** — triggered by the confirmed-Playing-XI event from the feed at toss, not by a timer.
- **Settlement** — after a match ends, reconcile the computed final score against the official scorecard fields already present in the feed, and settle contests **automatically**. Only pause and raise a flag for human review when reconciliation finds a genuine discrepancy (the engine's total doesn't match the provider's own final scorecard beyond a small tolerance, or an edge case the rules table doesn't cover) — this is the exception path, not something every match should wait on. Make settlement **idempotent**: re-running it for an already-settled match must be a safe no-op, never a second payout.
- **Reuse, don't rebuild**, whatever contest-creation, entry, and wallet/ledger system the existing games already use (Phase 0). The cricket-specific work here is fixture/credit/scoring data feeding into that existing system, not a parallel one.

## 7. Boundary Baazi — ball-by-ball prediction, build list

- **Question generation** — for each upcoming delivery in a covered match, generate the prediction prompt (e.g. outcome of the next ball) from the live state.
- **Lock trigger — this is the part that matters most.** The prediction must close **before the delivery happens**, gated on the feed event marking the start of the next ball (e.g. bowler entering the crease / the over-ball counter incrementing) — never on a fixed time delay after the previous ball. A broadcast a user is watching runs seconds to a minute behind the live feed; if the lock is time-based rather than event-based, a user watching a delayed stream can answer after already seeing the outcome. This is a scoring-integrity issue, not a performance detail, and it does not go away with a faster data plan.
- **Resolution** — settle the prediction the moment the actual ball-outcome event arrives, reading from the same permanent event log, idempotently (re-processing the same event must not double-settle).
- Runs on the same shared collector and fan-out from Section 5. No separate data cost, no separate provider connection.

## 8. Known edge cases to handle explicitly, not silently

Design the scoring engine and lock logic with defined, deliberate behaviour for each of these — the failure mode to avoid is any of these hitting an untested code path in production:

- Super over
- Impact Player substitution (IPL)
- Retired hurt / retired out
- Match reduced by rain / DLS-adjusted target
- Match abandoned with no result
- A player replaced in the confirmed XI after original squad announcement

For each, decide and document: does scoring continue, pause, or void the contest? Get this signed off before launch, not discovered during one.

## 9. Validation and safe rollout

- Before trusting the in-house scoring engine with real settlement, run it **in parallel** against real matches and compare its output to the official scorecard for the first 5–10 matches. (Optional: temporarily also subscribing to Roanuz's Fantasy Match Points API for this window only, ~₹350/match, gives an independent second reference to diff against — this is optional spend outside the ₹3,800 baseline, worth considering for the first tournament only, not required.)
- Launch behind a feature flag to a small user group before opening either game to everyone.
- Load-test the fan-out layer for a realistic concurrent-viewer count on a marquee match before a genuinely popular fixture relies on it.
- Confirm the Roanuz attribution placement with the client before public launch (Section 4).
- After the first tournament, review how spread out team selections were in Your 11. If a small number of players appear in almost every team, the credit algorithm is underpricing them — feed that back into the weights.

## 10. Explicitly out of scope for this build

- Real-money compliance (GST on entry fees, TDS on winnings, and state-level legality — several Indian states restrict real-money fantasy sports). This is a product/legal decision for the client, not something to implement defensively in code. Flag it back rather than guessing at it.
- Any Roanuz product beyond Match Via Push (Section 4).
- Changes to existing games beyond extending genuinely shared libraries.

## 11. Definition of done

Every unticked item below is unticked for exactly one of two reasons, and each is labelled which:
**BLOCKED ON ROANUZ** (waiting on their account access, or on real match data that only exists once
that access works) or **BLOCKED ON SIGN-OFF** (waiting on a business decision from the client, not
on anything technical). Neither is "quietly done except for the part that matters" — see Section 12
for exactly what closes each one.

- [x] Phase 0 audit written up and followed
- [x] All existing games build and run unchanged
- [ ] Shared collector, permanent event log, live-state builder, fan-out, and 90-second stall alert are live and covering at least one real match end-to-end — **BLOCKED ON ROANUZ.** *(the pipeline itself is built, unit-tested, and now proven end-to-end against a full simulated match AND a real gzip-compressed snapshot delivery over real HTTP — `npm run test:cricket:e2e`, 2026-08-24 — but "covering a REAL match" needs Roanuz account access, which is still 403'd, and the exact field names in normalize.js's FIELD_MAP and snapshot-adapter.js remain unverified against a live payload until then)*
- [ ] Fixtures and squads sync automatically from free endpoints, keyed by provider match ID — **BLOCKED ON ROANUZ.** *(implemented, including the previously-missing subscribe call, and run end-to-end against the mock transport into the real database — but never yet run against the live endpoints, since account access is still blocked)*
- [ ] Your 11: credit config, scoring config (flagged for client sign-off), toss-gated lock, idempotent auto-settlement with exception-only human review, reusing existing wallet/contest infra — **BLOCKED ON SIGN-OFF** for the config, **BLOCKED ON ROANUZ** for a real match. *(backend and player-facing UI (`youreleven.html`) both complete, house entry included, and the full contest lifecycle — join, lock, confirmed-XI substitution, the reconciliation hold on settlement, a forced settle, idempotent re-settlement — is proven over real HTTP with real wallet money; nothing has run against a real match, and every scoring/credit weight in config-store.js is still an unreviewed placeholder)*
- [ ] Boundary Baazi: event-gated (not timer-gated) prediction lock, idempotent resolution — **BLOCKED ON SIGN-OFF** for the config, **BLOCKED ON ROANUZ** for a real match. *(backend and player-facing UI (`boundarybaazi.html`) both complete and unit-tested, and the full open/lock/resolve/payout cycle — including a wide colliding with its own legal re-bowl and the innings-break transition, both bugs found and fixed via the E2E suite — is proven over real HTTP; the ball-start assumption above is still unverified without a live feed, and rake/stake config is unreviewed)*
- [x] Edge cases in Section 8 have defined, documented behaviour *(all six implemented and unit-tested — the last gap, §3's confirmed-XI replacement, was auto-substitution in name only until 2026-08-24, see Amendments)*
- [ ] Parallel-run validation completed for 5–10 real matches before real settlement is trusted — **BLOCKED ON ROANUZ.** *(the harness itself is built and self-verifying today — `node backend/cricket_validate.js --demo` — against no live match at all; running it 5-10 times needs 5-10 real matches to run it against)*
- [x] Roanuz attribution visible in the product *(footer-style line on both `youreleven.html` and `boundarybaazi.html`; exact wording/placement still worth a final check with the client per Section 9)*
- [ ] Only Match Via Push is an active paid product on the Roanuz account — **BLOCKED ON ROANUZ.** *(an account-level fact, not verifiable from the code — confirm directly on the Roanuz dashboard once access exists)*

## 12. Flipping to production

Everything in this project was built so that this section is short. Whoever does the actual
cutover — not necessarily whoever wrote this — needs exactly this, in this order:

1. **Get the account unblocked.** As of 2026-08-24 the account returns `403 "Access is limited to
   specific user groups"` on a syntactically-correct auth call — a Roanuz-side entitlement issue,
   not a technical one. Contact `support@sports.roanuz.com` with that exact error if it hasn't
   cleared on its own.
2. **Get the webhook secret answer from Roanuz**, if they'll give one: ask support whether webhook
   deliveries carry any signature/secret header. If yes, confirm the header name and update
   `collector.verifySignature`'s HMAC branch to match (it's written and tested, just unconfirmed).
   If no (or no answer), the URL-embedded-secret mechanism already in place is sufficient on its
   own — nothing to change.
3. **Confirm `related_balls` is actually included on your licence tier for the matches you intend
   to cover**, and get one real sample webhook payload from Roanuz support or a live test
   subscription. Update the field aliases in `backend/lib/cricket/snapshot-adapter.js` and
   `backend/lib/cricket/normalize.js` to match — both are deliberately built as small, contained
   alias lists for exactly this reason; this should be a one-file, one-hour fix, not a rewrite.
4. **Fill in the real env vars** in production's `backend/.env` (never commit real production
   secrets to git, despite this repo's dev `.env` being tracked — see the repository quirks note in
   `CLAUDE.md`):
   - `ROANUZ_API_TOKEN`, `ROANUZ_PROJECT_KEY` — this alone flips `ROANUZ_TRANSPORT` from mock to
     live automatically (see the 2026-08-24 amendment). Do not also set `ROANUZ_TRANSPORT=mock`.
   - `ROANUZ_WEBHOOK_SECRET` — generate a real random value; config.js refuses to boot production
     with cricket enabled and this unset.
   - `ROANUZ_TOURNAMENT_IDS` — the tournament key(s) to actually cover.
   - `CRICKET_HOUSE_ACCOUNT` — only if the Your 11 house entry should be active; leave blank to
     launch without it.
5. **Register the webhook URL** with Roanuz (their console, per `sports.dev.roanuz.com/v5/pages/
   match-webhook`): `https://<your-production-host>/api/cricket/webhook?secret=<ROANUZ_WEBHOOK_SECRET>`
   — the query-string secret is the part that makes this specific URL only usable by you.
6. **Get the config sign-offs.** Every section of `backend/lib/cricket/config-store.js` is marked
   `_signed_off: false` with placeholder values. Section 6/7's scoring/credit/contest/boundary
   numbers need the client/product owner's review before a single real contest is created —
   `POST /api/cricket/admin/config/:key` is how an operator edits them once reviewed.
7. **Set `CRICKET_ENABLED=true`** and deploy. `roanuz.syncFixtures` will start running on its
   timer, syncing and subscribing every fixture in the configured tournament(s) automatically.
8. **Run the parallel-run validation** (Section 9) for the first 5–10 real matches:
   `node backend/cricket_validate.js <fixtureKey>` once each match has an `official_scorecard`
   recorded on its `CricketFixture` row. There is still no *automatic* writer for that field —
   confirm with Roanuz support whether the push feed or a REST call supplies it — but as of
   2026-08-25 an operator can enter it by hand: `admin.html`'s Cricket Ops tab → "Settlement
   validation" card, or directly via `POST /api/cricket/admin/fixtures/:fixtureKey/scorecard` with
   `{ players: { [player_key]: { runs, balls_faced, wickets, runs_conceded } } }`. Do not let real
   money settle unsupervised until this window is clean.
9. **Launch behind whatever feature-flag/staged-rollout mechanism the rest of the site uses** (per
   Section 9) — `CRICKET_ENABLED` is a blunt global switch, not a staged one.
10. Only then, tick the remaining Section 11 boxes.
