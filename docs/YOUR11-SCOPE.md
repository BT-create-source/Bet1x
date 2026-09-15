# Your 11 — Contest, Settlement & House-Entry Scope

Status: **approved 2026-08-23. This is the spec Phase 1 builds against.**
Follows `docs/PHASE0-AUDIT.md`. Answers the §11.1 and §11.2 blockers.

---

## 0. Decisions taken

| Question | Decision |
|---|---|
| Cricket & the takeover engine | Your 11 **is** riggable, via a house entrant in the leaderboard (§4). Real players' points are never altered. |
| Boundary Baazi | **Structurally non-riggable.** No `boundary` key in `botTakeoverState`, no rig endpoint, no admin override. The only game in the project with no house control. |
| Contest infrastructure | Scoped here first, approved before any money code. |
| Prisma migrations | Initialise migrations; cricket tables become the first real migration. |
| Rig percentage unit | **Contests**, with a per-match ledger key `youreleven:<match_key>` (§4). |
| Opponent team visibility | **Private until the match ends**, uniformly for every entrant (§4). |
| House entry funding | **Pays its own entry fee** from a house account (§4). |
| Default fill policy | **Fill-or-cancel** — full refund if a contest does not fill by lock (§1). |
| Section 8 edge cases | Adopted as proposed in §3. |
| House-entry exclusions | Never in Practice, Private, or Head-to-Head (§4). |

All six open items from the previous revision were resolved on the recommended option.

---

## 1. Contest model

### Formats

Six types, matching the filter chips in the brief. All are rows in one `CricketContest` table
discriminated by `format`, not separate code paths.

| Format | Entrants | Multi-entry | Notes |
|---|---|---|---|
| Head to Head | 2 | No | Winner takes the pool less rake |
| Winner Takes All | 3–100 | No | Single prize |
| Practice | Unlimited | No | **Entry fee 0, no payout.** No house entry, no settlement — bypasses the wallet entirely |
| Small League | 3–100 | Configurable, default 1 | Steep prize curve |
| Mega Contest | 1,000–1,000,000 | Yes, cap per contest (e.g. 20) | Guaranteed prize pool |
| Private Contest | 2–1,000 | Configurable | User-created, invite code, custom fee + pool |

### Fill policy — per contest, one of:

- **Guaranteed** — the advertised pool pays out regardless of fill. The house covers the shortfall.
  This is a real liability: an under-filled mega contest loses money. Needs an operator-set maximum
  exposure per match, and the admin console must show committed guarantee liability before lock.
- **Fill-or-cancel** — if the contest doesn't fill by lock, every entry is refunded in full and the
  contest voids. **Recommended as the default** for anything above a small pool.

### Entry fee, rake and the prize pool

```
gross_pool  = entry_fee × entrants
rake        = gross_pool × rake_pct        (config, per contest, default from CricketConfig)
prize_pool  = gross_pool − rake
```

`rake_pct` is operator-editable config, never a constant (Ground Rule 6). It is the platform's
legitimate revenue on Your 11 and the *only* revenue on Boundary Baazi.

### Prize breakdown

A rank-range table per contest, stored as JSON, not code:

```json
[ { "from": 1, "to": 1,   "pct": 20.0 },
  { "from": 2, "to": 2,   "pct": 12.0 },
  { "from": 3, "to": 10,  "pct": 4.0  },
  { "from": 11, "to": 100,"pct": 0.35 } ]
```

Validated on save: ranges contiguous, no overlap, `pct` sums to 100 ± 0.01. This drives both the
expandable "Prize breakup" view and settlement, so there is exactly one source of truth.

**Ties** split the sum of the prizes for the tied ranks, evenly, rounded down to the paisa, with the
remainder going to the lowest-numbered rank. Tie-break before that is: higher points → earlier team
submission time. Documented in the contest rules modal.

---

## 2. Settlement

The highest-risk code in the project. Rules:

1. **One `Transaction` row per winner**, `type: 'Deposit'`, `details: 'Fantasy Cricket Payout'` —
   matching the string `classifyGameplayTransaction` (`server.js:1759`) already recognises, so
   historical rows and admin stats keep working. Entry fees use `'Fantasy Cricket Entry Fee'`.
2. **Idempotent.** `CricketContest.settled_at` is claimed with a conditional
   `updateMany({ where: { id, settled_at: null }, data: { settled_at: now } })` — the same
   compare-and-set idiom the deposit/withdrawal approval paths already use (`server.js:5409–5444`).
   `count === 0` means someone else settled it; return success, pay nothing. Re-running settlement
   is a safe no-op.
