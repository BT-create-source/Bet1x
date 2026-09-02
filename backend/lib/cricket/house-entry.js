/**
 * Your 11 — the house entry (docs/YOUR11-SCOPE.md section 4).
 *
 * Boundary Baazi has no equivalent and deliberately never will: its outcomes come from the event log
 * and nothing else. There is no `boundary` key anywhere in this file, and test_cricket.js asserts
 * that positively so it cannot be reintroduced by accident.
 *
 * What this actually does, and what it deliberately does not:
 *
 *   - The house entry's points are NEVER a made-up number. They are produced by running the real
 *     scoring engine over a real, legal lineup, drawn only from the confirmed XIs and passing the
 *     exact same `validateLineup` a user's team passes. Only the *choice* of lineup is made with
 *     hindsight. That is what keeps the points break-up screen internally consistent if anyone taps
 *     it after the match.
 *   - It pays its own entry fee, from a real house account, into the same pool. Otherwise the pool
 *     arithmetic silently breaks: real entrants would be funding a prize for an entrant who paid
 *     nothing, and the guarantee-liability figures would be wrong.
 *   - It never enters Practice, Private or Head to Head, and never appears twice in one contest.
 *     H2H matters most: with two entrants a house entry means the real player faces the house
 *     directly and loses nearly every time, which is statistically unmissable within a day.
 *   - It never finishes first when `never_rank_first` is set.
 *
 * The percentage is applied in the unit that is actually concurrent, which for Your 11 is the
 * CONTEST, with a per-match ledger key (`youreleven:<fixture_key>`) so a match with many contests
 * cannot consume another match's rigged slots. Being drawn from the bag **is** the rig decision —
 * there is no second probability roll anywhere in this file. Stacking two percentage mechanisms is
 * what once turned a configured 50% into "8 of 10 games", and CLAUDE.md is emphatic about it.
 */

const crypto = require('crypto');
const context = require('./context');
const configStore = require('./config-store');
const contests = require('./contests');
const scoring = require('./scoring');
const rigAudit = require('../rig-audit');

const GAME_KEY = 'youreleven';

// ------------------------------------------------------------------------------------------------
// determinism
// ------------------------------------------------------------------------------------------------

/**
 * A seeded PRNG, so the same contest in the same match state always produces the same lineup.
 *
 * This is not a cosmetic choice. An unseeded generator would reshuffle the house team on every ball
 * even when its target had not moved — the single most visible tell this design has, and one that
 * would survive into the frozen post-match lineup as an implausible history.
 */
