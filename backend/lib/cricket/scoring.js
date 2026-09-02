/**
 * Fantasy scoring engine.
 *
 * Reads the per-player figures that `live-state.js` derived from the permanent event log and turns
 * them into fantasy points. Like everything else in this pipeline it **recomputes the full total
 * every time** — never incrementing per ball — so a late, duplicated or corrected delivery simply
 * produces the right answer on the next pass.
 *
 * Every value it uses comes from the config store. There is not one scoring constant in this file,
 * deliberately: the numbers are business logic awaiting sign-off, and burying them here is exactly
 * what the build brief forbids.
 *
 * `scorePlayer` is pure — figures in, points out — so the whole scoring surface is testable without
 * a database, a feed, or a clock.
 */

/**
 * Pick the applicable band from a threshold table.
 *
 * Bands are expressed as `{ above, points }` or `{ below, points }`. The most extreme matching band
 * wins in each direction, and the table is sorted here rather than trusting the order it was saved
 * in — an operator editing the JSON must not be able to change the outcome by reordering rows.
 */
function selectBand(value, bands) {
  if (!Array.isArray(bands)) return 0;

  const above = bands.filter(b => typeof b.above === 'number').sort((a, b) => b.above - a.above);
  for (const band of above) {
    if (value > band.above) return band.points || 0;
  }

  const below = bands.filter(b => typeof b.below === 'number').sort((a, b) => a.below - b.below);
  for (const band of below) {
    if (value < band.below) return band.points || 0;
  }

  return 0;
}

/** Highest reached milestone, or the sum of all reached, depending on config. */
function milestoneBonus(runs, rules) {
  const tiers = [
    { at: 100, points: rules.runs_100_bonus || 0 },
    { at: 50, points: rules.runs_50_bonus || 0 },
    { at: 25, points: rules.runs_25_bonus || 0 }
  ];
  const reached = tiers.filter(t => runs >= t.at);
  if (!reached.length) return 0;
  return rules.milestones_cumulative
    ? reached.reduce((sum, t) => sum + t.points, 0)
    : reached[0].points;
}

/** Highest reached wicket haul, or the sum, depending on config. */
function haulBonus(wickets, rules) {
  const tiers = [
    { at: 5, points: rules.wickets_5_bonus || 0 },
    { at: 4, points: rules.wickets_4_bonus || 0 },
    { at: 3, points: rules.wickets_3_bonus || 0 }
  ];
  const reached = tiers.filter(t => wickets >= t.at);
  if (!reached.length) return 0;
  return rules.hauls_cumulative
    ? reached.reduce((sum, t) => sum + t.points, 0)
    : reached[0].points;
}

/**
 * Score one player.
 *
 * Returns `{ total, breakdown }`. The breakdown is what the per-player points screen shows, so it
 * has to be complete and honest — every point in `total` appears as a line in it.
 */
function scorePlayer(figures, rules, { role = 'BAT', inPlayingXi = true } = {}) {
  const bat = (figures && figures.batting) || {};
  const bowl = (figures && figures.bowling) || {};
  const field = (figures && figures.fielding) || {};

  const breakdown = {};
  const add = (label, points) => {
    if (points) breakdown[label] = (breakdown[label] || 0) + points;
  };

  if (inPlayingXi) add('in_playing_xi', rules.in_playing_xi || 0);

  // --- batting ---------------------------------------------------------------------------------
  const runs = bat.runs || 0;
  add('runs', runs * (rules.run || 0));
  add('fours', (bat.fours || 0) * (rules.four_bonus || 0));
  add('sixes', (bat.sixes || 0) * (rules.six_bonus || 0));
  add('milestone', milestoneBonus(runs, rules));

  // A duck is being dismissed for nought having actually batted. Retiring hurt on 0 is not a duck,
  // which falls out of live-state marking that case `retired_hurt` rather than `dismissed`.
  const duckExempt = (rules.duck_exempt_roles || []).includes(role);
  if (bat.batted && bat.dismissed && runs === 0 && !duckExempt) {
    add('duck', rules.duck_penalty || 0);
  }

  const srRules = rules.strike_rate || {};
  if ((bat.balls_faced || 0) >= (srRules.min_balls || 0) && (bat.balls_faced || 0) > 0) {
    const sr = (runs / bat.balls_faced) * 100;
    add('strike_rate', selectBand(sr, srRules.bands));
  }

  // --- bowling ---------------------------------------------------------------------------------
  const wickets = bowl.wickets || 0;
  add('wickets', wickets * (rules.wicket || 0));
  add('wicket_haul', haulBonus(wickets, rules));
  add('maidens', (bowl.maidens || 0) * (rules.maiden_over || 0));

  const econRules = rules.economy || {};
  const oversBowled = (bowl.balls_bowled || 0) / 6;
  if (oversBowled >= (econRules.min_overs || 0) && oversBowled > 0) {
    const economy = (bowl.runs_conceded || 0) / oversBowled;
    add('economy', selectBand(economy, econRules.bands));
  }

  // --- fielding --------------------------------------------------------------------------------
  const catches = field.catches || 0;
  add('catches', catches * (rules.catch || 0));
  if (catches >= 3) add('catch_bonus', rules.catches_3_bonus || 0);
  add('stumpings', (field.stumpings || 0) * (rules.stumping || 0));
  add('run_outs', (field.run_outs || 0) * (rules.run_out_direct || 0));
  add('run_outs_assisted', (field.run_outs_assisted || 0) * (rules.run_out_assisted || 0));

  const total = Object.values(breakdown).reduce((sum, v) => sum + v, 0);
  return { total: round2(total), breakdown };
}

