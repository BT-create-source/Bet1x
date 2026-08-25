/**
 * Derives match state from the permanent event log.
 *
 * The one rule that governs this whole file: **every call recomputes the full state from the entire
 * event log.** Nothing is ever incremented from a previously derived value. That is what makes a
 * dropped, duplicated or out-of-order delivery harmless instead of silently corrupting every score
 * downstream of it — a late-arriving ball simply lands in the right place on the next recompute,
 * and a redelivered one is deduplicated here as well as at the database's unique index.
 *
 * `buildLiveState` is deliberately a pure function of an array of event rows. It touches no
 * database, no clock and no module state, so the whole scoring surface can be tested by handing it
 * a replayed sequence — including one deliberately shuffled out of order.
 */

const normalize = require('./normalize');

const LEGAL_BALLS_PER_OVER = 6;

/** Dismissals that end an innings for the batsman but are not "out" for fantasy duck purposes. */
const NON_DISMISSAL_EXITS = ['retired_hurt', 'retired_not_out'];

function emptyBatting() {
  return {
    runs: 0, balls_faced: 0, fours: 0, sixes: 0, dots: 0,
    dismissed: false, dismissal_type: null, retired_hurt: false, batted: false
  };
}

function emptyBowling() {
  return {
    balls_bowled: 0, runs_conceded: 0, wickets: 0, maidens: 0,
    dots: 0, wides: 0, noballs: 0, bowled: false
  };
}

function emptyFielding() {
  return { catches: 0, stumpings: 0, run_outs: 0, run_outs_assisted: 0 };
}

function playerSlot(players, key) {
  if (!key) return null;
  if (!players[key]) {
    players[key] = { player_key: key, batting: emptyBatting(), bowling: emptyBowling(), fielding: emptyFielding() };
  }
  return players[key];
}

/**
 * Deterministic cricket ordering.
 *
 * The database returns events in this order already, but relying on that would make correctness a
 * property of how the rows happened to be queried. Sorting here means a caller can hand in events
 * in any order at all — including the shuffled sequence the test suite uses — and get the same
 * answer.
 */
function orderEvents(events) {
  const seen = new Set();
  const unique = [];
  for (const e of events) {
    const id = e.event_id || e.id;
    if (id !== undefined && seen.has(id)) continue; // defensive dedupe; the DB index is the real one
    if (id !== undefined) seen.add(id);
    unique.push(e);
  }

  const rank = v => (v === null || v === undefined ? Number.MAX_SAFE_INTEGER : v);
  return unique.sort((a, b) =>
    rank(a.innings) - rank(b.innings) ||
    rank(a.over) - rank(b.over) ||
    rank(a.ball) - rank(b.ball) ||
    rank(a.sequence) - rank(b.sequence) ||
    rank(a.id) - rank(b.id)
  );
}

/**
 * Build the full derived state for a fixture.
 *
 * `events` are raw log rows; each carries the untouched provider `payload`, which is re-normalised
 * here on every call rather than trusting any interpretation stored earlier.
 */