function mulberry32(seed) {
  let a = seed >>> 0;
  return function next() {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function seedFrom(...parts) {
  const hash = crypto.createHash('sha256').update(parts.join('|')).digest();
  return hash.readUInt32BE(0);
}

/** A stable value in [lo, hi] for this contest — its own entry and finish percentiles. */
function stableInBand(seedKey, band, fallback) {
  const [lo, hi] = Array.isArray(band) && band.length === 2 ? band : fallback;
  const r = mulberry32(seedFrom(seedKey))();
  return lo + r * (hi - lo);
}

// ------------------------------------------------------------------------------------------------
// trajectory
// ------------------------------------------------------------------------------------------------

/** Easing curves. Anything unrecognised falls back to linear rather than scoring nothing. */
function ease(p, kind) {
  const x = Math.min(1, Math.max(0, Number(p) || 0));
  if (kind === 'ease_in_out') return x < 0.5 ? 2 * x * x : 1 - ((-2 * x + 2) ** 2) / 2;
  if (kind === 'ease_in') return x * x;
  if (kind === 'ease_out') return 1 - (1 - x) ** 2;
  return x;
}

/**
 * Quantise progress into steps so the climb has small plateaus instead of being a straight line.
 *
 * Deliberately deterministic rather than a per-ball coin flip: a random plateau would let the entry
 * stall and then jump, and — because the lineup is re-solved whenever the target moves — would churn
 * the team for no reason. `plateau_chance` is read as "how coarse the steps are", so a higher value
 * means longer flat stretches.
 */
function plateau(p, chance) {
  const c = Number(chance) || 0;
  if (c <= 0) return p;
  const steps = Math.max(2, Math.round(1 / c));
  return Math.round(p * steps) / steps;
}

/**
 * The rank the house entry should hold at this point in the match.
 *
 * Enters in the lower part of the field and climbs, monotonically, to a band near the top. Never
 * rank 1 when `never_rank_first` is set — winning outright is both the most conspicuous outcome and
 * the most expensive one, since the house would be taking the top prize out of its own pool.
 */
function targetRankAt(progress, entrants, cfg, { seedKey = 'house' } = {}) {
  const n = Math.max(1, Number(entrants) || 1);

  const startPct = stableInBand(`${seedKey}:entry`, cfg.entry_percentile_band, [60, 85]);
  const endPct = stableInBand(`${seedKey}:finish`, cfg.finish_percentile_band, [2, 8]);

  const eased = ease(plateau(Math.min(1, Math.max(0, Number(progress) || 0)), cfg.plateau_chance), cfg.climb_easing);
  const pct = startPct + (endPct - startPct) * eased;

  let rank = Math.ceil((pct / 100) * n);
  rank = Math.min(n, Math.max(1, rank));

  // Never first. In a contest of one there is no second place to take, which is why a house entry is
  // gated on a minimum field size long before this point.
  if (cfg.never_rank_first && rank < 2) rank = Math.min(n, 2);
  return rank;
}

/**
 * How far through the match we are, from the event log's own ball count.
 *
 * Derived from balls actually bowled rather than wall-clock time: a rain break must not advance the
 * climb, and an innings that ends early must not leave the entry stranded mid-trajectory.
 */
function matchProgress(state, { format = 'T20' } = {}) {
  if (!state) return 0;
  if (state.match_ended) return 1;

  const perInnings = { T20: 120, T10: 60, ODI: 300, TEST: 450 }[String(format).toUpperCase()] || 120;
  const total = perInnings * 2;

  const bowled = (state.innings || [])
    .filter(i => !i.super_over)
    .reduce((sum, i) => sum + (Number(i.legal_balls) || 0), 0);

  return Math.min(1, bowled / total);
}

// ------------------------------------------------------------------------------------------------
// eligibility
// ------------------------------------------------------------------------------------------------

/**
 * The guardrails, as a pure function so every one of them is directly testable.
 *
 * Checked BEFORE the bag is drawn from. An ineligible contest must not consume a rigged slot: doing
 * so would make the observed percentage lower than the configured one for reasons the operator
 * cannot see, which is exactly the class of bug the rig audit exists to catch.
 */
function eligibility(contest, entrants, cfg) {
  if (!contest) return { eligible: false, reason: 'no_contest' };

  const excluded = (cfg.excluded_formats || []).map(f => String(f).toLowerCase());
  if (excluded.includes(String(contest.format || '').toLowerCase())) {
    return { eligible: false, reason: `excluded_format:${contest.format}` };
  }
  if (contests.FREE_FORMATS.includes(contest.format) || !(contest.entry_fee > 0)) {
    return { eligible: false, reason: 'no_money_in_contest' };
  }
  if (contest.house_decided) {
    return { eligible: false, reason: 'already_decided' };
  }

  const min = Number(cfg.min_contest_entrants) || 0;
  if (entrants < min) {
    return { eligible: false, reason: `field_too_small:${entrants}<${min}` };
  }
  // One more seat has to exist for the house to occupy, and it must not be the seat that fills the
  // contest at a real player's expense.
  if (contest.max_entrants && entrants >= contest.max_entrants) {
    return { eligible: false, reason: 'contest_full' };
  }

  return { eligible: true, reason: 'eligible' };
}

// ------------------------------------------------------------------------------------------------
// lineup back-solver
// ------------------------------------------------------------------------------------------------

/**
 * Build one legal XI, biased by `bias` in [-1, 1]: +1 strongly prefers high scorers, 0 ignores
 * points entirely, -1 strongly prefers low ones.
 *
 * The negative half is not decoration. The trajectory spends the early part of the match in the
 * lower half of the field, and a solver that could only build good teams would enter every contest
 * near the top and have nowhere to climb from. A purely random team (bias 0) lands near the field
 * average, not near the bottom, so aiming low has to be as explicit as aiming high.
 *
 * Role minimums are filled first, because a greedy pass that ignores them regularly paints itself
 * into a corner with no wicketkeeper left and no budget to fix it. Every pick then checks that the
 * remaining slots can still be afforded using the cheapest players available, so the construction
 * cannot walk over the credit budget and have to be thrown away.
 */
function constructXi(pool, rules, rnd, bias) {
  const size = rules.squad_size || 11;
  const budget = rules.credit_budget || 100;
  const maxPerTeam = rules.max_per_real_team || size;
  const limits = rules.role_limits || {};

  const maxPoints = Math.max(1, ...pool.map(p => Math.abs(p.points) || 0));
  const noise = 1 - Math.abs(bias);
  const keyed = pool.map(p => ({
    ...p,
    // A blend of merit and noise. The noise weight falls away as the bias grows in either direction,
    // so the extremes are near-deterministic (best team / worst team) and the middle is genuinely
    // varied. Sweeping this is what lets the solver hit a target anywhere in the reachable range.
    sort: bias * ((Number(p.points) || 0) / maxPoints) + noise * rnd()
  })).sort((a, b) => b.sort - a.sort);

  const picked = [];
  const roleCount = {};
  const teamCount = {};
  let spent = 0;

  const cheapestRemaining = (exclude, slots) => {
    const costs = keyed
      .filter(p => !exclude.has(p.player_key))
      .map(p => p.credits)
      .sort((a, b) => a - b)
      .slice(0, slots);
    return costs.length < slots ? Infinity : costs.reduce((s, c) => s + c, 0);
  };

  const chosen = new Set();
  const canTake = player => {
    if (chosen.has(player.player_key)) return false;
    const role = player.role;
    const limit = limits[role] || {};
    if (limit.max && (roleCount[role] || 0) >= limit.max) return false;
    if ((teamCount[player.team_key] || 0) >= maxPerTeam) return false;

    const after = spent + player.credits;
    if (after > budget) return false;

    // Leave enough for every slot still to fill.
    const slotsLeft = size - picked.length - 1;
    if (slotsLeft > 0) {
      const provisional = new Set(chosen);
      provisional.add(player.player_key);
      if (after + cheapestRemaining(provisional, slotsLeft) > budget) return false;
    }
    return true;
  };

  const take = player => {
    picked.push(player);
    chosen.add(player.player_key);
    roleCount[player.role] = (roleCount[player.role] || 0) + 1;
    teamCount[player.team_key] = (teamCount[player.team_key] || 0) + 1;
    spent += player.credits;
  };

  // 1. Role minimums.
  for (const [role, limit] of Object.entries(limits)) {
    const need = limit.min || 0;
    for (let i = 0; i < need; i += 1) {
      const next = keyed.find(p => p.role === role && canTake(p));
      if (!next) return null; // this construction is infeasible; the caller retries with new noise
      take(next);
    }
  }

  // 2. Free slots.
  while (picked.length < size) {
    const next = keyed.find(p => canTake(p));
    if (!next) return null;
    take(next);
  }

  return picked;
}

/**
 * Pick the captain and vice-captain that land the team's total closest to the target.
 *
 * The multipliers are the finest control the solver has: with an XI already chosen, moving the
 * armband is worth up to a full player's score, which is usually enough to hit a target the squad
 * itself can only bracket.
 */
function chooseCaptains(xi, rules, target, { floor = null, ceiling = null } = {}) {
  const mc = rules.captain_multiplier || 2;
  const mvc = rules.vice_captain_multiplier || 1.5;
  const base = xi.reduce((sum, p) => sum + (Number(p.points) || 0), 0);

  let best = null;
  for (const c of xi) {
    for (const vc of xi) {
      if (c.player_key === vc.player_key) continue;
      const total = base + c.points * (mc - 1) + vc.points * (mvc - 1);

      // Two hard bounds, both of which matter more than hitting the target exactly:
      //   floor   — the climb is monotonic. A house entry whose score visibly drops is implausible,
      //             and unfair to every real entrant it had already overtaken.
      //   ceiling — `never_rank_first`. Winning outright is the most conspicuous outcome available
      //             and the most expensive, since the top prize comes out of the house's own pool.
      const belowFloor = floor != null && total < floor - 0.0001;
      const aboveCeiling = ceiling != null && total > ceiling + 0.0001;
      // How far outside the bounds this lands. When no legal-and-in-bounds team exists at all — a
      // ceiling below anything the pool can score, say — the least-violating team is the right
      // answer, not the one nearest a target it was never going to reach.
      const violation = (belowFloor ? floor - total : 0) + (aboveCeiling ? total - ceiling : 0);
      const candidate = {
        captain: c.player_key,
        vice_captain: vc.player_key,
        total,
        distance: Math.abs(total - target),
        violation,
        out_of_bounds: belowFloor || aboveCeiling,
        below_floor: belowFloor,
        above_ceiling: aboveCeiling
      };

      if (!best) { best = candidate; continue; }
      if (best.out_of_bounds && !candidate.out_of_bounds) { best = candidate; continue; }
      if (!best.out_of_bounds && candidate.out_of_bounds) continue;
      if (best.out_of_bounds && candidate.out_of_bounds) {
        if (candidate.violation < best.violation) best = candidate;
        continue;
      }
      if (candidate.distance < best.distance) best = candidate;
    }
  }
  return best;
}

/**
 * Back-solve a legal XI scoring as close as possible to `target`.
 *
 * Returns null rather than an approximation when nothing legal can be built — a missing house entry
 * is a non-event, while an illegal one is the tell that undoes the whole design.
 */
function solveLineup({ pool, rules, target, floor = null, ceiling = null, seed = 1, attempts = 240 }) {
  const usable = (pool || []).filter(p => p && p.player_key && Number.isFinite(p.credits));
  if (usable.length < (rules.squad_size || 11)) return null;

  const rnd = mulberry32(seed);
  let best = null;

  for (let i = 0; i < attempts; i += 1) {
    // Sweep the bias across the full range so the candidate set spans the worst legal team through
    // to the best. A solver that only ever built good teams could climb but never sit mid-table,
    // which is where the trajectory spends most of the match.
    const bias = ((i % 21) / 10) - 1;
    const xi = constructXi(usable, rules, rnd, bias);
    if (!xi) continue;

    const armband = chooseCaptains(xi, rules, target, { floor, ceiling });
    if (!armband) continue;

    const candidate = {
      players: xi.map(p => p.player_key),
      captain: armband.captain,
      vice_captain: armband.vice_captain,
      total: Math.round(armband.total * 100) / 100,
      credits_used: Math.round(xi.reduce((s, p) => s + p.credits, 0) * 100) / 100,
      distance: armband.distance,
      violation: armband.violation,
      out_of_bounds: armband.out_of_bounds,
      below_floor: armband.below_floor,
      above_ceiling: armband.above_ceiling
    };

    if (!best) { best = candidate; continue; }
    if (best.out_of_bounds && !candidate.out_of_bounds) { best = candidate; continue; }
    if (!best.out_of_bounds && candidate.out_of_bounds) continue;
    if (best.out_of_bounds && candidate.out_of_bounds) {
      if (candidate.violation < best.violation) best = candidate;
      continue;
    }
    if (candidate.distance < best.distance) best = candidate;
  }

  return best;
}

/**
 * The points the house needs in order to sit at `rank` in a field it is about to join.
 *
 * Aims for the midpoint between the entry it must stay behind and the one it must overtake, so a
 * small miss by the solver still lands on the intended rank rather than one either side of it.
 */
function targetPointsForRank(realPointsDesc, rank, { margin = 1 } = {}) {
  const points = [...(realPointsDesc || [])].sort((a, b) => b - a);
  if (!points.length) return 0;

  const above = rank >= 2 ? points[rank - 2] : null;   // must stay ahead of the house
  const below = rank - 1 < points.length ? points[rank - 1] : null; // the house must overtake this

  if (above == null && below == null) return 0;
  if (above == null) return below + margin;
  if (below == null) return Math.max(0, above - margin);
  return (above + below) / 2;
}

// ------------------------------------------------------------------------------------------------
// database-facing
// ------------------------------------------------------------------------------------------------

/** The rig engine and filler-name generator, injected at boot so nothing here reaches into
 *  server.js. Absent means the house entry is simply switched off, never improvised. */
function houseEdgeOf() {
  const { houseEdge } = context.get();
  return houseEdge || null;
}

/**
 * Draw the rig decision for one contest, exactly once, and record it.
 *
 * `house_decided` is claimed with a conditional updateMany before the bag is drawn, so two
 * concurrent lock passes cannot both draw for the same contest — which would consume two slots from
 * a 100-slot cycle for one contest and quietly bend the observed percentage away from the
 * configured one.
 */
async function decideForContest(contest, entrants, cfg) {
  const { prisma, logger } = context.get();
  const houseEdge = houseEdgeOf();

  const check = eligibility(contest, entrants, cfg);
  if (!check.eligible) {
    // Recorded as ineligible rather than as a non-rigged decision: an ineligible contest never
    // consulted the engine, and counting it would dilute the observed percentage with contests the
    // house was never allowed to enter.
    rigAudit.record({
      game: GAME_KEY, instance: contest.fixture_key, round: contest.id,
      configured_pct: 0, rigged: false, eligible: false, live: entrants, note: check.reason
    });
    return { rigged: false, reason: check.reason };
  }

  if (!houseEdge || typeof houseEdge.shouldRig !== 'function') {
    logger.warn('cricket: house entry skipped - no rig engine injected', { contest_id: contest.id });
    return { rigged: false, reason: 'no_rig_engine' };
  }

  const claim = await prisma.cricketContest.updateMany({
    where: { id: contest.id, house_decided: false },
    data: { house_decided: true }
  });
  if (claim.count === 0) return { rigged: false, reason: 'already_decided' };

  // The per-match ledger key is the whole point: a match with forty contests draws forty times from
  // its OWN cycle, so a busy match cannot consume a quiet match's rigged slots. Being drawn IS the
  // decision — there is no second probability roll below.
  const decision = houseEdge.shouldRig(GAME_KEY, `${GAME_KEY}:${contest.fixture_key}`);

  await prisma.cricketContest.updateMany({
    where: { id: contest.id },
    data: { house_rigged: !!decision.shouldRig }
  });

  rigAudit.record({
    game: GAME_KEY, instance: contest.fixture_key, round: contest.id,
    configured_pct: decision.profit_pct, rigged: !!decision.shouldRig, eligible: true,
    live: entrants, note: decision.source
  });

  return { rigged: !!decision.shouldRig, reason: decision.shouldRig ? 'drawn' : 'not_drawn', decision };
}

/** Everyone in the confirmed XIs, with their price and their points so far. */
async function buildPool(fixtureKey, state) {
  const { prisma } = context.get();

  const squad = await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: fixtureKey } });
  const creditRows = await prisma.cricketPlayerCredit.findMany({ where: { fixture_key: fixtureKey } });
  const creditRules = await configStore.credits();

  const priced = {};
  for (const row of creditRows) priced[row.player_key] = row.credits;

  // Only the confirmed XIs. A house team containing someone who never took the field is the most
  // obvious possible tell, and it is also simply not a team a user could have picked after lock.
  const confirmed = squad.filter(p => p.in_confirmed_xi);
  const usable = confirmed.length >= 11 ? confirmed : squad;

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: fixtureKey } });
  const rules = await configStore.scoringFor((fixture && fixture.format) || 'T20');

  return usable.map(p => {
    const figures = (state && state.players && state.players[p.player_key]) || null;
    return {
      player_key: p.player_key,
      role: String(p.role || 'BAT').toUpperCase(),
      team_key: p.team_key,
      credits: Number.isFinite(priced[p.player_key]) ? priced[p.player_key] : creditRules.default_credits,
      // Unmultiplied. The captain/vice-captain multipliers are applied by chooseCaptains, and
      // double-counting them here would make every solved total too high.
      points: scoringPointsFor(figures, rules, p)
    };
  });
}