/**
 * Score a whole fantasy team.
 *
 * Captain and vice-captain multipliers are applied to the player's **final** total, so every bonus
 * and penalty is multiplied along with the base points — which is what players expect and what
 * every mainstream rule set does.
 */
function scoreTeam(entry, state, rules, { roles = {}, confirmedXi = null } = {}) {
  const players = Array.isArray(entry.players) ? entry.players : [];
  const perPlayer = {};
  let total = 0;

  for (const playerKey of players) {
    const figures = (state.players && state.players[playerKey]) || null;
    const inXi = confirmedXi ? confirmedXi.includes(playerKey) : true;

    const base = scorePlayer(figures, rules, { role: roles[playerKey] || 'BAT', inPlayingXi: inXi });

    let multiplier = 1;
    let designation = null;
    if (playerKey === entry.captain) {
      multiplier = rules.captain_multiplier || 1;
      designation = 'C';
    } else if (playerKey === entry.vice_captain) {
      multiplier = rules.vice_captain_multiplier || 1;
      designation = 'VC';
    }

    const points = round2(base.total * multiplier);
    perPlayer[playerKey] = {
      player_key: playerKey,
      role: roles[playerKey] || null,
      base_points: base.total,
      multiplier,
      designation,
      points,
      breakdown: base.breakdown,
      in_playing_xi: inXi
    };
    total += points;
  }

  return { total: round2(total), players: perPlayer };
}

/**
 * Reconcile the engine's own figures against the provider's official scorecard.
 *
 * Settlement runs automatically; this is what decides when it must *not*. A mismatch beyond
 * tolerance means the engine and the provider disagree about what physically happened, and paying
 * out on that would be paying out on a bug.
 */
function reconcile(state, officialScorecard, { tolerance = 0 } = {}) {
  if (!officialScorecard || typeof officialScorecard !== 'object') {
    return { ok: false, reason: 'no_official_scorecard', discrepancies: [] };
  }

  const discrepancies = [];
  const official = officialScorecard.players || {};

  for (const [playerKey, figures] of Object.entries(state.players || {})) {
    const ref = official[playerKey];
    if (!ref) continue;

    const checks = [
      ['runs', (figures.batting || {}).runs || 0, ref.runs],
      ['balls_faced', (figures.batting || {}).balls_faced || 0, ref.balls_faced],
      ['wickets', (figures.bowling || {}).wickets || 0, ref.wickets],
      ['runs_conceded', (figures.bowling || {}).runs_conceded || 0, ref.runs_conceded]
    ];

    for (const [field, computed, expected] of checks) {
      if (expected === undefined || expected === null) continue;
      if (Math.abs(computed - expected) > tolerance) {
        discrepancies.push({ player_key: playerKey, field, computed, official: expected });
      }
    }
  }

  return { ok: discrepancies.length === 0, reason: discrepancies.length ? 'mismatch' : 'ok', discrepancies };
}

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

module.exports = { scorePlayer, scoreTeam, reconcile, selectBand, milestoneBonus, haulBonus };
