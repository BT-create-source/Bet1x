/**
 * Roanuz push payload -> the pipeline's internal event shape.
 *
 * ---------------------------------------------------------------------------------------------
 * IMPORTANT, AND NOT YET SETTLED
 *
 * This adapter is written against Roanuz's documented ball structure, but no real payload has been
 * seen — there is no API token on the account yet. The exact field names below MUST be checked
 * against a live delivery before launch. `FIELD_MAP` is deliberately the only place that knows
 * Roanuz's vocabulary, so correcting it is a small, contained edit rather than a rewrite.
 *
 * The design assumption that makes being wrong survivable: the event log stores the **raw provider
 * payload**, and normalisation happens at *read* time, on every recompute. So if a field name here
 * turns out to be wrong, the fix is to correct the map and recompute — no data is lost, and no
 * already-recorded match becomes unscoreable. Nothing in this file may ever discard an event it
 * fails to understand.
 * ---------------------------------------------------------------------------------------------
 */

/** First present, non-null value among the given keys. */
function pick(obj, keys, fallback = undefined) {
  if (!obj || typeof obj !== 'object') return fallback;
  for (const key of keys) {
    const parts = key.split('.');
    let cur = obj;
    let ok = true;
    for (const part of parts) {
      if (cur && typeof cur === 'object' && part in cur) cur = cur[part];
      else { ok = false; break; }
    }
    if (ok && cur !== null && cur !== undefined && cur !== '') return cur;
  }
  return fallback;
}

function toInt(value, fallback = null) {
  const n = parseInt(value, 10);
  return Number.isFinite(n) ? n : fallback;
}

/**
 * Every provider field name the adapter understands, in one place. Add alternatives rather than
 * replacing them — a payload that satisfies an older name still normalises correctly.
 */
const FIELD_MAP = {
  eventId:    ['event_id', 'id', 'key', 'ball_key'],
  matchKey:   ['match_key', 'match_id', 'match.key', 'key'],
  eventType:  ['event_type', 'type', 'msg_type'],
  innings:    ['innings', 'innings_number', 'ball.innings', 'over.innings'],
  over:       ['over', 'over_number', 'ball.over', 'overs.over'],
  ballNumber: ['ball', 'ball_number', 'ball.ball', 'overs.ball'],
  sequence:   ['sequence', 'seq', 'ball_seq'],
  batsman:    ['batsman.key', 'batsman', 'striker.key', 'striker'],
  nonStriker: ['non_striker.key', 'non_striker', 'batsman_2.key'],
  bowler:     ['bowler.key', 'bowler'],
  batsmanRun: ['batsman_run', 'batsman_runs', 'runs.batsman', 'batsman.runs'],
  extraRuns:  ['extra_runs', 'extras', 'runs.extras'],
  extraType:  ['extra_type', 'extras_type', 'runs.extra_type'],
  isWicket:   ['is_wicket', 'wicket', 'out'],
  wicketType: ['wicket_type', 'dismissal', 'out_type'],
  outPlayer:  ['out_player.key', 'out_player', 'player_out.key', 'player_out'],
  fielders:   ['fielders', 'fielder_keys', 'catchers'],
  isSuperOver:['is_super_over', 'super_over']
};

/** Provider event-type strings mapped onto the pipeline's vocabulary. */
const TYPE_ALIASES = {
  ball: 'ball',
  ball_update: 'ball',
  delivery: 'ball',
  toss: 'toss',
  lineup: 'lineup',
  playing_xi: 'lineup',
  squad_confirmed: 'lineup',
  innings_break: 'innings_break',
  innings_end: 'innings_break',
  match_start: 'match_start',
  match_end: 'match_end',
  match_complete: 'match_end',
  abandoned: 'match_abandoned',
  no_result: 'match_abandoned',
  ball_start: 'ball_start',
  next_ball: 'ball_start'
};

const EXTRA_TYPES = ['wide', 'noball', 'no_ball', 'bye', 'legbye', 'leg_bye', 'penalty'];

function normalizeExtraType(raw) {
  if (!raw) return null;
  const key = String(raw).toLowerCase().replace(/\s+/g, '_');
  if (!EXTRA_TYPES.includes(key)) return key; // preserve anything unrecognised rather than dropping it
  if (key === 'no_ball') return 'noball';
  if (key === 'leg_bye') return 'legbye';
  return key;
}

/**
 * Classify a raw payload.
 *
 * Returns 'unknown' rather than throwing or guessing when the type is unrecognised. An unknown
 * event is still written to the log verbatim; only its interpretation is deferred.
 */