/** One player's base fantasy points, via the same engine that scores every real entry. */
function scoringPointsFor(figures, rules, squadRow) {
  return scoring.scorePlayer(figures, rules, {
    role: String(squadRow.role || 'BAT').toUpperCase(),
    inPlayingXi: !!squadRow.in_confirmed_xi
  }).total;
}

/**
 * Create the house entry for a contest that was drawn, paying its own entry fee.
 *
 * It goes through `contests.joinContest` — the same path a real user takes — rather than writing an
 * entry row directly. That is deliberate: the fee lands in the pool through the same wallet debit
 * and the same ledger row, and the lineup passes the same validator, so nothing about the pool
 * arithmetic or the entry's legality is special-cased for the house.
 */
async function enterContest(contest, { wallet = null } = {}) {
  const { prisma, logger } = context.get();
  const houseEdge = houseEdgeOf();

  const account = (houseEdge && houseEdge.account) || null;
  if (!account) {
    logger.error('cricket: house entry drawn but no house account configured - skipping', {
      contest_id: contest.id
    });
    return { ok: false, reason: 'no_house_account' };
  }

  const existing = await prisma.cricketEntry.count({ where: { contest_id: contest.id, is_house: true } });
  if (existing > 0) return { ok: false, reason: 'already_entered' };

  const state = { players: {} }; // nothing has been bowled yet at lock time
  const pool = await buildPool(contest.fixture_key, state);
  const rules = await configStore.contest();

  // Every player is on zero at lock, so there is no target to hit yet: any legal team will do, and
  // the trajectory takes over once real points exist.
  const solved = solveLineup({
    pool, rules, target: 0, seed: seedFrom(contest.id, 'lock'), attempts: 60
  });
  if (!solved) {
    logger.warn('cricket: could not build a legal house lineup', { contest_id: contest.id });
    return { ok: false, reason: 'no_legal_lineup' };
  }

  const result = await contests.joinContest(contest.id, account, {
    players: solved.players,
    captain: solved.captain,
    vice_captain: solved.vice_captain,
    team_name: houseEdge.fillerName ? houseEdge.fillerName() : 'XI',
    display_name: houseEdge.fillerName ? houseEdge.fillerName() : null
  }, { wallet, isHouse: true, bypassLock: true });

  if (!result.ok) {
    logger.warn('cricket: house entry could not join', { contest_id: contest.id, error: result.error });
    return { ok: false, reason: result.error };
  }

  logger.info('cricket: house entry placed', { contest_id: contest.id, entry_id: result.entry.id });
  return { ok: true, entry: result.entry };
}

