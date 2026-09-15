/**
 * Converts a Roanuz match-state SNAPSHOT into the discrete per-event payloads the rest of this
 * pipeline (normalize.js, event-log.js, live-state.js) already understands and is deeply tested
 * against.
 *
 * WHY THIS FILE EXISTS: `collector.js` was originally built assuming one webhook delivery carries
 * one discrete ball event (`{event_type: 'ball', over, ball, batsman, ...}`). Roanuz's own docs
 * (confirmed 2026-08-24, see docs/CRICKET-BUILD-BRIEF.md) instead describe Match via Push
 * delivering the CURRENT FULL MATCH STATE on every update — top-level `score`, `players`,
 * `innings`, `toss`, `status_overview`, and (for "MG100" matches) a `related_balls` array with
 * "full detail for every delivery, including batsman, bowler, outcome, dismissal type". Rather
 * than rewrite the deeply-tested event-log/live-state/scoring layers around a snapshot model, this
 * file adapts AT THE BOUNDARY: given a snapshot, it produces the same discrete event objects a
 * per-ball delivery would have, each carrying a STABLE, content-derived `event_id` — so redelivering
 * the same snapshot (which will happen constantly, since every update resends the whole state) is a
 * no-op at the event log's existing dedup index, exactly like a redelivered discrete event already
 * is. No new dedup logic was needed; the existing one already does the right thing once each
 * logical ball has a stable id.
 *
 * UNCONFIRMED FIELD NAMES: exact `related_balls` item field names could not be extracted from
 * Roanuz's public docs (the schema is not rendered as literal JSON to a crawler — see the
 * build-brief amendment). The aliases below are the closest reading of what publicly-visible text
 * describes ("batsman, bowler, outcome, dismissal type") and follow this codebase's existing
 * `snake_case` / `{ key }`-object convention for player references, matching how every other
 * confirmed Roanuz field already looks (`teams.a.key`, `venue.name`, etc.) — but they are a
 * documented best guess, not a verified contract, exactly like normalize.js's FIELD_MAP already
 * flags itself as being. Fixing a wrong alias here is a contained, one-line change, same discipline.
 */

const crypto = require('crypto');
const normalize = require('./normalize');

/** First present, non-null value among the given dotted-path keys. Reuses normalize's own reader. */
const pick = normalize._pick;

/** A snapshot has match-wide state fields a discrete ball event never carries. */
function looksLikeSnapshot(body) {
  if (!body || typeof body !== 'object') return false;
  if (body.event_type || body.type || body.msg_type) return false; // already a discrete event
  return Boolean(
    body.related_balls || body.score || body.players ||
    (body.live && (body.live.recent_overs_repr || body.live.score)) ||
    body.status_overview
  );
}

/** A short, stable id from whatever fields make a delivery unique, so redelivery dedupes cleanly. */
function stableId(prefix, parts) {
  const digest = crypto.createHash('sha256').update(parts.join('|')).digest('hex').slice(0, 24);
  return `snap_${prefix}_${digest}`;
}

const OUTCOME_TO_LEGACY_FIELDS = {
  // A compact `outcome` descriptor (if that is genuinely all a delivery carries, rather than raw
  // runs/wicket fields) maps onto the same {batsman_run, extra_type, is_wicket} shape
  // normalize.js's FIELD_MAP already reads. Extended defensively; an unrecognised outcome string
  // still yields SOME event rather than being dropped, consistent with normalize.js never
  // discarding an event it cannot fully classify.
  dot: { batsman_run: 0 },
  four: { batsman_run: 4 },
  six: { batsman_run: 6 },
  wide: { extra_type: 'wide', extra_runs: 1 },
  noball: { extra_type: 'noball', extra_runs: 1 },
  no_ball: { extra_type: 'noball', extra_runs: 1 },
  bye: { extra_type: 'bye' },
  legbye: { extra_type: 'legbye' },
  leg_bye: { extra_type: 'legbye' },
  wicket: { is_wicket: true }
};