3. **Per-entry claim too.** Each `CricketEntry` has its own `paid_at`, claimed the same way, so a
   crash halfway through a 10,000-winner payout resumes without double-paying the first half.
4. **Credit, then ledger, then reverse on failure** — the Mines discipline (`server.js:4700–4726`),
   in that order, with a loud `logger.error` if the reversal itself fails.
5. **Automatic by default.** Settlement runs on match end without human involvement. It pauses and
   raises a flag **only** when reconciliation fails: the engine's computed total for any player
   differs from the provider's final scorecard beyond tolerance, or an unhandled §5 edge case fired.
   The exception path, not the norm.
6. **Never settle from live state.** Settlement recomputes from the permanent event log.

### Refunds

A full refund is a `Transaction` with `details: 'Fantasy Cricket Refund'` and the same per-entry
idempotency claim. Triggered by: fill-or-cancel shortfall, abandoned match, or an operator void.

---

## 3. Edge cases (brief §8) — proposed behaviour, needs sign-off

| Case | Proposed |
|---|---|
| Super over | **Does not score.** Fantasy points freeze at the end of the tied 20th over. Industry standard. |
| Impact Player (IPL) | Both the substituted and substitute player score, each for what they actually did. If a user's XI contains both, both count. Credits are unaffected. |
| Retired hurt | Player keeps points earned; may resume and keep accruing. No penalty, no duck penalty if on 0. |
| Retired out | Treated as dismissed. Duck penalty applies if on 0. |
| Rain-reduced / DLS | **Scoring continues normally** on actual events. Economy/strike-rate bonuses use actual balls faced/bowled. Contests settle as usual. |
| Abandoned, no result | **Contest voids, full refund to every entrant, rake included.** No payout, no house entry payout. |
| Player replaced in confirmed XI | Any team containing the removed player is auto-substituted with the highest-credit valid bench player from the same team and role. Alternative — void those entries and refund — is available if preferred. |

Each is an explicit branch with its own test, never an untested default.

---

## 4. House entry in the leaderboard (Your 11 only)

### What was asked for

When the takeover engine selects a contest, the house takes a place in that contest's leaderboard.
It appears low down at lock, and as the match progresses its lineup and points are recomputed
against the real live scores so it climbs steadily, finishing **near the top but not at the top**.

### Why this is the honest version of rigging on this platform

It is worth being clear about what this does and does not do, because it changes what the rest of
the system has to guarantee:

- It **does not** alter the ball event log, any real player's fantasy points, or the reconciliation
  against the official scorecard. Those all stay genuine, so the brief's §6 settlement checks remain
  meaningful for every real entrant.
- It **does** take prize money. The house entry displaces real entrants down one rank each, and
  collects a real payout. That is the mechanism, and the prize maths must account for it.

### Percentage unit — DECIDED: contests, per-match ledger

The brief you gave describes it per *round*: at 50%, the first match is rigged, the second isn't,
the third is. That is `shouldBotRigThisRound('youreleven', ledgerKey)` (`server.js:1622`) — the
same exact 100-slot bag Aviator uses, not RNG, so 50% is exactly 50 in every 100 with no clustering.

The open question is what a "round" counts as, and `CLAUDE.md` is emphatic that this is the thing to
get right: **the percentage must be applied in the unit that is actually concurrent**, and stacking
two percentage mechanisms is what once turned a configured 50% into "8 of 10 games".

For Your 11 the concurrent unit is the **contest**, not the match — one match carries many contests
running at once.

- **Recommended: percentage = contests, with a per-match ledger key**
  (`youreleven:<match_key>`), mirroring how each colour room draws from its own cycle so a fast room
  can't consume a slow room's slots. At 50% the house enters half the contests in each match. Being
  drawn **is** the rig decision — no second probability roll, exactly as Teen Patti does it.
- **Rejected alternative: percentage = matches.** At 50% the house would enter *every* contest in
  every other match — simpler, closer to a literal reading of "round", but far more aggressive and
  it clusters house entries into the same matches.

**Decided 2026-08-23: contests, with a per-match ledger key.**

**Manual override:** an admin "rig this contest" button, following the existing `admin_rig` override
pattern (`TeenPattiRoom.admin_rig`, `MINES_RIG_CONFIG.matrix`). A manual rig bypasses the bag and is
recorded separately in `rig-audit` so the observed-vs-configured percentage stays truthful.

### How the climb works

The house entry's points are **never a made-up number**. They are produced by running the real
scoring engine over a real, valid lineup. Only the lineup is chosen with hindsight.