/**
 * Operator override: place a house entry in one named contest, bypassing the bag.
 *
 * Two things it deliberately does NOT bypass:
 *
 *   - The guardrails. Practice, Private and Head to Head stay off limits, and so does a field too
 *     small to hide an extra entrant. These are not tuning values, they are the conditions under
 *     which a house entry is undetectable at all, and an operator clicking a button does not change
 *     the arithmetic that makes an H2H house entry obvious within a day.
 *   - The audit. A manual rig is recorded with `eligible: false`, which keeps it OUT of the
 *     observed-percentage ratio while still appearing in the audit trail. Counting it would make
 *     the reported observed percentage drift above the configured one for a reason the operator
 *     could not see — the exact class of untruthful reporting the audit exists to prevent.
 */
async function forceEnter(contestId, { wallet = null, by = null } = {}) {
  const { prisma, logger } = context.get();
  const cfg = await configStore.house();

  const contest = await prisma.cricketContest.findUnique({ where: { id: String(contestId) } });
  if (!contest) return { ok: false, error: 'Contest not found.' };

  const entrants = await prisma.cricketEntry.count({ where: { contest_id: contest.id } });

  // `house_decided` is checked by `eligibility`, but a manual override is allowed to act on a
  // contest the bag has already passed over — that is the whole point of an override. Every other
  // guardrail is enforced exactly as it is for an automatic decision.
  const check = eligibility({ ...contest, house_decided: false }, entrants, cfg);
  if (!check.eligible) return { ok: false, error: `Not eligible: ${check.reason}` };

  await prisma.cricketContest.updateMany({
    where: { id: contest.id },
    data: { house_decided: true, house_rigged: true }
  });

  rigAudit.record({
    game: GAME_KEY, instance: contest.fixture_key, round: contest.id,
    configured_pct: 0, rigged: true, eligible: false,
    live: entrants, note: `manual_override${by ? ':' + by : ''}`
  });

  const placed = await enterContest(contest, { wallet });
  if (!placed.ok) return { ok: false, error: placed.reason };

  logger.warn('cricket: house entry placed by MANUAL OVERRIDE', {
    contest_id: contest.id, admin: by, entrants
  });
  return { ok: true, entry: placed.entry };
}

