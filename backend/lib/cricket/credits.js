/**
 * Player credit pricing.
 *
 * A player's credit value is a recency-weighted function of their recent form, blended with their
 * average in the current tournament, clamped to a sensible range and rounded to the nearest 0.5.
 *
 * Two things are deliberate:
 *
 *   - **Every weight is config** (`config-store.js`). There is no single correct pricing, only a
 *     well-balanced one, and the brief is explicit that these get tuned against real selection
 *     behaviour after the first tournament. If a handful of players appear in nearly every team,
 *     they are underpriced and the weights move — not the code.
 *   - **An operator override is never recomputed.** A row marked `admin_override` is left exactly
 *     as it was set, so an obviously wrong price is fixed by changing a number rather than shipping
 *     a release.
 *
 * Form is derived from this platform's own permanent event log, scored with this platform's own
 * engine. That is what makes the paid Fantasy Match Credits product unnecessary.
 */

const context = require('./context');
const configStore = require('./config-store');
const liveState = require('./live-state');
const scoring = require('./scoring');

/**
 * Recency-weighted mean: the most recent match carries the most weight, decaying linearly.
 *
 * A plain average treats a century six matches ago exactly like one yesterday, which prices a
 * player on who they used to be. Linear decay is simple, explicable to a player who asks why
 * someone costs what they cost, and easy to tune.
 */
function recencyWeightedMean(values) {
  if (!Array.isArray(values) || values.length === 0) return null;
  let weighted = 0;
  let weight = 0;
  values.forEach((value, i) => {
    const w = values.length - i; // values[0] is the most recent
    weighted += value * w;
    weight += w;
  });
  return weight ? weighted / weight : null;
}

/** Round to the nearest configured step (0.5 by default) and clamp to the allowed band. */
function quantise(value, weights) {
  const step = weights.round_to || 0.5;
  const rounded = Math.round(value / step) * step;
  const clamped = Math.min(weights.max_credits, Math.max(weights.min_credits, rounded));
  return Math.round(clamped * 100) / 100;
}

/**
 * Price one player from their form.
 *
 * `recentPoints` is most-recent-first. `tournamentAverage` may be null early in a tournament, in
 * which case the blend collapses onto recent form rather than dragging every price toward zero —
 * which is what a naive `(a*0.6 + b*0.4)` with a null `b` would do.
 */
function computeCredits({ recentPoints = [], tournamentAverage = null }, weights) {
  const recent = recencyWeightedMean(recentPoints.slice(0, weights.recent_matches));

  if (recent === null && tournamentAverage === null) {
    // Cold start: nothing known about this player at all.
    return { credits: quantise(weights.default_credits, weights), basis: 'cold_start', form: null };
  }

  let blended;
  let basis;
  if (recent === null) {
    blended = tournamentAverage;
    basis = 'tournament_only';
  } else if (tournamentAverage === null) {
    blended = recent;
    basis = 'recent_only';
  } else {
    const wr = weights.weight_recent_form;
    const wt = weights.weight_tournament;
    const totalWeight = wr + wt || 1;
    blended = (recent * wr + tournamentAverage * wt) / totalWeight;
    basis = 'blended';
  }

  const raw = weights.min_credits + (blended / (weights.points_per_credit || 1));
  return { credits: quantise(raw, weights), basis, form: Math.round(blended * 100) / 100 };
}

/**
 * A player's fantasy points in each of their recent completed matches, most recent first.
 *
 * Computed from this platform's own event log, so it stays available long after Roanuz's 8-week
 * retention window has dropped the match.
 *
 * Note on cost: this scores each past fixture from its full event log. That is fine at the
 * frequency credits are actually recomputed (once before a match, not per ball), but it is the
 * obvious thing to cache if fixtures-per-tournament ever grows large.
 */