function buildLiveState(events, { fixtureKey = null } = {}) {
  const ordered = orderEvents(Array.isArray(events) ? events : []);

  const state = {
    fixture_key: fixtureKey,
    status: 'scheduled',
    toss: null,
    lineups_confirmed: false,
    confirmed_xi: [],
    innings: [],
    current_innings: null,
    super_over: false,
    abandoned: false,
    match_ended: false,
    players: {},
    // Fantasy figures exclude super-over deliveries (see §8 of docs/YOUR11-SCOPE.md: points freeze
    // at the end of the tied final over). `players` is the fantasy view; `match_players` counts
    // everything, so the live scorecard can still show the super over honestly.
    match_players: {},
    event_count: ordered.length,
    last_event_at: null
  };

  // Over bookkeeping, keyed innings:over:bowler, used to resolve maidens once an over completes.
  const overs = new Map();

  // How many innings-start markers (`match_start` + `innings_break`) the log has recorded, counted
  // order-independently so a full-log recompute gives the same answer as a live, ball-by-ball one.
  let inningsStartMarkers = 0;

  for (const row of ordered) {
    const norm = normalize.normalizeEvent(row.payload, { fixtureKey: row.fixture_key || fixtureKey });
    const detail = norm.detail;

    // Prefer the type derived from the raw payload right now over the one stored alongside it. The
    // stored column is only an index for querying; treating it as authoritative would mean a row
    // written before a normalizer fix keeps its stale classification forever, which is exactly the
    // "recompute from the log" property this module exists to provide. The stored value is used
    // only when the payload cannot be classified at all.
    const type = norm.event_type !== 'unknown' ? norm.event_type : (row.event_type || 'unknown');

    if (row.received_at) state.last_event_at = row.received_at;

    if (type === 'toss') {
      state.toss = {
        winner: normalize._pick(row.payload, ['toss.winner', 'winner', 'toss_winner'], null),
        decision: normalize._pick(row.payload, ['toss.decision', 'decision', 'elected'], null)
      };
      if (state.status === 'scheduled') state.status = 'toss';
      continue;
    }

    if (type === 'lineup') {
      const xi = normalize._pick(row.payload, ['playing_xi', 'players', 'lineup'], []);
      state.confirmed_xi = Array.isArray(xi) ? xi : [];
      // The confirmed XI is what gates a team lock — never the squad list, never a wall clock.
      state.lineups_confirmed = state.confirmed_xi.length > 0;
      continue;
    }

    if (type === 'match_abandoned') {
      state.abandoned = true;
      state.match_ended = true;
      state.status = 'abandoned';
      continue;
    }

    if (type === 'match_end') {
      state.match_ended = true;
      state.status = 'completed';
      continue;
    }

    if (type === 'innings_break' || type === 'match_start') {
      if (state.status === 'scheduled' || state.status === 'toss') state.status = 'live';
      // Each of these events is a transition INTO one more innings than has started before it -
      // counted here, seeded once the full log has been walked (see below). Doing it inline,
      // mid-loop, is order-dependent in a way that breaks on a full-log recompute: `orderEvents`
      // ranks a marker with no innings/over/ball of its own last, so by the time a stored match is
      // replayed end to end, every real ball has already been processed and both real innings
      // entries already exist - counting "how many exist so far" at that point would double up
      // rather than seed anything.
      inningsStartMarkers += 1;
      continue;
    }

    if (type !== 'ball') continue; // 'ball_start' and anything unknown carry no scoring information

    state.status = state.match_ended ? state.status : 'live';
    if (detail.is_super_over) state.super_over = true;

    const inningsNo = row.innings === null || row.innings === undefined ? 1 : row.innings;
    let innings = state.innings.find(i => i.number === inningsNo && i.super_over === !!detail.is_super_over);
    if (!innings) {
      innings = {
        number: inningsNo, super_over: !!detail.is_super_over,
        runs: 0, wickets: 0, legal_balls: 0, balls_attempted: 0, extras: 0
      };
      state.innings.push(innings);
    }

    const legal = normalize.isLegalDelivery(detail);
    const runsTotal = normalize.totalRuns(detail);

    // Every delivery attempt, legal or not - a wide and the legal re-bowl that follows it report
    // the SAME over.ball under cricket's own numbering (`legal_balls`), so Boundary Baazi needs a
    // counter that still tells them apart. See `nextDeliveryKey` below.
    innings.balls_attempted += 1;

    innings.runs += runsTotal;
    innings.extras += detail.extra_runs || 0;
    if (legal) innings.legal_balls += 1;
    if (detail.is_wicket && !NON_DISMISSAL_EXITS.includes(detail.wicket_type)) innings.wickets += 1;

    // --- per-player figures ------------------------------------------------------------------
    // Super-over deliveries update the match view but never the fantasy view.
    const views = detail.is_super_over ? [state.match_players] : [state.players, state.match_players];

    for (const players of views) {
      const bat = playerSlot(players, detail.batsman);
      const bowl = playerSlot(players, detail.bowler);

      if (bat) {
        bat.batting.batted = true;
        const runs = normalize.batsmanRuns(detail);
        bat.batting.runs += runs;
        if (legal) bat.batting.balls_faced += 1;
        if (runs === 4 && detail.extra_type !== 'bye' && detail.extra_type !== 'legbye') bat.batting.fours += 1;
        if (runs === 6 && detail.extra_type !== 'bye' && detail.extra_type !== 'legbye') bat.batting.sixes += 1;
        if (legal && runsTotal === 0) bat.batting.dots += 1;
      }

      if (bowl) {
        bowl.bowling.bowled = true;
        if (legal) bowl.bowling.balls_bowled += 1;
        bowl.bowling.runs_conceded += normalize.runsAgainstBowler(detail);
        if (detail.extra_type === 'wide') bowl.bowling.wides += 1;
        if (detail.extra_type === 'noball') bowl.bowling.noballs += 1;
        if (legal && normalize.runsAgainstBowler(detail) === 0) bowl.bowling.dots += 1;
        if (normalize.isBowlerWicket(detail)) bowl.bowling.wickets += 1;
      }

      if (detail.is_wicket) {
        const outKey = detail.out_player || detail.batsman;
        const out = playerSlot(players, outKey);
        if (out) {
          if (NON_DISMISSAL_EXITS.includes(detail.wicket_type)) {
            // Retired hurt: keeps everything earned, is not "out", and takes no duck penalty. May
            // resume later and carry on accruing, which falls out naturally from recomputing.
            out.batting.retired_hurt = true;
          } else {
            // Retired *out* is a dismissal and does carry the duck penalty, which is why it is not
            // in NON_DISMISSAL_EXITS.
            out.batting.dismissed = true;
            out.batting.dismissal_type = detail.wicket_type;
          }
        }

        // Fielding credit. Run-outs are never the bowler's; catches and stumpings are the
        // fielder's own. A missing fielder list simply yields no fielding credit rather than
        // guessing at one.
        const fielders = detail.fielders || [];
        if (detail.wicket_type === 'caught' || detail.wicket_type === 'caught_behind') {
          const f = playerSlot(players, fielders[0]);
          if (f) f.fielding.catches += 1;
        } else if (detail.wicket_type === 'caught_and_bowled') {
          const f = playerSlot(players, detail.bowler);
          if (f) f.fielding.catches += 1;
        } else if (detail.wicket_type === 'stumped') {
          const f = playerSlot(players, fielders[0]);
          if (f) f.fielding.stumpings += 1;
        } else if (detail.wicket_type === 'run_out') {
          if (fielders.length === 1) {
            const f = playerSlot(players, fielders[0]);
            if (f) f.fielding.run_outs += 1;
          } else if (fielders.length > 1) {
            fielders.forEach(key => {
              const f = playerSlot(players, key);
              if (f) f.fielding.run_outs_assisted += 1;
            });
          }
        }
      }
    }

    // --- maiden bookkeeping -------------------------------------------------------------------
    if (detail.bowler && row.over !== null && row.over !== undefined && !detail.is_super_over) {
      const key = `${inningsNo}:${row.over}:${detail.bowler}`;
      if (!overs.has(key)) overs.set(key, { bowler: detail.bowler, legal: 0, conceded: 0 });
      const o = overs.get(key);
      if (legal) o.legal += 1;
      o.conceded += normalize.runsAgainstBowler(detail);
    }
  }

  // A maiden is a *completed* over with nothing charged to the bowler. Byes and leg-byes do not
  // break it — they are not the bowler's runs — which is why `runsAgainstBowler` is the measure.
  for (const o of overs.values()) {
    if (o.legal >= LEGAL_BALLS_PER_OVER && o.conceded === 0) {
      const slot = playerSlot(state.players, o.bowler);
      if (slot) slot.bowling.maidens += 1;
      const matchSlot = playerSlot(state.match_players, o.bowler);
      if (matchSlot) matchSlot.bowling.maidens += 1;
    }
  }

  // Seed an entry for any innings a start marker has announced but no ball has reached yet -
  // nothing before the match's first ball, or the just-finished innings right after a break.
  // Without this, `current_innings` below would stay pointed at whichever innings last had a real
  // ball bowled, so `nextDeliveryKey` could never name the OPENING delivery of an innings, and
  // Boundary Baazi would silently have no market for it (`resolveRound` finds no round and no-ops).
  // Comparing markers seen against real innings entries already built (rather than counting inline,
  // mid-loop) is what keeps this correct regardless of replay order.
  {
    const seenNumbers = new Set(state.innings.filter(i => !i.super_over).map(i => i.number));
    for (let n = 1; n <= inningsStartMarkers; n += 1) {
      if (!seenNumbers.has(n)) {
        state.innings.push({ number: n, super_over: false, runs: 0, wickets: 0, legal_balls: 0, balls_attempted: 0, extras: 0 });
      }
    }
  }

  const live = state.innings.filter(i => !i.super_over).sort((a, b) => a.number - b.number);
  state.current_innings = live.length ? live[live.length - 1] : null;
  state.score = state.current_innings
    ? {
        runs: state.current_innings.runs,
        wickets: state.current_innings.wickets,
        overs: formatOvers(state.current_innings.legal_balls),
        legal_balls: state.current_innings.legal_balls
      }
    : { runs: 0, wickets: 0, overs: '0.0', legal_balls: 0 };

  return state;
}