/**
 * Decide and place house entries for every contest on a fixture. Called once, at the confirmed-XI
 * lock, because the field size the guardrails check is only final at that moment.
 */
async function armFixture(fixtureKey, { wallet = null } = {}) {
  const { prisma, logger } = context.get();
  const cfg = await configStore.house();

  const list = await prisma.cricketContest.findMany({
    where: { fixture_key: String(fixtureKey), status: 'locked', house_decided: false }
  });

  const entered = [];
  for (const contest of list) {
    const entrants = await prisma.cricketEntry.count({ where: { contest_id: contest.id } });
    const decision = await decideForContest(contest, entrants, cfg);
    if (!decision.rigged) continue;

    const placed = await enterContest(contest, { wallet });
    if (placed.ok) entered.push(contest.id);
  }

  if (entered.length) {
    logger.info('cricket: house entries armed', { fixture_key: fixtureKey, contests: entered.length });
  }
  return { ok: true, considered: list.length, entered: entered.length, contest_ids: entered };
}

/**
 * Re-solve every live house lineup toward its current target rank.
 *
 * Called on each recompute. Cheap when nothing has moved: the target only changes when the eased,
 * plateaued trajectory crosses into a new rank, and an unchanged target re-solves to the identical
 * lineup because the solver is seeded deterministically.
 */