At each recompute the engine:

1. Reads the current per-player points from the permanent event log.
2. Computes the target rank for this moment from the trajectory (below).
3. Back-solves a lineup that scores what that rank requires, subject to every rule a real user
   faces — exactly 11 players, WK 1–4 / BAT 3–6 / AR 1–4 / BOWL 3–6, max 7 from one real team,
   ≤ 100 credits, C at 2× and VC at 1.5× — drawn only from the confirmed XIs.
4. Stores that lineup as the house entry's team.

Because the lineup is always valid and the points always follow from real events, the points
break-up screen is internally consistent if anyone taps it.

**Trajectory** — all config, not constants:

- Entry rank at lock: bottom quartile (config: percentile band).
- Climb: monotonic, eased, with small plateaus so it isn't a straight line.
- Finish: a configurable band near the top but never 1st — default 2nd–5th in small contests,
  top 1–3% in a mega contest.

### The detectability problem, and the fix

**A lineup that changes mid-match is visible** if users can inspect other entrants' teams — and most
fantasy apps let you, after lock. A team that quietly reshuffles itself into the right answer is the
single most obvious tell this design has.

**Recommendation: no opponent team is inspectable in v1.** Users see their own teams; the
leaderboard shows rank, team name, points and prize only. This is a legitimate product choice —
several major fantasy apps hide opponent lineups — and it is uniform, so the house entry isn't the
one special case that can't be opened. After the match ends, the house entry's lineup is frozen and
becomes inspectable along with everyone else's, by which time it is a fixed, plausible, high-scoring
team.

If you'd rather opponent teams *were* inspectable during play, the house entry cannot climb this
way, and we should talk before building it.

**Two further tells to handle:**

- **Team name and username.** Must come from the same generator that produces the existing filler
  names (`randomFillerName`, `server.js:1264`), not a literal "Admin". The brief says the admin's
  name appears — in the operator console it will be clearly marked as a house entry; in the player-
  facing leaderboard it must be indistinguishable from any other entrant.
- **Rank history.** If a "rank over time" graph is ever added, the house entry needs a plausible
  stored history, not a reconstruction. Cheap to record now, expensive to retrofit.

### Guardrails

- The house entry **pays its own entry fee** into the pool, from a house account. Otherwise the pool
  maths silently breaks: real entrants would be funding a prize for an entrant who paid nothing, and
  the guaranteed-pool liability figures in the admin console would be wrong.
- Never in a **Practice** contest (no money, nothing to take).
- Never in a **Private** contest — those are between friends who know each other's names, and an
  unexplained extra entrant is immediately obvious.
- Never in **Head to Head** — with two entrants, a house entry means the real player faces the house
  directly and loses ~every time. Statistically unmissable within a day.
- At most **one** house entry per contest, even under a manual override.
- Every decision recorded through `backend/lib/rig-audit.js`, so `GET /api/admin/rig-audit` reports
  observed-vs-configured for `youreleven` the same as every other game, and `npm run test:rig` gains
  a Your 11 section asserting that a configured percentage means that proportion of contests.

### Boundary Baazi

No house entry, no rig endpoint, no admin override, no `boundary` key in `botTakeoverState`.
Ball-by-ball outcomes come from the event log and nothing else. A test in `test_rigging.js` should
assert this positively — that no rig path exists for `boundary` — so it can't be reintroduced by
accident later.

---

## 5. Data model (first Prisma migration)

```
CricketFixture        provider match key (PK), tournament, teams, venue, start_time, status,
                      toss_at, lineups_confirmed_at
CricketSquadPlayer    fixture, player key, name, role (WK|BAT|AR|BOWL), team, in_confirmed_xi
CricketBallEvent      provider event id (unique), fixture, innings, over, ball, payload Json,
                      received_at        ← append-only, permanent, Postgres-only
CricketPlayerCredit   fixture, player, credits, source (algo|admin_override), updated_by
CricketContest        fixture, format, entry_fee, rake_pct, prize_breakup Json, max_entrants,
                      max_entries_per_user, guaranteed, invite_code, status, settled_at
CricketEntry          contest, username, team Json, captain, vice_captain, points, rank,
                      prize, paid_at, is_house
CricketConfig         key/value — scoring rules, credit weights, trajectory bands, lock policy
```

`CricketConfig` may reuse the existing `GameState` table (generic `key` + `data` Json) rather than a
new one — that is already the established idiom for per-game config here.

**`CricketBallEvent` never uses the JSON fallback.** Per the audit §4, `writeJsonTable` rewrites the
whole file per write, which is wrong for a permanently growing log. Guarded by `requireDatabase`;
returns 503 rather than creating a diverging second store.

