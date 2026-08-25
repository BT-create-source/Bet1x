/**
 * Rig audit ledger — a read-only observability layer over the house-edge engine.
 *
 * Every game already decides, per round, whether the house takes the round. Nothing recorded what
 * those decisions actually added up to, so "the bot is set to 50% but it feels like more than half"
 * could only ever be argued from impressions. This module records each decision as it happens and
 * reports the observed ratio back, per game and per room, so the configured percentage can be
 * checked against reality instead of estimated.
 *
 * It is deliberately inert with respect to gameplay: `record()` only appends, never returns a value
 * a caller could branch on, and never throws — a bug in here must not be able to change or interrupt
 * a round. The store is in-memory only (a capped ring buffer). Audit history is diagnostic, not
 * business data, so losing it on restart is fine and is much preferable to putting a database write
 * in the path of every round resolution.
 */

// Ring buffer cap. At the busiest realistic rate (four colour rooms plus six Teen Patti tables plus
// Aviator, all resolving continuously) this holds several hours of decisions, which is far more than
// any percentage check needs.
const MAX_ENTRIES = 5000;

const entries = [];

/**
 * Record one rig decision.
 *
 * @param {object} d
 * @param {string} d.game            game key: 'color_guess' | 'aviator' | 'teenpatti' | 'mines'
 * @param {string} [d.instance]      which concurrent instance this decision belongs to — the colour
 *                                   room, the Teen Patti room id, or the username for Mines. Games
 *                                   with a single global round (Aviator) leave it undefined.
 * @param {string|number} [d.round]  round identifier, for cross-referencing against game history
 * @param {number} d.configured_pct  the percentage the operator has set for this game
 * @param {boolean} d.rigged         whether this decision actually rigged the round
 * @param {number} [d.live]          how many live players/instances were in scope at decision time
 * @param {number} [d.targeted]      how many of them the targeting engine had selected
 * @param {number} [d.house_profit]  realised or projected house profit for this round, when known
 * @param {boolean} [d.eligible]     whether this round actually consulted the rig engine. Teen Patti
 *                                   only draws for tables with a real player on them, and a hand
 *                                   between NPCs never asks the ledger anything — counting those as
 *                                   decisions dragged a correct 50% down to a reported 29%. Defaults
 *                                   to true, which is right for every game that always draws.
 * @param {string} [d.note]          free-text reason, mirroring the game's own rig_desc
 */
function record(d) {
  try {
    if (!d || !d.game) return;
    entries.push({
      ts: Date.now(),
      game: String(d.game),
      instance: d.instance !== undefined && d.instance !== null ? String(d.instance) : null,
      round: d.round !== undefined && d.round !== null ? String(d.round) : null,
      configured_pct: Number(d.configured_pct) || 0,
      rigged: !!d.rigged,
      eligible: d.eligible === undefined ? true : !!d.eligible,
      live: Number.isFinite(d.live) ? d.live : null,
      targeted: Number.isFinite(d.targeted) ? d.targeted : null,
      house_profit: Number.isFinite(d.house_profit) ? d.house_profit : null,
      note: d.note ? String(d.note).slice(0, 200) : null
    });
    if (entries.length > MAX_ENTRIES) entries.splice(0, entries.length - MAX_ENTRIES);
  } catch (e) {
    // Audit must never break a round. Swallowing here is the whole point.
  }
}

function summarise(list) {
  // Only rounds that actually consulted the engine count toward the ratio; rounds it was never asked
  // about are reported separately rather than diluting the figure.
  const considered = list.filter(e => e.eligible !== false);
  const decisions = considered.length;
  const skipped = list.length - decisions;
  const rigged = considered.filter(e => e.rigged).length;
  // The configured percentage can change mid-window, so report the most recent one rather than an
  // average of settings that were never simultaneously in effect.
  const configured = decisions > 0 ? considered[considered.length - 1].configured_pct : 0;
  const observed = decisions > 0 ? (rigged / decisions) * 100 : 0;
  const profitEntries = considered.filter(e => e.house_profit !== null);
  return {
    decisions,
    skipped,
    rigged,
    configured_pct: configured,
    observed_pct: parseFloat(observed.toFixed(2)),
    // Positive drift means the house took more rounds than configured; negative means fewer. This is
    // the single number that answers "is 50% actually behaving like 50%".
    drift_pct: parseFloat((observed - configured).toFixed(2)),
    house_profit: profitEntries.length
      ? parseFloat(profitEntries.reduce((s, e) => s + e.house_profit, 0).toFixed(2))
      : null
  };
}

/**
 * Observed-versus-configured ratios, overall and broken down per concurrent instance.
 *
 * The per-instance breakdown is the part that matters for the multi-room games: an overall figure of
 * 50% can hide one Teen Patti table being rigged almost every hand while another is never touched,
 * and only the per-room split makes that visible.
 *
 * @param {object} [opts]
 * @param {number} [opts.sinceMs] only consider decisions from the last N milliseconds
 * @param {string} [opts.game]    restrict to a single game key
 */
function report(opts) {
  const o = opts || {};
  const cutoff = Number.isFinite(o.sinceMs) ? Date.now() - o.sinceMs : null;

  let list = entries;
  if (cutoff !== null) list = list.filter(e => e.ts >= cutoff);
  if (o.game) list = list.filter(e => e.game === o.game);

  const games = {};
  for (const e of list) {
    if (!games[e.game]) games[e.game] = [];
    games[e.game].push(e);
  }

  const out = {};
  for (const game of Object.keys(games)) {
    const gameEntries = games[game];
    const instances = {};
    for (const e of gameEntries) {
      const key = e.instance || '(global)';
      if (!instances[key]) instances[key] = [];
      instances[key].push(e);
    }
    const perInstance = {};
    for (const key of Object.keys(instances)) {
      perInstance[key] = summarise(instances[key]);
    }
    out[game] = { ...summarise(gameEntries), per_instance: perInstance };
  }
  return { window_ms: o.sinceMs || null, total_decisions: list.length, games: out };
}

/**
 * Most recent decisions, newest first — for eyeballing what the engine actually did.
 *
 * Takes the same `game` filter as report(): without it, asking for one game's recent activity
 * returned the last N decisions across every game, so Teen Patti hands showed up in what looked
 * like an Aviator listing.
 */
function recent(limit, game) {
  const n = Math.max(1, Math.min(500, parseInt(limit, 10) || 50));
  const list = game ? entries.filter(e => e.game === game) : entries;
  return list.slice(-n).reverse();
}

function reset() {
  entries.length = 0;
}

module.exports = { record, report, recent, reset };