async function syncFixture(fixtureKey, state) {
  const { prisma, logger } = context.get();
  const cfg = await configStore.house();

  const houseEntries = await prisma.cricketEntry.findMany({
    where: { is_house: true, contest: { fixture_key: String(fixtureKey), status: 'locked' } },
    include: { contest: true }
  });
  if (!houseEntries.length) return { ok: true, updated: 0 };

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: String(fixtureKey) } });
  const pool = await buildPool(String(fixtureKey), state);
  const rules = await configStore.contest();
  const scoringRules = await configStore.scoringFor((fixture && fixture.format) || 'T20');
  const progress = matchProgress(state, { format: (fixture && fixture.format) || 'T20' });

  let updated = 0;
  for (const entry of houseEntries) {
    try {
      // The real field, scored from the permanent log. The house entry is excluded from the field
      // used to compute its own target — including it would feed its last position back into its
      // next one and stall the climb.
      const rivals = await prisma.cricketEntry.findMany({
        where: { contest_id: entry.contest_id, is_house: false }
      });
      if (!rivals.length) continue;

      const rivalPoints = rivals.map(r => contests.round2(
        scoring.scoreTeam(r, state, scoringRules, {
          roles: Object.fromEntries(pool.map(p => [p.player_key, p.role])),
          confirmedXi: state && state.confirmed_xi && state.confirmed_xi.length ? state.confirmed_xi : null
        }).total
      ));

      const targetRank = targetRankAt(progress, rivals.length + 1, cfg, { seedKey: entry.contest_id });
      const target = targetPointsForRank(rivalPoints, targetRank);

      // Never first: the ceiling is whatever the real leader has, less a whisker.
      const leader = Math.max(...rivalPoints);
      const ceiling = cfg.never_rank_first ? leader - 0.01 : null;

      const solved = solveLineup({
        pool,
        rules,
        target,
        floor: Number(entry.points) || null,
        ceiling,
        // Seeded on the target, not the ball: the lineup is stable for as long as the target is,
        // so the team does not churn between recomputes within a plateau.
        seed: seedFrom(entry.contest_id, String(targetRank)),
        attempts: 180
      });
      if (!solved) continue;

      // If nothing legal could be built under the ceiling, the climb stalls this recompute rather
      // than pushing the house past the real leader. A house entry that stops gaining for an over
      // is unremarkable; one that wins the contest outright is neither unremarkable nor cheap.
      if (solved.above_ceiling) {
        logger.info('cricket: house climb held - no legal lineup below the leader', {
          contest_id: entry.contest_id, target, ceiling
        });
        continue;
      }

      await prisma.cricketEntry.update({
        where: { id: entry.id },
        data: {
          players: solved.players,
          captain: solved.captain,
          vice_captain: solved.vice_captain,
          credits_used: solved.credits_used,
          points: solved.total
        }
      });
      updated += 1;
    } catch (e) {
      // A house entry that fails to update simply stops climbing. It must never take down the
      // recompute that every real entrant's score depends on.
      logger.error('cricket: house entry sync failed', {
        contest_id: entry.contest_id, entry_id: entry.id, message: e.message
      });
    }
  }

  return { ok: true, updated };
}

module.exports = {
  GAME_KEY,
  // database-facing
  decideForContest,
  enterContest,
  forceEnter,
  armFixture,
  syncFixture,
  buildPool,
  // pure — no database, no server
  mulberry32,
  seedFrom,
  ease,
  plateau,
  targetRankAt,
  matchProgress,
  eligibility,
  constructXi,
  chooseCaptains,
  solveLineup,
  targetPointsForRank
};