---

## 6. Scoring and credits — config, flagged for sign-off

Every value below ships as a **placeholder requiring your confirmation before launch** (brief §6).

**Scoring:** run, boundary bonus, six bonus, 30/50/100 milestones, duck penalty, wicket, 3/4/5-wicket
hauls, maiden over, catch, stumping, run-out (direct/assisted), strike-rate bands (batting),
economy bands (bowling), starting-XI points, C ×2, VC ×1.5.

**Credits:** recency-weighted average of last N matches' fantasy points, blended with the
current-tournament average, clamped to a range and rounded to 0.5. Cold-start from the one-off
Tournament Player Stats seed. Per-player admin override in `CricketPlayerCredit`, so a mispriced
player is fixed by editing a number.

Recomputation is always a **full recompute from the event log**, never an increment.

---

## 7. UI — the Your 11 surface

Built as `youreleven.html` at the repo root, one file, using the existing tokens and the
`ui-common.js` → `sound-fx.js` script order. Screens as specified:

- **Match header** — teams, venue, pitch report, weather; a "Lineups out / Lineups pending"
  indicator driven by the confirmed-XI event, plus the lock countdown. Optional editorial
  tips/expert-prediction slot.
- **Tabs** — Contests · My Teams · Points · Scoreboard · Team News.
- **Contest list** — filter chips (All, H2H, Winner Takes All, Practice, Small, Mega, Private);
  sort by prize pool / entry fee / spots left. Cards carry: type icon, prize pool (large), entry
  fee, 1st prize, a spots-filled progress bar, multi-entry cap, Guaranteed badge, winner-percentage
  badge, and a Join button showing how many teams the user already has in. Expandable rank-wise
  prize breakup and a rules modal, both read from `prize_breakup` and `CricketConfig`.
- **Private contest** — create with custom fee and pool, shareable invite code/link.
- **Team builder** — live 100-credit counter, X/11 selected, role tabs with min/max enforcement,
  Team A / Team B / All sub-filters, sort by credits / selection% / recent points. Player cards
  show photo, name, team tag, credits, selection%, average points, injury/doubtful icon. Max-7-per-
  team enforced client-side **and** server-side. Stats popup and a two-player compare tool.
- **C/VC** — circular avatar grid, tap to assign, multiplier tooltip, optional suggested-picks banner.
- **Pitch view** — field graphic with players by role position, C/VC badges, editable team name,
  credits-used summary, Save / Edit / Create Another Team.
- **My Teams** — list with swipe/tab, compare your own teams, clone, delete (before lock only),
  per-match lock countdown.
- **Live points** — per-player ticker with runs/wickets/catches breakdown, live scorecard tab,
  floating live-rank widget, and a tap-through per-player points break-up.
- **Leaderboard** — sticky My Rank card, full table (rank, team, points, prize), All / My Contests
  filter, top-3 badges, auto-refresh, and a post-match results screen with prize-credited animation.

All live surfaces consume the shared SSE endpoint from the audit (§5), not their own polling loop.

### Three UI items with no infrastructure behind them

- **Push notifications** (wicket falls, captain scores big, result declared). There is **no service
  worker, no web app manifest, and no push infrastructure anywhere in the repo** — I checked. This
  is a genuinely separate piece of work. Recommendation: v1 ships in-page toasts over the SSE
  stream, which covers the experience while a user has the page open, and real push gets scoped on
  its own.
- **Player photos.** Roanuz's free tournament endpoints may not include images, and the licence may
  restrict their use. Needs confirming before the player cards are designed around them — the
  fallback is initials-in-a-circle avatars, which the existing design tokens suit well.
- **Pitch report, weather, team news, expert tips.** Editorial or third-party content, not in the
  Match Via Push feed. Either an admin-authored field per fixture, or the sections are dropped from
  v1. No additional paid Roanuz product should be bought for this (brief §4).

---

## 8. Still outstanding

All six sign-off items were resolved on 2026-08-23 (§0). Two things remain open, neither of which
blocks building:

- **Scoring values and credit weights.** They ship as flagged placeholders and get tuned after real
  matches, as the brief specifies. Sign-off is needed before public launch, not before Phase 1.
- **Roanuz credentials.** No API token, project key, or webhook secret exists yet. The pipeline can
  be built and tested against a replayed feed without them, but nothing can cover a real match —
  and the brief's Section 11 items that say "live" and "covering at least one real match" cannot
  honestly be ticked until they are supplied and a fixture has run.