/** Balls -> the conventional "12.4" over string. */
function formatOvers(legalBalls) {
  const complete = Math.floor(legalBalls / LEGAL_BALLS_PER_OVER);
  return `${complete}.${legalBalls % LEGAL_BALLS_PER_OVER}`;
}

/**
 * The ball the next prediction should be gated on.
 *
 * Boundary Baazi closes on the event marking the *start* of the next delivery, never on a timer —
 * a viewer on a broadcast delay would otherwise be answering a question whose outcome they had
 * already seen. This returns the identity of the delivery now in progress so the lock can be keyed
 * to it.
 */
function nextDeliveryKey(state) {
  if (!state.current_innings || state.match_ended) return null;
  const balls = state.current_innings.legal_balls;
  const attempted = state.current_innings.balls_attempted || 0;
  // The trailing component is a delivery-attempt counter, not part of cricket's own over.ball
  // numbering (`questionFor` below only ever reads the two components before it for the human
  // label). It exists because a wide or no-ball does not advance `legal_balls` - the legal re-bowl
  // that follows one is reported at the exact same over.ball position the extra was - so without
  // it, the market for that re-bowl would collide with the extra's own, already-resolved one and
  // could never be opened.
  return `${state.current_innings.number}:${Math.floor(balls / LEGAL_BALLS_PER_OVER)}:${(balls % LEGAL_BALLS_PER_OVER) + 1}:${attempted + 1}`;
}

module.exports = { buildLiveState, orderEvents, formatOvers, nextDeliveryKey, LEGAL_BALLS_PER_OVER };