function classify(raw) {
  const declared = pick(raw, FIELD_MAP.eventType);
  if (declared) {
    const key = String(declared).toLowerCase().replace(/\s+/g, '_');
    if (TYPE_ALIASES[key]) return TYPE_ALIASES[key];
  }
  // No declared type: infer from shape. A payload carrying a bowler and an over is a delivery.
  if (pick(raw, FIELD_MAP.bowler) && pick(raw, FIELD_MAP.over) !== undefined) return 'ball';
  return 'unknown';
}

/**
 * Normalise one raw provider payload.
 *
 * Never throws and never returns null — an unparseable payload still yields a record whose
 * `payload` is the untouched original, so the collector can always persist it.
 */
function normalizeEvent(raw, { fixtureKey = null } = {}) {
  const safe = raw && typeof raw === 'object' ? raw : { _unparsed: raw };
  const eventType = classify(safe);

  const wicketRaw = pick(safe, FIELD_MAP.isWicket, false);
  const isWicket = wicketRaw === true || wicketRaw === 'true' || wicketRaw === 1;

  const fielders = pick(safe, FIELD_MAP.fielders, []);

  return {
    event_id: pick(safe, FIELD_MAP.eventId, null),
    fixture_key: fixtureKey || pick(safe, FIELD_MAP.matchKey, null),
    event_type: eventType,
    innings: toInt(pick(safe, FIELD_MAP.innings)),
    over: toInt(pick(safe, FIELD_MAP.over)),
    ball: toInt(pick(safe, FIELD_MAP.ballNumber)),
    sequence: toInt(pick(safe, FIELD_MAP.sequence)),
    payload: safe,

    // Interpreted fields. Consumed by live-state; recomputed from `payload` on every read, never
    // trusted from a previous pass.
    detail: {
      batsman: pick(safe, FIELD_MAP.batsman, null),
      non_striker: pick(safe, FIELD_MAP.nonStriker, null),
      bowler: pick(safe, FIELD_MAP.bowler, null),
      batsman_run: toInt(pick(safe, FIELD_MAP.batsmanRun), 0) || 0,
      extra_runs: toInt(pick(safe, FIELD_MAP.extraRuns), 0) || 0,
      extra_type: normalizeExtraType(pick(safe, FIELD_MAP.extraType)),
      is_wicket: isWicket,
      wicket_type: isWicket ? String(pick(safe, FIELD_MAP.wicketType, 'unknown')).toLowerCase().replace(/\s+/g, '_') : null,
      out_player: isWicket ? pick(safe, FIELD_MAP.outPlayer, null) : null,
      fielders: Array.isArray(fielders) ? fielders : [fielders].filter(Boolean),
      is_super_over: pick(safe, FIELD_MAP.isSuperOver, false) === true
    }
  };
}

/**
 * Does this delivery consume a legal ball?
 *
 * Wides and no-balls do not. This matters well beyond the over count: a bowler's economy and a
 * batsman's strike rate are both per-ball, so getting it wrong silently mis-prices every
 * strike-rate and economy bonus in the fantasy scoring.
 */
function isLegalDelivery(detail) {
  return detail.extra_type !== 'wide' && detail.extra_type !== 'noball';
}

/** Total runs credited to the batting side for a delivery. */
function totalRuns(detail) {
  return (detail.batsman_run || 0) + (detail.extra_runs || 0);
}

/**
 * Runs credited to the batsman personally.
 *
 * Byes and leg-byes go to the team, never the striker. Runs off a no-ball do count to the batsman.
 */
function batsmanRuns(detail) {
  if (detail.extra_type === 'bye' || detail.extra_type === 'legbye' || detail.extra_type === 'penalty') return 0;
  return detail.batsman_run || 0;
}

/**
 * Runs charged against the bowler's economy.
 *
 * Byes and leg-byes are not the bowler's fault; wides and no-balls are.
 */
function runsAgainstBowler(detail) {
  const extras = detail.extra_type === 'bye' || detail.extra_type === 'legbye' ? 0 : (detail.extra_runs || 0);
  return (detail.batsman_run || 0) + extras;
}

/**
 * Is this dismissal credited to the bowler?
 *
 * Run-outs, retirements and obstruction are not. Getting this wrong hands bowlers fantasy points
 * for wickets they had no part in.
 */
const BOWLER_CREDITED_WICKETS = ['bowled', 'caught', 'lbw', 'stumped', 'hit_wicket', 'caught_and_bowled', 'caught_behind'];
function isBowlerWicket(detail) {
  if (!detail.is_wicket) return false;
  return BOWLER_CREDITED_WICKETS.includes(detail.wicket_type);
}

module.exports = {
  normalizeEvent,
  classify,
  isLegalDelivery,
  totalRuns,
  batsmanRuns,
  runsAgainstBowler,
  isBowlerWicket,
  FIELD_MAP,
  TYPE_ALIASES,
  BOWLER_CREDITED_WICKETS,
  _pick: pick
};