/** One `related_balls` entry -> a discrete ball-event payload, in normalize.js's own vocabulary. */
function ballFromRelatedBall(raw, fixtureKey) {
  const innings = pick(raw, ['innings', 'innings_number'], 1);
  const over = pick(raw, ['over', 'over_number'], null);
  const ball = pick(raw, ['ball', 'ball_number'], null);
  if (over === null || ball === null) return null;

  const outcomeWord = String(pick(raw, ['outcome', 'ball_type'], '') || '').toLowerCase().replace(/\s+/g, '_');
  const derived = OUTCOME_TO_LEGACY_FIELDS[outcomeWord] || {};

  const isWicket = pick(raw, ['is_wicket', 'wicket.is_wicket'], derived.is_wicket || false);
  const idSeed = pick(raw, ['id', 'key', 'ball_key'], null);

  return {
    event_id: idSeed ? `snap_ball_${idSeed}` : stableId('ball', [fixtureKey, innings, over, ball,
      pick(raw, ['batsman.key', 'batsman'], ''), pick(raw, ['score.batsman_runs', 'batsman_run', 'batsman_runs'], derived.batsman_run || 0)]),
    match_key: fixtureKey,
    event_type: 'ball',
    innings, over, ball,
    batsman: pick(raw, ['batsman.key', 'batsman', 'striker.key', 'striker'], null),
    non_striker: pick(raw, ['non_striker.key', 'non_striker'], null),
    bowler: pick(raw, ['bowler.key', 'bowler'], null),
    batsman_run: pick(raw, ['score.batsman_runs', 'batsman_run', 'batsman_runs'], derived.batsman_run || 0),
    extra_runs: pick(raw, ['score.extra_runs', 'extra_runs'], derived.extra_runs || 0),
    extra_type: pick(raw, ['score.extra_type', 'extra_type'], derived.extra_type || null),
    is_wicket: !!isWicket,
    wicket_type: isWicket ? pick(raw, ['dismissal_type', 'wicket.dismissal_type', 'wicket_type'], 'unknown') : null,
    out_player: isWicket ? pick(raw, ['out_player.key', 'out_player', 'wicket.player.key'], null) : null,
    fielders: pick(raw, ['fielders', 'wicket.fielders'], [])
  };
}

/**
 * The match-wide marker events a snapshot implies, each keyed so a value that hasn't changed since
 * the last snapshot dedupes away and one that has (or appears for the first time) is emitted once.
 */
function markersFromSnapshot(body, fixtureKey) {
  const out = [];

  const tossWinner = pick(body, ['toss.winner', 'toss.winner.key'], null);
  if (tossWinner) {
    const decision = pick(body, ['toss.decision', 'toss.elected'], null);
    out.push({
      event_id: stableId('toss', [fixtureKey, String(tossWinner), String(decision)]),
      match_key: fixtureKey, event_type: 'toss', winner: tossWinner, decision
    });
  }

  const xi = pick(body, ['players.confirmed_xi', 'playing_xi', 'confirmed_playing_xi'], null);
  if (Array.isArray(xi) && xi.length) {
    const keys = xi.map(p => (typeof p === 'object' ? pick(p, ['key', 'player_key'], '') : p)).filter(Boolean).sort();
    out.push({
      event_id: stableId('lineup', [fixtureKey, ...keys]),
      match_key: fixtureKey, event_type: 'lineup', playing_xi: keys
    });
  }

  const state = String(pick(body, ['status_overview.state', 'status.state', 'status'], '') || '').toLowerCase();
  if (state.includes('abandon') || state.includes('no_result')) {
    out.push({ event_id: stableId('abandoned', [fixtureKey]), match_key: fixtureKey, event_type: 'match_abandoned' });
  } else if (state.includes('complete') || state.includes('finished') || state === 'result') {
    out.push({ event_id: stableId('match_end', [fixtureKey]), match_key: fixtureKey, event_type: 'match_end' });
  }

  return out;
}

/**
 * The single entry point: a snapshot -> every discrete event it implies, in a stable order (marker
 * events first, so a lineup lock is never processed after balls that depended on it within the same
 * batch — see collector.advanceContests's own ordering comment for why that matters).
 */
function extractEventsFromSnapshot(body, { fixtureKey } = {}) {
  const key = fixtureKey || pick(body, ['key', 'match.key', 'match_key'], null);
  if (!key) return [];

  const events = markersFromSnapshot(body, key);

  const relatedBalls = pick(body, ['related_balls', 'live.related_balls'], []);
  for (const raw of Array.isArray(relatedBalls) ? relatedBalls : []) {
    const event = ballFromRelatedBall(raw, key);
    if (event) events.push(event);
  }

  return events;
}

module.exports = { looksLikeSnapshot, extractEventsFromSnapshot, ballFromRelatedBall, markersFromSnapshot, stableId };