async function playerForm(playerKey, { tournamentKey = null, before = null, limit = 10 } = {}) {
  const { prisma } = context.get();

  const fixtures = await prisma.cricketFixture.findMany({
    where: {
      status: 'completed',
      ...(tournamentKey ? { tournament_key: tournamentKey } : {}),
      ...(before ? { start_time: { lt: before } } : {})
    },
    orderBy: { start_time: 'desc' },
    take: limit
  });

  const rules = await configStore.scoringFor('T20');
  const recentPoints = [];
  const tournamentPoints = [];

  for (const fixture of fixtures) {
    const events = await prisma.cricketBallEvent.findMany({
      where: { fixture_key: fixture.key },
      orderBy: [{ innings: 'asc' }, { over: 'asc' }, { ball: 'asc' }, { id: 'asc' }]
    });
    if (!events.length) continue;

    const state = liveState.buildLiveState(events, { fixtureKey: fixture.key });
    const figures = state.players[playerKey];
    if (!figures) continue; // did not play

    const formatRules = await configStore.scoringFor(fixture.format);
    const { total } = scoring.scorePlayer(figures, formatRules || rules, { inPlayingXi: true });

    recentPoints.push(total);
    if (tournamentKey && fixture.tournament_key === tournamentKey) tournamentPoints.push(total);
  }

  const tournamentAverage = tournamentPoints.length
    ? tournamentPoints.reduce((a, b) => a + b, 0) / tournamentPoints.length
    : null;

  return { recentPoints, tournamentAverage, matches_counted: recentPoints.length };
}

/**
 * Recompute and store credits for every player in a fixture's squad.
 *
 * Rows already marked `admin_override` are skipped entirely — an operator correction outranks the
 * algorithm, permanently, until they change it back.
 */
async function refreshForFixture(fixtureKey) {
  const { prisma, logger } = context.get();
  const weights = await configStore.credits();

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: fixtureKey } });
  if (!fixture) return { ok: false, reason: 'unknown_fixture', priced: 0 };

  const squad = await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: fixtureKey } });
  if (!squad.length) return { ok: false, reason: 'no_squad', priced: 0 };

  const existing = await prisma.cricketPlayerCredit.findMany({ where: { fixture_key: fixtureKey } });
  const overridden = new Set(existing.filter(r => r.source === 'admin_override').map(r => r.player_key));

  let priced = 0;
  let skipped = 0;

  for (const player of squad) {
    if (overridden.has(player.player_key)) {
      skipped += 1;
      continue;
    }

    const form = await playerForm(player.player_key, {
      tournamentKey: fixture.tournament_key,
      before: fixture.start_time,
      limit: weights.recent_matches
    });

    const { credits, basis } = computeCredits(form, weights);

    await prisma.cricketPlayerCredit.upsert({
      where: { fixture_key_player_key: { fixture_key: fixtureKey, player_key: player.player_key } },
      update: { credits, source: 'algo' },
      create: { fixture_key: fixtureKey, player_key: player.player_key, credits, source: 'algo' }
    });
    priced += 1;

    logger.debug('cricket: priced player', { player: player.player_key, credits, basis });
  }

  return { ok: true, priced, skipped_overrides: skipped };
}

/** Operator override. Wins over the algorithm until explicitly reset. */
async function setOverride(fixtureKey, playerKey, credits, updatedBy) {
  const { prisma } = context.get();
  const weights = await configStore.credits();
  const value = quantise(Number(credits), weights);

  return prisma.cricketPlayerCredit.upsert({
    where: { fixture_key_player_key: { fixture_key: fixtureKey, player_key: playerKey } },
    update: { credits: value, source: 'admin_override', updated_by: updatedBy },
    create: {
      fixture_key: fixtureKey, player_key: playerKey,
      credits: value, source: 'admin_override', updated_by: updatedBy
    }
  });
}

/** Clear an override so the algorithm prices this player again on the next refresh. */
async function clearOverride(fixtureKey, playerKey) {
  const { prisma } = context.get();
  return prisma.cricketPlayerCredit.updateMany({
    where: { fixture_key: fixtureKey, player_key: playerKey },
    data: { source: 'algo' }
  });
}

module.exports = {
  computeCredits,
  recencyWeightedMean,
  quantise,
  playerForm,
  refreshForFixture,
  setOverride,
  clearOverride
};
