/**
 * Cricket pipeline test suite.
 *
 * Style matches backend/test_rigging.js: no Jest, no Mocha, no database, no server. Everything here
 * drives the pipeline modules directly with an injected in-memory store, so the whole suite runs in
 * about a second and can be run on a laptop with nothing installed.
 *
 * What it is actually asserting, and why each one is here rather than being taken on trust:
 *
 *   - A shuffled, duplicated event stream produces byte-identical state to a clean one. This is the
 *     single guarantee the whole design rests on. If it ever fails, a dropped or out-of-order push
 *     delivery silently corrupts every score and every payout downstream of it.
 *   - Extras arithmetic. Byes never credit the batsman, wides never count as a legal ball, leg-byes
 *     never break a maiden. Each of these silently mis-prices a strike-rate or economy bonus.
 *   - Wicket attribution. A run-out is not the bowler's wicket. Getting this wrong hands bowlers
 *     fantasy points for dismissals they had no part in.
 *   - Signature verification actually rejects a forged body. The webhook writes into a permanent log
 *     that decides real payouts.
 *   - A redelivered event is a no-op. The push feed is at-least-once by design.
 *   - The 90-second stall is detected, and recovery clears it.
 *
 * Run: node backend/test_cricket.js      (or: npm run test:cricket)
 */

const assert = require('assert');

const context = require('./lib/cricket/context');
const normalize = require('./lib/cricket/normalize');
const eventLog = require('./lib/cricket/event-log');
const liveState = require('./lib/cricket/live-state');
const collector = require('./lib/cricket/collector');
const fanout = require('./lib/cricket/fanout');
const health = require('./lib/cricket/health');
const configStore = require('./lib/cricket/config-store');
const scoring = require('./lib/cricket/scoring');
const credits = require('./lib/cricket/credits');
const contests = require('./lib/cricket/contests');
const houseEntry = require('./lib/cricket/house-entry');
const boundary = require('./lib/cricket/boundary');
const snapshotAdapter = require('./lib/cricket/snapshot-adapter');
const roanuzTransport = require('./lib/cricket/roanuz-transport');
const config = require('./config');

/** Match the engine's own 2dp rounding when asserting on derived totals. */
const round = n => Math.round((Number(n) || 0) * 100) / 100;

// ------------------------------------------------------------------------------------------------
// harness
// ------------------------------------------------------------------------------------------------

let passed = 0;
let failed = 0;

function section(name) {
  console.log(`\n--- ${name} ---`);
}

function ok(label, fn) {
  try {
    fn();
    console.log(`  ok   ${label}`);
    passed += 1;
  } catch (e) {
    console.log(`  FAIL ${label}`);
    console.log(`       ${e.message}`);
    failed += 1;
  }
}

async function okAsync(label, fn) {
  try {
    await fn();
    console.log(`  ok   ${label}`);
    passed += 1;
  } catch (e) {
    console.log(`  FAIL ${label}`);
    console.log(`       ${e.message}`);
    failed += 1;
  }
}

/** Silent logger; swapped for a capturing one where a test asserts on what was logged. */
function makeLogger(sink) {
  const push = level => (msg, meta) => { if (sink) sink.push({ level, msg, meta }); };
  return { error: push('error'), warn: push('warn'), info: push('info'), debug: push('debug') };
}

/**
 * In-memory stand-in for the parts of PrismaClient the pipeline touches, including the unique
 * constraint on event_id — the dedupe guarantee is only meaningful if the store enforces it.
 */
function makeStore() {
  const events = [];
  const fixtures = new Map();
  const gameState = new Map();

  const matches = (row, where) => Object.entries(where || {}).every(([k, v]) => {
    if (v && typeof v === 'object' && !(v instanceof Date)) return true; // nested filters unused here
    return row[k] === v;
  });

  return {
    _events: events,
    _fixtures: fixtures,
    _gameState: gameState,
    cricketBallEvent: {
      async create({ data }) {
        if (events.some(e => e.event_id === data.event_id)) {
          const err = new Error('Unique constraint failed on the fields: (`event_id`)');
          err.code = 'P2002';
          throw err;
        }
        if (fixtures.size && !fixtures.has(data.fixture_key)) {
          const err = new Error('Foreign key constraint failed');
          err.code = 'P2003';
          throw err;
        }
        const row = { id: events.length + 1, received_at: new Date(), sequence: null, ...data };
        events.push(row);
        return row;
      },
      async findMany({ where }) {
        const rank = v => (v === null || v === undefined ? Number.MAX_SAFE_INTEGER : v);
        return events
          .filter(e => matches(e, where))
          .sort((a, b) =>
            rank(a.innings) - rank(b.innings) ||
            rank(a.over) - rank(b.over) ||
            rank(a.ball) - rank(b.ball) ||
            rank(a.sequence) - rank(b.sequence) ||
            a.id - b.id);
      },
      async count({ where }) {
        return events.filter(e => matches(e, where)).length;
      }
    },
    gameState: {
      async findUnique({ where }) {
        return gameState.get(where.key) || null;
      },
      async upsert({ where, update, create }) {
        const row = gameState.has(where.key)
          ? { ...gameState.get(where.key), ...update }
          : create;
        gameState.set(where.key, row);
        return row;
      }
    },
    cricketFixture: {
      async update({ where, data }) {
        const row = fixtures.get(where.key);
        if (!row) {
          const err = new Error('Record to update not found');
          err.code = 'P2025';
          throw err;
        }
        fixtures.set(where.key, { ...row, ...data });
        return fixtures.get(where.key);
      },
      async findUnique({ where }) {
        return fixtures.get(where.key) || null;
      }
    }
  };
}

function install({ sink = null } = {}) {
  const store = makeStore();
  context.reset();
  context.init({ prisma: store, logger: makeLogger(sink) });
  health.reset();
  return store;
}

// ------------------------------------------------------------------------------------------------
// event builders
// ------------------------------------------------------------------------------------------------

const MATCH = 'test_match_1';

let ballCounter = 0;
function ball(over, ballNo, opts = {}) {
  ballCounter += 1;
  return {
    event_id: opts.event_id || `ev_${over}_${ballNo}_${ballCounter}`,
    match_key: MATCH,
    event_type: 'ball',
    innings: opts.innings || 1,
    over,
    ball: ballNo,
    batsman: opts.batsman || 'bat1',
    non_striker: opts.non_striker || 'bat2',
    bowler: opts.bowler || 'bowl1',
    batsman_run: opts.runs === undefined ? 0 : opts.runs,
    extra_runs: opts.extra_runs || 0,
    extra_type: opts.extra_type || null,
    is_wicket: opts.wicket || false,
    wicket_type: opts.wicket_type || null,
    out_player: opts.out_player || null,
    fielders: opts.fielders || [],
    is_super_over: opts.super_over || false
  };
}

/** Wrap raw payloads as log rows, the shape buildLiveState receives from the database. */
function asRows(payloads) {
  return payloads.map((p, i) => ({
    id: i + 1,
    event_id: p.event_id,
    fixture_key: MATCH,
    event_type: p.event_type,
    innings: p.innings === undefined ? null : p.innings,
    over: p.over === undefined ? null : p.over,
    ball: p.ball === undefined ? null : p.ball,
    sequence: null,
    payload: p,
    received_at: new Date()
  }));
}

function shuffle(arr, seed = 7) {
  const out = arr.slice();
  let s = seed;
  for (let i = out.length - 1; i > 0; i--) {
    s = (s * 1103515245 + 12345) % 2147483648;
    const j = s % (i + 1);
    [out[i], out[j]] = [out[j], out[i]];
  }
  return out;
}

// ------------------------------------------------------------------------------------------------
// tests
// ------------------------------------------------------------------------------------------------

async function run() {
  console.log('\n=================================================');
  console.log('  cricket pipeline');
  console.log('=================================================');

  // ---------------------------------------------------------------------------------------------
  section('normalisation: extras arithmetic');
  install();

  ok('a wide is not a legal delivery', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'wide', extra_runs: 1 })).detail;
    assert.strictEqual(normalize.isLegalDelivery(d), false);
  });

  ok('a no-ball is not a legal delivery', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'noball', extra_runs: 1 })).detail;
    assert.strictEqual(normalize.isLegalDelivery(d), false);
  });

  ok('a leg-bye IS a legal delivery', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'legbye', extra_runs: 2 })).detail;
    assert.strictEqual(normalize.isLegalDelivery(d), true);
  });

  ok('byes never credit the batsman', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'bye', extra_runs: 4 })).detail;
    assert.strictEqual(normalize.batsmanRuns(d), 0);
  });

  ok('runs off a no-ball DO credit the batsman', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { runs: 4, extra_type: 'noball', extra_runs: 1 })).detail;
    assert.strictEqual(normalize.batsmanRuns(d), 4);
  });

  ok('leg-byes are not charged to the bowler', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'legbye', extra_runs: 2 })).detail;
    assert.strictEqual(normalize.runsAgainstBowler(d), 0);
  });

  ok('wides ARE charged to the bowler', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'wide', extra_runs: 1 })).detail;
    assert.strictEqual(normalize.runsAgainstBowler(d), 1);
  });

  ok('an unrecognised extra type is preserved, not dropped', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { extra_type: 'some_new_thing' })).detail;
    assert.strictEqual(d.extra_type, 'some_new_thing');
  });

  // ---------------------------------------------------------------------------------------------
  section('normalisation: wicket attribution');

  ok('bowled is the bowler\'s wicket', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { wicket: true, wicket_type: 'bowled' })).detail;
    assert.strictEqual(normalize.isBowlerWicket(d), true);
  });

  ok('a run-out is NOT the bowler\'s wicket', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { wicket: true, wicket_type: 'run out' })).detail;
    assert.strictEqual(normalize.isBowlerWicket(d), false);
  });

  ok('retired hurt is NOT the bowler\'s wicket', () => {
    const d = normalize.normalizeEvent(ball(1, 1, { wicket: true, wicket_type: 'retired hurt' })).detail;
    assert.strictEqual(normalize.isBowlerWicket(d), false);
  });

  ok('an unknown payload is classified, never discarded', () => {
    const n = normalize.normalizeEvent({ something: 'unexpected' });
    assert.strictEqual(n.event_type, 'unknown');
    assert.deepStrictEqual(n.payload, { something: 'unexpected' });
  });

  ok('a non-object payload still yields a storable record', () => {
    const n = normalize.normalizeEvent('garbage');
    assert.strictEqual(n.event_type, 'unknown');
    assert.ok(n.payload);
  });

  // ---------------------------------------------------------------------------------------------
  section('live state: the recompute guarantee');

  const over1 = [
    ball(1, 1, { runs: 1 }),
    ball(1, 2, { runs: 4 }),
    ball(1, 3, { runs: 0 }),
    ball(1, 4, { runs: 6 }),
    ball(1, 5, { extra_type: 'wide', extra_runs: 1 }),
    ball(1, 5, { runs: 0, wicket: true, wicket_type: 'bowled', out_player: 'bat1' }),
    ball(1, 6, { runs: 2 })
  ];

  ok('runs, wickets and legal balls are counted correctly', () => {
    const s = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    assert.strictEqual(s.score.runs, 14, 'expected 1+4+0+6+1(wide)+0+2');
    assert.strictEqual(s.score.wickets, 1);
    assert.strictEqual(s.score.legal_balls, 6, 'the wide must not count');
    assert.strictEqual(s.score.overs, '1.0');
  });

  ok('a SHUFFLED event stream produces identical state', () => {
    const clean = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    const messy = liveState.buildLiveState(shuffle(asRows(over1)), { fixtureKey: MATCH });
    assert.deepStrictEqual(messy.score, clean.score);
    assert.deepStrictEqual(messy.players, clean.players);
  });

  ok('DUPLICATED events do not double-count', () => {
    const clean = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    const rows = asRows(over1);
    const doubled = liveState.buildLiveState(rows.concat(rows), { fixtureKey: MATCH });
    assert.deepStrictEqual(doubled.score, clean.score);
  });

  ok('shuffled AND duplicated together still produce identical state', () => {
    const clean = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    const rows = asRows(over1);
    const chaos = liveState.buildLiveState(shuffle(rows.concat(rows), 99), { fixtureKey: MATCH });
    assert.deepStrictEqual(chaos.score, clean.score);
    assert.deepStrictEqual(chaos.players, clean.players);
  });

  ok('batsman figures: runs, balls faced, boundaries', () => {
    const s = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    const bat = s.players.bat1.batting;
    assert.strictEqual(bat.runs, 13, '1+4+0+6+0+2');
    assert.strictEqual(bat.balls_faced, 6, 'the wide is not a ball faced');
    assert.strictEqual(bat.fours, 1);
    assert.strictEqual(bat.sixes, 1);
    assert.strictEqual(bat.dismissed, true);
  });

  ok('bowler figures: balls, runs conceded, wickets', () => {
    const s = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    const bowl = s.players.bowl1.bowling;
    assert.strictEqual(bowl.balls_bowled, 6);
    assert.strictEqual(bowl.runs_conceded, 14);
    assert.strictEqual(bowl.wickets, 1);
    assert.strictEqual(bowl.wides, 1);
  });

  // ---------------------------------------------------------------------------------------------
  section('live state: maidens');

  ok('a wicket-maiden is still a maiden', () => {
    const over = [
      ball(2, 1, { runs: 0 }), ball(2, 2, { runs: 0 }), ball(2, 3, { runs: 0 }),
      ball(2, 4, { runs: 0, wicket: true, wicket_type: 'bowled', out_player: 'bat1' }),
      ball(2, 5, { runs: 0 }), ball(2, 6, { runs: 0 })
    ];
    const s = liveState.buildLiveState(asRows(over), { fixtureKey: MATCH });
    assert.strictEqual(s.players.bowl1.bowling.maidens, 1);
  });

  ok('leg-byes do NOT break a maiden (they are not the bowler\'s runs)', () => {
    const over = [
      ball(3, 1, { runs: 0 }), ball(3, 2, { extra_type: 'legbye', extra_runs: 2 }),
      ball(3, 3, { runs: 0 }), ball(3, 4, { runs: 0 }),
      ball(3, 5, { runs: 0 }), ball(3, 6, { runs: 0 })
    ];
    const s = liveState.buildLiveState(asRows(over), { fixtureKey: MATCH });
    assert.strictEqual(s.players.bowl1.bowling.maidens, 1);
  });

  ok('a single run DOES break a maiden', () => {
    const over = [
      ball(4, 1, { runs: 0 }), ball(4, 2, { runs: 1 }), ball(4, 3, { runs: 0 }),
      ball(4, 4, { runs: 0 }), ball(4, 5, { runs: 0 }), ball(4, 6, { runs: 0 })
    ];
    const s = liveState.buildLiveState(asRows(over), { fixtureKey: MATCH });
    assert.strictEqual(s.players.bowl1.bowling.maidens, 0);
  });

  ok('an incomplete over is never a maiden', () => {
    const over = [ball(5, 1, { runs: 0 }), ball(5, 2, { runs: 0 }), ball(5, 3, { runs: 0 })];
    const s = liveState.buildLiveState(asRows(over), { fixtureKey: MATCH });
    assert.strictEqual(s.players.bowl1.bowling.maidens, 0);
  });

  // ---------------------------------------------------------------------------------------------
  section('live state: fielding credit');

  ok('a catch credits the fielder, not the bowler', () => {
    const rows = asRows([ball(6, 1, {
      wicket: true, wicket_type: 'caught', out_player: 'bat1', fielders: ['field9']
    })]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.field9.fielding.catches, 1);
    assert.strictEqual(s.players.bowl1.fielding.catches, 0);
    assert.strictEqual(s.players.bowl1.bowling.wickets, 1, 'the bowler still gets the wicket');
  });

  ok('a run-out gives the bowler no wicket at all', () => {
    const rows = asRows([ball(6, 2, {
      wicket: true, wicket_type: 'run out', out_player: 'bat2', fielders: ['field3']
    })]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.bowl1.bowling.wickets, 0);
    assert.strictEqual(s.players.field3.fielding.run_outs, 1);
  });

  ok('a two-fielder run-out is recorded as assisted for both', () => {
    const rows = asRows([ball(6, 3, {
      wicket: true, wicket_type: 'run out', out_player: 'bat2', fielders: ['field3', 'field4']
    })]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.field3.fielding.run_outs_assisted, 1);
    assert.strictEqual(s.players.field4.fielding.run_outs_assisted, 1);
  });

  ok('a stumping credits the keeper', () => {
    const rows = asRows([ball(6, 4, {
      wicket: true, wicket_type: 'stumped', out_player: 'bat1', fielders: ['keeper1']
    })]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.keeper1.fielding.stumpings, 1);
  });

  // ---------------------------------------------------------------------------------------------
  section('live state: an innings transition seeds the next innings before its first ball');

  const marker = (type, id) => ({ event_id: id, match_key: MATCH, event_type: type });

  ok('nextDeliveryKey is null before anything has happened', () => {
    const s = liveState.buildLiveState([], { fixtureKey: MATCH });
    assert.strictEqual(liveState.nextDeliveryKey(s), null);
  });

  ok('match_start alone (no ball bowled yet) makes the very first delivery addressable', () => {
    const rows = asRows([marker('match_start', 'ms1')]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(liveState.nextDeliveryKey(s), '1:0:1:1', 'without this, Boundary Baazi has no market for ball 1 of the match');
    assert.strictEqual(s.score.legal_balls, 0);
  });

  ok('a redelivered match_start does not create a second innings-1 entry', () => {
    const rows = asRows([marker('match_start', 'ms1'), marker('match_start', 'ms1_retry')]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.innings.filter(i => i.number === 1 && !i.super_over).length, 1);
  });

  ok('an innings_break after innings 1 addresses innings 2 ball 1, not the finished innings 1', () => {
    const innings1 = [];
    for (let over = 0; over < 20; over += 1) {
      for (let b = 1; b <= 6; b += 1) innings1.push(ball(over, b, { runs: 1, innings: 1 }));
    }
    const rows = asRows([marker('match_start', 'ms1'), ...innings1, marker('innings_break', 'brk1')]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    // Before this fix, current_innings still pointed at the just-finished innings 1 (120 legal
    // balls bowled), so nextDeliveryKey named a delivery ("1:20:1") that will never be bowled -
    // the real innings-2 opener would silently never get a market at all.
    assert.strictEqual(liveState.nextDeliveryKey(s), '2:0:1:1');
    assert.strictEqual(s.current_innings.number, 2);
    assert.strictEqual(s.current_innings.legal_balls, 0);
  });

  ok('the first ball of innings 2, once it actually arrives, lands on the seeded entry rather than a duplicate', () => {
    const rows = asRows([
      marker('match_start', 'ms2'),
      ball(0, 1, { runs: 1, innings: 1 }),
      marker('innings_break', 'brk2'),
      ball(0, 1, { runs: 4, innings: 2, batsman: 'bat3' })
    ]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.innings.filter(i => i.number === 2 && !i.super_over).length, 1, 'no duplicate innings-2 entry');
    assert.strictEqual(s.current_innings.runs, 4);
    assert.strictEqual(s.current_innings.legal_balls, 1);
  });

  ok('the same guarantee holds on a full-log recompute, where markers replay AFTER every ball', () => {
    // orderEvents ranks a marker with no innings/over/ball of its own last, so a full-log replay
    // processes every real ball of both innings BEFORE it ever reaches match_start/innings_break -
    // the exact ordering that broke a naive "count what's been seen so far, inline" seeding attempt.
    const rows = asRows([
      marker('match_start', 'ms3'),
      ball(0, 1, { runs: 1, innings: 1 }),
      ball(0, 2, { runs: 2, innings: 1 }),
      marker('innings_break', 'brk3'),
      ball(0, 1, { runs: 4, innings: 2, batsman: 'bat3' }),
      ball(0, 2, { runs: 6, innings: 2, batsman: 'bat3' })
    ]);
    const inOrder = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    const shuffled = liveState.buildLiveState(shuffle(rows, 13), { fixtureKey: MATCH });
    for (const s of [inOrder, shuffled]) {
      assert.strictEqual(s.innings.filter(i => !i.super_over).length, 2, 'exactly innings 1 and 2, no phantom third');
      assert.strictEqual(s.current_innings.number, 2);
      assert.strictEqual(s.current_innings.runs, 10);
    }
  });

  // ---------------------------------------------------------------------------------------------
  section('edge cases (docs/YOUR11-SCOPE.md section 3)');

  ok('retired hurt is not a dismissal and takes no duck penalty', () => {
    const rows = asRows([ball(7, 1, {
      runs: 0, wicket: true, wicket_type: 'retired hurt', out_player: 'bat1'
    })]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.bat1.batting.dismissed, false);
    assert.strictEqual(s.players.bat1.batting.retired_hurt, true);
    assert.strictEqual(s.score.wickets, 0, 'retired hurt does not cost the team a wicket');
  });

  ok('retired OUT is a dismissal', () => {
    const rows = asRows([ball(7, 2, {
      runs: 0, wicket: true, wicket_type: 'retired out', out_player: 'bat1'
    })]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.bat1.batting.dismissed, true);
    assert.strictEqual(s.score.wickets, 1);
  });

  ok('super-over deliveries are EXCLUDED from fantasy figures', () => {
    const rows = asRows([
      ball(1, 1, { runs: 4, batsman: 'bat1' }),
      ball(1, 1, { runs: 6, batsman: 'bat1', super_over: true, innings: 3 })
    ]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.players.bat1.batting.runs, 4, 'fantasy view must freeze before the super over');
    assert.strictEqual(s.super_over, true);
  });

  ok('...but the super over IS visible in the match view', () => {
    const rows = asRows([
      ball(1, 1, { runs: 4, batsman: 'bat1' }),
      ball(1, 1, { runs: 6, batsman: 'bat1', super_over: true, innings: 3 })
    ]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.match_players.bat1.batting.runs, 10);
  });

  ok('an abandoned match is flagged and ended', () => {
    const rows = asRows([
      ball(1, 1, { runs: 1 }),
      { event_id: 'ab1', match_key: MATCH, event_type: 'abandoned' }
    ]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.abandoned, true);
    assert.strictEqual(s.status, 'abandoned');
  });

  ok('the confirmed XI arrives from the feed, and gates the lock', () => {
    const rows = asRows([
      { event_id: 'xi1', match_key: MATCH, event_type: 'playing_xi', playing_xi: ['p1', 'p2', 'p3'] }
    ]);
    const s = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(s.lineups_confirmed, true);
    assert.strictEqual(s.confirmed_xi.length, 3);
  });

  ok('no lineup event means teams are NOT locked', () => {
    const s = liveState.buildLiveState(asRows(over1), { fixtureKey: MATCH });
    assert.strictEqual(s.lineups_confirmed, false);
  });

  // ---------------------------------------------------------------------------------------------
  section('event log: append-only and deduplicating');

  await okAsync('an event is stored', async () => {
    install();
    const r = await eventLog.append(ball(1, 1, { runs: 4, event_id: 'unique_1' }), { fixtureKey: MATCH });
    assert.strictEqual(r.stored, true);
    assert.strictEqual(r.duplicate, false);
  });

  await okAsync('the SAME event id is a no-op, not a double-write', async () => {
    const store = install();
    const payload = ball(1, 1, { runs: 4, event_id: 'unique_2' });
    await eventLog.append(payload, { fixtureKey: MATCH });
    const second = await eventLog.append(payload, { fixtureKey: MATCH });
    assert.strictEqual(second.stored, false);
    assert.strictEqual(second.duplicate, true);
    assert.strictEqual(store._events.length, 1);
  });

  await okAsync('a payload with no provider id still deduplicates by content', async () => {
    const store = install();
    const payload = { match_key: MATCH, event_type: 'ball', over: 1, ball: 1, bowler: 'b', batsman_run: 2 };
    await eventLog.append(payload, { fixtureKey: MATCH });
    await eventLog.append({ ...payload }, { fixtureKey: MATCH });
    assert.strictEqual(store._events.length, 1, 'identical content must collide on the derived id');
  });

  await okAsync('an event with no resolvable match key is refused, not orphaned', async () => {
    const sink = [];
    install({ sink });
    const r = await eventLog.append({ event_type: 'ball', over: 1 }, {});
    assert.strictEqual(r.stored, false);
    assert.strictEqual(r.error, 'no_fixture_key');
    assert.ok(sink.some(l => l.level === 'error'), 'must be logged loudly');
  });

  await okAsync('the raw provider payload is stored verbatim', async () => {
    const store = install();
    const payload = ball(1, 1, { runs: 3, event_id: 'verbatim_1' });
    payload.provider_specific_field = { nested: 'value' };
    await eventLog.append(payload, { fixtureKey: MATCH });
    assert.deepStrictEqual(store._events[0].payload.provider_specific_field, { nested: 'value' });
  });

  // ---------------------------------------------------------------------------------------------
  section('collector: signature verification');

  const crypto = require('crypto');
  const originalSecret = config.ROANUZ_WEBHOOK_SECRET;
  const originalProd = config.IS_PRODUCTION;

  ok('a correctly signed body is accepted', () => {
    config.ROANUZ_WEBHOOK_SECRET = 'test_secret_value';
    const body = Buffer.from(JSON.stringify({ a: 1 }));
    const sig = crypto.createHmac('sha256', 'test_secret_value').update(body).digest('hex');
    assert.strictEqual(collector.verifySignature(body, sig).ok, true);
  });

  ok('a "sha256=" prefixed signature is accepted', () => {
    const body = Buffer.from(JSON.stringify({ a: 1 }));
    const sig = crypto.createHmac('sha256', 'test_secret_value').update(body).digest('hex');
    assert.strictEqual(collector.verifySignature(body, `sha256=${sig}`).ok, true);
  });

  ok('a FORGED body is rejected', () => {
    const body = Buffer.from(JSON.stringify({ a: 1 }));
    const sig = crypto.createHmac('sha256', 'test_secret_value').update(body).digest('hex');
    const tampered = Buffer.from(JSON.stringify({ a: 999 }));
    assert.strictEqual(collector.verifySignature(tampered, sig).ok, false);
  });

  ok('a missing signature is rejected when a secret is configured', () => {
    assert.strictEqual(collector.verifySignature(Buffer.from('{}'), null).ok, false);
  });

  ok('a wrong-length signature is rejected without throwing', () => {
    assert.strictEqual(collector.verifySignature(Buffer.from('{}'), 'short').ok, false);
  });

  ok('production with NO secret refuses every delivery', () => {
    config.ROANUZ_WEBHOOK_SECRET = '';
    config.IS_PRODUCTION = true;
    const v = collector.verifySignature(Buffer.from('{}'), null);
    assert.strictEqual(v.ok, false);
    assert.strictEqual(v.reason, 'no_secret_configured');
    config.IS_PRODUCTION = originalProd;
  });

  // The URL/header-embedded secret path (2026-08-24) - the confirmed-workable mechanism, since
  // Roanuz's own docs describe no signature header at all, only that their console accepts an
  // arbitrary webhook URL to deliver to.
  ok('a matching URL-embedded secret is accepted with no signature at all', () => {
    config.ROANUZ_WEBHOOK_SECRET = 'test_secret_value';
    const v = collector.verifySignature(Buffer.from('{}'), null, { secretParam: 'test_secret_value' });
    assert.strictEqual(v.ok, true);
    assert.strictEqual(v.reason, 'verified_url_secret');
  });

  ok('a WRONG URL-embedded secret is rejected, not silently ignored', () => {
    const v = collector.verifySignature(Buffer.from('{}'), null, { secretParam: 'guessed_wrong' });
    assert.strictEqual(v.ok, false);
  });

  ok('a correct HMAC signature still works when no URL secret is supplied', () => {
    const body = Buffer.from(JSON.stringify({ a: 1 }));
    const sig = crypto.createHmac('sha256', 'test_secret_value').update(body).digest('hex');
    const v = collector.verifySignature(body, sig, { secretParam: null });
    assert.strictEqual(v.ok, true);
    assert.strictEqual(v.reason, 'verified_hmac');
  });

  config.ROANUZ_WEBHOOK_SECRET = originalSecret;

  // ---------------------------------------------------------------------------------------------
  section('collector: gzip-compressed deliveries');

  const zlib = require('zlib');

  ok('a gzip-compressed body is decompressed and parsed', () => {
    const payload = { event_type: 'toss', match_key: MATCH, winner: 'A' };
    const gz = zlib.gzipSync(Buffer.from(JSON.stringify(payload)));
    assert.deepStrictEqual(collector.parseBody(gz), payload);
  });

  ok('a plain, uncompressed body still parses (Roanuz\'s compression is documented, not guaranteed forever)', () => {
    const payload = { event_type: 'toss', match_key: MATCH };
    assert.deepStrictEqual(collector.parseBody(Buffer.from(JSON.stringify(payload))), payload);
  });

  ok('a body that merely starts with the gzip magic bytes fails cleanly, not with a throw', () => {
    const fakeGzip = Buffer.concat([Buffer.from([0x1f, 0x8b]), Buffer.from('not actually gzip data')]);
    assert.strictEqual(collector.parseBody(fakeGzip), null);
  });

  // ---------------------------------------------------------------------------------------------
  section('snapshot adapter — Roanuz\'s documented full-match-state push shape');

  const SNAP_FIXTURE = 'snap_match_1';

  ok('a discrete event is NOT mistaken for a snapshot', () => {
    assert.strictEqual(snapshotAdapter.looksLikeSnapshot({ event_type: 'ball', over: 0, ball: 1 }), false);
  });

  ok('an object carrying related_balls/score/players IS recognised as a snapshot', () => {
    assert.strictEqual(snapshotAdapter.looksLikeSnapshot({ key: SNAP_FIXTURE, score: {}, players: {} }), true);
    assert.strictEqual(snapshotAdapter.looksLikeSnapshot({ key: SNAP_FIXTURE, related_balls: [] }), true);
  });

  ok('a toss and a confirmed XI in the snapshot become discrete toss/lineup events', () => {
    const snap = {
      key: SNAP_FIXTURE, score: {},
      toss: { winner: 'team_a', decision: 'bat' },
      players: { confirmed_xi: [{ key: 'p1' }, { key: 'p2' }] }
    };
    const events = snapshotAdapter.extractEventsFromSnapshot(snap, { fixtureKey: SNAP_FIXTURE });
    const toss = events.find(e => e.event_type === 'toss');
    const lineup = events.find(e => e.event_type === 'lineup');
    assert.strictEqual(toss.winner, 'team_a');
    assert.deepStrictEqual(lineup.playing_xi, ['p1', 'p2']);
  });

  ok('related_balls entries become normalize.js-shaped discrete ball events', () => {
    const snap = {
      key: SNAP_FIXTURE, score: {},
      related_balls: [
        { innings: 1, over: 0, ball: 1, batsman: { key: 'bat1' }, bowler: { key: 'bowl1' }, score: { batsman_runs: 4 } },
        { innings: 1, over: 0, ball: 2, batsman: { key: 'bat1' }, bowler: { key: 'bowl1' }, is_wicket: true, dismissal_type: 'bowled', out_player: { key: 'bat1' } }
      ]
    };
    const events = snapshotAdapter.extractEventsFromSnapshot(snap, { fixtureKey: SNAP_FIXTURE });
    const balls = events.filter(e => e.event_type === 'ball');
    assert.strictEqual(balls.length, 2);
    assert.strictEqual(balls[0].batsman_run, 4);
    assert.strictEqual(balls[1].is_wicket, true);
    assert.strictEqual(balls[1].out_player, 'bat1');

    // And they normalise correctly through the SAME normalizer every other event goes through -
    // the whole point of adapting at the boundary rather than teaching live-state a second shape.
    const norm = normalize.normalizeEvent(balls[0], { fixtureKey: SNAP_FIXTURE });
    assert.strictEqual(norm.event_type, 'ball');
    assert.strictEqual(norm.detail.batsman_run, 4);
  });

  ok('the same snapshot, delivered twice, produces IDENTICAL event ids - safe to redeliver', () => {
    const snap = {
      key: SNAP_FIXTURE, score: {},
      related_balls: [{ innings: 1, over: 0, ball: 1, batsman: { key: 'bat1' }, bowler: { key: 'bowl1' }, score: { batsman_runs: 1 } }]
    };
    const first = snapshotAdapter.extractEventsFromSnapshot(snap, { fixtureKey: SNAP_FIXTURE });
    const second = snapshotAdapter.extractEventsFromSnapshot(JSON.parse(JSON.stringify(snap)), { fixtureKey: SNAP_FIXTURE });
    assert.strictEqual(first.find(e => e.event_type === 'ball').event_id, second.find(e => e.event_type === 'ball').event_id);
  });

  await okAsync('feeding a growing snapshot into the real event log only ever appends the NEW ball', async () => {
    install();
    const early = { key: SNAP_FIXTURE, score: {}, related_balls: [
      { innings: 1, over: 0, ball: 1, batsman: { key: 'bat1' }, bowler: { key: 'bowl1' }, score: { batsman_runs: 1 } }
    ] };
    const grown = { key: SNAP_FIXTURE, score: {}, related_balls: [
      { innings: 1, over: 0, ball: 1, batsman: { key: 'bat1' }, bowler: { key: 'bowl1' }, score: { batsman_runs: 1 } },
      { innings: 1, over: 0, ball: 2, batsman: { key: 'bat1' }, bowler: { key: 'bowl1' }, score: { batsman_runs: 4 } }
    ] };

    for (const e of snapshotAdapter.extractEventsFromSnapshot(early, { fixtureKey: SNAP_FIXTURE })) {
      await eventLog.append(e, { fixtureKey: SNAP_FIXTURE });
    }
    let stored = 0, dup = 0;
    for (const e of snapshotAdapter.extractEventsFromSnapshot(grown, { fixtureKey: SNAP_FIXTURE })) {
      const r = await eventLog.append(e, { fixtureKey: SNAP_FIXTURE });
      if (r.stored) stored += 1;
      if (r.duplicate) dup += 1;
    }
    assert.strictEqual(stored, 1, 'only the genuinely new ball should be stored');
    assert.strictEqual(dup, 1, 'the ball already seen should dedupe, not double-write');
  });

  ok('the mock transport\'s canned fixtures shape correctly (real network never touched)', () => {
    const mock = roanuzTransport.createMockTransport();
    assert.strictEqual(mock.kind, 'mock');
  });

  await okAsync('the mock transport answers auth, fixtures, squad and subscribe without any network call', async () => {
    const mock = roanuzTransport.createMockTransport();
    const auth = await mock.authenticate('proj', 'key');
    assert.strictEqual(auth.ok, true);
    assert.ok(auth.data.data.token);

    const fixtures = await mock.call('GET', 'proj', `/tournament/${roanuzTransport.MOCK_TOURNAMENT_KEY}/fixtures/`, {});
    assert.strictEqual(fixtures.ok, true);
    assert.ok(Array.isArray(fixtures.data.data.fixtures) && fixtures.data.data.fixtures.length > 0);

    const squad = await mock.call('GET', 'proj', `/tournament/${roanuzTransport.MOCK_TOURNAMENT_KEY}/team/${roanuzTransport.MOCK_TEAM_A.key}/`, {});
    assert.strictEqual(squad.data.data.players.length, 13);

    const subscribe = await mock.call('POST', 'proj', '/match/mock_match_001/subscribe/', { body: { method: 'web_hook' } });
    assert.strictEqual(subscribe.data.data.subscribed, true);
  });

  // ---------------------------------------------------------------------------------------------
  section('collector: ingest');

  await okAsync('a batch of events is ingested', async () => {
    install();
    const r = await collector.ingest(
      JSON.stringify({ match_key: MATCH, events: [ball(1, 1, { runs: 1, event_id: 'b1' }), ball(1, 2, { runs: 4, event_id: 'b2' })] }),
      { skipSignature: true }
    );
    assert.strictEqual(r.ok, true);
    assert.strictEqual(r.stored, 2);
  });

  await okAsync('REDELIVERY of the same batch stores nothing further', async () => {
    const store = install();
    const body = JSON.stringify({ match_key: MATCH, events: [ball(1, 1, { runs: 1, event_id: 'r1' })] });
    await collector.ingest(body, { skipSignature: true });
    const second = await collector.ingest(body, { skipSignature: true });
    assert.strictEqual(second.ok, true, 'a duplicate is a success, not an error');
    assert.strictEqual(second.stored, 0);
    assert.strictEqual(second.duplicates, 1);
    assert.strictEqual(store._events.length, 1);
  });

  await okAsync('a single (non-batched) event payload is handled', async () => {
    const store = install();
    await collector.ingest(JSON.stringify(ball(1, 1, { runs: 6, event_id: 'single1' })), { skipSignature: true });
    assert.strictEqual(store._events.length, 1);
  });

  await okAsync('an unparseable body is a 400, not a crash', async () => {
    install();
    const r = await collector.ingest('not json at all', { skipSignature: true });
    assert.strictEqual(r.ok, false);
    assert.strictEqual(r.status, 400);
  });

  await okAsync('an unsigned delivery is rejected before anything is written', async () => {
    config.ROANUZ_WEBHOOK_SECRET = 'a_secret';
    const store = install();
    const r = await collector.ingest(JSON.stringify({ match_key: MATCH, events: [ball(1, 1, {})] }), { signature: 'wrong' });
    assert.strictEqual(r.ok, false);
    assert.strictEqual(r.status, 401);
    assert.strictEqual(store._events.length, 0, 'nothing may reach the permanent log');
    config.ROANUZ_WEBHOOK_SECRET = originalSecret;
  });

  // ---------------------------------------------------------------------------------------------
  section('health monitor: 90-second stall');

  ok('a quiet feed inside the window is not a stall', () => {
    const sink = [];
    install({ sink });
    health.track(MATCH);
    health.recordEvent(MATCH);
    const stalls = health.check(Date.now() + config.CRICKET_STALL_MS - 1000);
    assert.strictEqual(stalls.length, 0);
  });

  ok('silence beyond the window IS a stall, and is logged at error level', () => {
    const sink = [];
    install({ sink });
    health.track(MATCH);
    health.recordEvent(MATCH);
    const stalls = health.check(Date.now() + config.CRICKET_STALL_MS + 1000);
    assert.strictEqual(stalls.length, 1);
    assert.ok(sink.some(l => l.level === 'error' && /STALLED/.test(l.msg)), 'must log at error level');
  });

  ok('a stall is reported once, not on every sweep', () => {
    const sink = [];
    install({ sink });
    health.track(MATCH);
    health.recordEvent(MATCH);
    const t = Date.now() + config.CRICKET_STALL_MS + 1000;
    health.check(t);
    const second = health.check(t + 5000);
    assert.strictEqual(second.length, 0, 'a still-stalled feed must not re-alert every sweep');
  });

  ok('an arriving event clears the stall and logs the recovery', () => {
    const sink = [];
    install({ sink });
    health.track(MATCH);
    health.recordEvent(MATCH);
    health.check(Date.now() + config.CRICKET_STALL_MS + 1000);
    health.recordEvent(MATCH);
    assert.ok(sink.some(l => /recovered/.test(l.msg)));
    assert.strictEqual(health.report().stalled, 0);
  });

  ok('the health report states plainly that alerting is not wired', () => {
    install();
    assert.strictEqual(health.report().alerting, 'log-only (no channel wired)');
  });

  ok('the stall hook is the single seam an alert channel plugs into', () => {
    install();
    let fired = null;
    health.onStall(info => { fired = info; });
    health.track(MATCH);
    health.recordEvent(MATCH);
    health.check(Date.now() + config.CRICKET_STALL_MS + 1000);
    assert.ok(fired && fired.fixtureKey === MATCH);
  });

  // ---------------------------------------------------------------------------------------------
  section('fan-out');

  ok('a subscriber receives published frames', () => {
    install();
    const written = [];
    const res = { writeHead() {}, flushHeaders() {}, write(chunk) { written.push(chunk); return true; }, end() {} };
    const req = { on() {} };
    fanout.subscribe(MATCH, req, res, { initialState: { score: { runs: 0 } } });
    fanout.publish(MATCH, 'state', { score: { runs: 14 } });
    assert.ok(written.join('').includes('"runs":14'));
    fanout.shutdown();
  });

  ok('publishing to a fixture with no subscribers is harmless', () => {
    install();
    assert.strictEqual(fanout.publish('nobody_here', 'state', {}), 0);
  });

  ok('a dead socket is dropped rather than retried forever', () => {
    install();
    const res = {
      writeHead() {}, flushHeaders() {},
      write() { throw new Error('EPIPE'); },
      end() {}
    };
    fanout.subscribe(MATCH, { on() {} }, res, {});
    fanout.publish(MATCH, 'state', {});
    assert.strictEqual(fanout.stats().subscribers, 0);
    fanout.shutdown();
  });

  ok('SSE responses are marked no-transform so compression cannot buffer them', () => {
    install();
    let headers = null;
    const res = { writeHead(code, h) { headers = h; }, flushHeaders() {}, write() { return true; }, end() {} };
    fanout.subscribe(MATCH, { on() {} }, res, {});
    assert.ok(/no-transform/.test(headers['Cache-Control']));
    assert.strictEqual(headers['Content-Type'], 'text/event-stream');
    fanout.shutdown();
  });

  // ---------------------------------------------------------------------------------------------
  section('Boundary Baazi: the lock is event-gated, never timed');

  ok('the next delivery key advances only when a ball is recorded', () => {
    const a = liveState.buildLiveState(asRows([ball(1, 1, { runs: 1 })]), { fixtureKey: MATCH });
    const b = liveState.buildLiveState(asRows([ball(1, 1, { runs: 1 }), ball(1, 2, { runs: 0 })]), { fixtureKey: MATCH });
    assert.notStrictEqual(liveState.nextDeliveryKey(a), liveState.nextDeliveryKey(b));
  });

  ok('the key is derived from ball count, not from any clock', () => {
    const rows = asRows([ball(1, 1, { runs: 1 })]);
    const first = liveState.nextDeliveryKey(liveState.buildLiveState(rows, { fixtureKey: MATCH }));
    const later = liveState.nextDeliveryKey(liveState.buildLiveState(rows, { fixtureKey: MATCH }));
    assert.strictEqual(first, later, 'time passing must not move the lock');
  });

  ok('a wide does not advance cricket\'s own over.ball numbering, but DOES get its own delivery key', () => {
    // The human-facing over.ball label must not move on a wide - a broadcast still calls the legal
    // re-bowl "the same ball". But the full delivery key must still change, or the market for that
    // re-bowl would collide with the wide's own (see boundary.js: extras and their re-bowl share an
    // over.ball position, so only the trailing attempt counter tells their markets apart).
    const a = liveState.buildLiveState(asRows([ball(1, 1, { runs: 1 })]), { fixtureKey: MATCH });
    const withWide = liveState.buildLiveState(
      asRows([ball(1, 1, { runs: 1 }), ball(1, 2, { extra_type: 'wide', extra_runs: 1 })]),
      { fixtureKey: MATCH }
    );
    const keyA = liveState.nextDeliveryKey(a);
    const keyWide = liveState.nextDeliveryKey(withWide);
    const overBallOf = k => k.split(':').slice(0, 3).join(':');
    assert.strictEqual(overBallOf(keyA), overBallOf(keyWide), 'the over.ball portion must be identical');
    assert.notStrictEqual(keyA, keyWide, 'but the full key - and so the market - must not collide');
  });

  ok('an ended match has no next delivery', () => {
    const rows = asRows([ball(1, 1, { runs: 1 }), { event_id: 'me1', match_key: MATCH, event_type: 'match_end' }]);
    assert.strictEqual(liveState.nextDeliveryKey(liveState.buildLiveState(rows, { fixtureKey: MATCH })), null);
  });

  // ---------------------------------------------------------------------------------------------
  section('replay: a full innings end to end');

  await okAsync('120 deliveries ingest, dedupe, and recompute consistently', async () => {
    const store = install();
    const payloads = [];
    for (let over = 1; over <= 20; over++) {
      for (let b = 1; b <= 6; b++) {
        payloads.push(ball(over, b, {
          runs: (over + b) % 7 === 0 ? 4 : (over + b) % 3,
          batsman: b % 2 === 0 ? 'bat1' : 'bat2',
          bowler: `bowl${(over % 5) + 1}`,
          event_id: `full_${over}_${b}`
        }));
      }
    }

    // Deliver everything twice, out of order, exactly as an at-least-once feed can.
    for (const p of shuffle(payloads.concat(payloads), 42)) {
      await collector.ingest(JSON.stringify(p), { skipSignature: true });
    }

    assert.strictEqual(store._events.length, 120, 'redelivery must not inflate the log');

    const rows = await eventLog.readEvents(MATCH);
    const state = liveState.buildLiveState(rows, { fixtureKey: MATCH });
    assert.strictEqual(state.score.legal_balls, 120);
    assert.strictEqual(state.score.overs, '20.0');

    const expected = payloads.reduce((sum, p) => sum + p.batsman_run, 0);
    assert.strictEqual(state.score.runs, expected, 'total must equal the sum of every delivery');
  });

  // ---------------------------------------------------------------------------------------------
  section('scoring engine');

  install();
  const RULES = configStore.DEFAULT_SCORING.T20;

  const figures = (bat = {}, bowl = {}, field = {}) => ({
    batting: { runs: 0, balls_faced: 0, fours: 0, sixes: 0, dots: 0, dismissed: false, retired_hurt: false, batted: true, ...bat },
    bowling: { balls_bowled: 0, runs_conceded: 0, wickets: 0, maidens: 0, dots: 0, wides: 0, noballs: 0, ...bowl },
    fielding: { catches: 0, stumpings: 0, run_outs: 0, run_outs_assisted: 0, ...field }
  });

  ok('runs and boundary bonuses are counted', () => {
    const r = scoring.scorePlayer(figures({ runs: 20, balls_faced: 15, fours: 2, sixes: 1 }), RULES);
    assert.strictEqual(r.breakdown.runs, 20);
    assert.strictEqual(r.breakdown.fours, 2);
    assert.strictEqual(r.breakdown.sixes, 2);
  });

  ok('the breakdown always sums exactly to the total', () => {
    const r = scoring.scorePlayer(
      figures({ runs: 54, balls_faced: 30, fours: 6, sixes: 2 }, { balls_bowled: 24, runs_conceded: 20, wickets: 3 }, { catches: 1 }),
      RULES
    );
    const sum = Object.values(r.breakdown).reduce((a, b) => a + b, 0);
    assert.strictEqual(Math.round(sum * 100) / 100, r.total, 'a points screen that does not add up is worse than none');
  });

  ok('only the highest milestone applies by default', () => {
    const r = scoring.scorePlayer(figures({ runs: 105, balls_faced: 60 }), RULES);
    assert.strictEqual(r.breakdown.milestone, RULES.runs_100_bonus);
  });

  ok('milestones stack when configured to', () => {
    const cumulative = { ...RULES, milestones_cumulative: true };
    const r = scoring.scorePlayer(figures({ runs: 105, balls_faced: 60 }), cumulative);
    assert.strictEqual(
      r.breakdown.milestone,
      RULES.runs_100_bonus + RULES.runs_50_bonus + RULES.runs_25_bonus
    );
  });

  ok('a duck is penalised', () => {
    const r = scoring.scorePlayer(figures({ runs: 0, balls_faced: 3, dismissed: true }), RULES, { role: 'BAT' });
    assert.strictEqual(r.breakdown.duck, RULES.duck_penalty);
  });

  ok('a BOWLER is exempt from the duck penalty', () => {
    const r = scoring.scorePlayer(figures({ runs: 0, balls_faced: 3, dismissed: true }), RULES, { role: 'BOWL' });
    assert.strictEqual(r.breakdown.duck, undefined);
  });

  ok('retiring hurt on 0 is NOT a duck', () => {
    const r = scoring.scorePlayer(
      figures({ runs: 0, balls_faced: 3, dismissed: false, retired_hurt: true }), RULES, { role: 'BAT' }
    );
    assert.strictEqual(r.breakdown.duck, undefined);
  });

  ok('a player who never batted takes no duck penalty', () => {
    const r = scoring.scorePlayer(figures({ runs: 0, batted: false, dismissed: false }), RULES, { role: 'BAT' });
    assert.strictEqual(r.breakdown.duck, undefined);
  });

  ok('strike rate is ignored below the minimum balls faced', () => {
    const r = scoring.scorePlayer(figures({ runs: 12, balls_faced: 3 }), RULES);
    assert.strictEqual(r.breakdown.strike_rate, undefined, 'a first-ball six must not earn the top bonus');
  });

  ok('a high strike rate over the minimum earns the band', () => {
    const r = scoring.scorePlayer(figures({ runs: 40, balls_faced: 20 }), RULES); // SR 200
    assert.strictEqual(r.breakdown.strike_rate, 6);
  });

  ok('a low strike rate is penalised', () => {
    const r = scoring.scorePlayer(figures({ runs: 8, balls_faced: 20 }), RULES); // SR 40
    assert.strictEqual(r.breakdown.strike_rate, -6);
  });

  ok('economy is ignored below the minimum overs', () => {
    const r = scoring.scorePlayer(figures({}, { balls_bowled: 6, runs_conceded: 2 }), RULES);
    assert.strictEqual(r.breakdown.economy, undefined);
  });

  ok('a tight economy over the minimum earns the band', () => {
    const r = scoring.scorePlayer(figures({}, { balls_bowled: 24, runs_conceded: 12 }), RULES); // 3.0
    assert.strictEqual(r.breakdown.economy, 6);
  });

  ok('an expensive spell is penalised', () => {
    const r = scoring.scorePlayer(figures({}, { balls_bowled: 24, runs_conceded: 60 }), RULES); // 15.0
    assert.strictEqual(r.breakdown.economy, -6);
  });

  ok('band selection ignores the order the table was saved in', () => {
    const shuffled = { ...RULES, strike_rate: { min_balls: 10, bands: shuffle(RULES.strike_rate.bands, 3) } };
    const a = scoring.scorePlayer(figures({ runs: 40, balls_faced: 20 }), RULES);
    const b = scoring.scorePlayer(figures({ runs: 40, balls_faced: 20 }), shuffled);
    assert.strictEqual(a.breakdown.strike_rate, b.breakdown.strike_rate);
  });

  ok('a five-wicket haul earns the top bonus only', () => {
    const r = scoring.scorePlayer(figures({}, { balls_bowled: 24, runs_conceded: 30, wickets: 5 }), RULES);
    assert.strictEqual(r.breakdown.wicket_haul, RULES.wickets_5_bonus);
    assert.strictEqual(r.breakdown.wickets, 5 * RULES.wicket);
  });

  ok('three catches earn the fielding bonus', () => {
    const r = scoring.scorePlayer(figures({}, {}, { catches: 3 }), RULES);
    assert.strictEqual(r.breakdown.catch_bonus, RULES.catches_3_bonus);
  });

  ok('two catches do not', () => {
    const r = scoring.scorePlayer(figures({}, {}, { catches: 2 }), RULES);
    assert.strictEqual(r.breakdown.catch_bonus, undefined);
  });

  ok('a player outside the confirmed XI gets no participation points', () => {
    const r = scoring.scorePlayer(figures({ runs: 5, balls_faced: 5 }), RULES, { inPlayingXi: false });
    assert.strictEqual(r.breakdown.in_playing_xi, undefined);
  });

  // ---------------------------------------------------------------------------------------------
  section('scoring: captain and vice-captain');

  const teamState = {
    players: {
      p1: figures({ runs: 50, balls_faced: 30, fours: 4, sixes: 2 }),
      p2: figures({ runs: 20, balls_faced: 18 }),
      p3: figures({}, { balls_bowled: 24, runs_conceded: 24, wickets: 2 })
    }
  };
  const entry = { players: ['p1', 'p2', 'p3'], captain: 'p1', vice_captain: 'p2' };

  ok('the captain scores exactly double', () => {
    const t = scoring.scoreTeam(entry, teamState, RULES);
    assert.strictEqual(t.players.p1.multiplier, 2);
    assert.strictEqual(t.players.p1.points, round(t.players.p1.base_points * 2));
  });

  ok('the vice-captain scores exactly 1.5x', () => {
    const t = scoring.scoreTeam(entry, teamState, RULES);
    assert.strictEqual(t.players.p2.multiplier, 1.5);
    assert.strictEqual(t.players.p2.points, round(t.players.p2.base_points * 1.5));
  });

  ok('the multiplier applies to bonuses too, not just base runs', () => {
    const t = scoring.scoreTeam(entry, teamState, RULES);
    // p1 has a fifty bonus and a strike-rate bonus; doubling the final total is the check.
    assert.ok(t.players.p1.breakdown.milestone > 0, 'precondition: p1 reached a milestone');
    assert.strictEqual(t.players.p1.points, round(t.players.p1.base_points * 2));
  });

  ok('an ordinary player is not multiplied', () => {
    const t = scoring.scoreTeam(entry, teamState, RULES);
    assert.strictEqual(t.players.p3.multiplier, 1);
  });

  ok('the team total equals the sum of its players', () => {
    const t = scoring.scoreTeam(entry, teamState, RULES);
    const sum = Object.values(t.players).reduce((a, p) => a + p.points, 0);
    assert.strictEqual(round(sum), t.total);
  });

  ok('a player with no figures scores participation only, not a crash', () => {
    const t = scoring.scoreTeam(
      { players: ['ghost'], captain: 'ghost', vice_captain: null }, { players: {} }, RULES
    );
    assert.strictEqual(t.players.ghost.base_points, RULES.in_playing_xi);
  });

  // ---------------------------------------------------------------------------------------------
  section('scoring: reconciliation against the official scorecard');

  ok('matching figures reconcile cleanly', () => {
    const state = { players: { p1: figures({ runs: 50, balls_faced: 30 }) } };
    const official = { players: { p1: { runs: 50, balls_faced: 30 } } };
    assert.strictEqual(scoring.reconcile(state, official).ok, true);
  });

  ok('a mismatch is caught and named', () => {
    const state = { players: { p1: figures({ runs: 50, balls_faced: 30 }) } };
    const official = { players: { p1: { runs: 52, balls_faced: 30 } } };
    const r = scoring.reconcile(state, official);
    assert.strictEqual(r.ok, false);
    assert.strictEqual(r.discrepancies[0].field, 'runs');
    assert.strictEqual(r.discrepancies[0].computed, 50);
    assert.strictEqual(r.discrepancies[0].official, 52);
  });

  ok('a missing scorecard blocks settlement rather than passing it', () => {
    assert.strictEqual(scoring.reconcile({ players: {} }, null).ok, false);
  });

  // ---------------------------------------------------------------------------------------------
  section('parallel-run validation harness (build brief section 9)');

  const pv = require('./lib/cricket/parallel-validation');
  const pvBall = (over, ballNo, opts) => ({ id: over * 10 + ballNo, event_id: `pv_${over}_${ballNo}`, fixture_key: 'pv_match', payload: ball(over, ballNo, opts) });

  ok('a match that matches its official scorecard exactly reports ok, with the computed score attached', () => {
    const events = [pvBall(0, 1, { runs: 4 }), pvBall(0, 2, { runs: 1 })];
    const report = pv.validateSnapshot(events, { players: { bat1: { runs: 5, balls_faced: 2 } } }, { fixtureKey: 'pv_match' });
    assert.strictEqual(report.reconciliation.ok, true);
    assert.strictEqual(report.computed_score.runs, 5);
    assert.strictEqual(report.event_count, 2);
  });

  ok('a real discrepancy is reported with the exact computed vs official figures, not just pass/fail', () => {
    const events = [pvBall(0, 1, { runs: 4 })];
    const report = pv.validateSnapshot(events, { players: { bat1: { runs: 6 } } }, { fixtureKey: 'pv_match' });
    assert.strictEqual(report.reconciliation.ok, false);
    assert.deepStrictEqual(report.reconciliation.discrepancies[0], { player_key: 'bat1', field: 'runs', computed: 4, official: 6 });
  });

  ok('no official scorecard yet is reported as exactly that, not a false pass', () => {
    const report = pv.validateSnapshot([pvBall(0, 1, { runs: 1 })], null, { fixtureKey: 'pv_match' });
    assert.strictEqual(report.reconciliation.ok, false);
    assert.strictEqual(report.reconciliation.reason, 'no_official_scorecard');
  });

  await okAsync('an unknown fixture key is reported cleanly, not thrown', async () => {
    install();
    const result = await pv.validateFixture('no_such_fixture_at_all');
    assert.strictEqual(result.ok, false);
    assert.strictEqual(result.reason, 'no_fixture');
  });

  // ---------------------------------------------------------------------------------------------
  section('credit algorithm');

  const W = configStore.DEFAULT_CREDITS;

  ok('recency weighting favours the most recent match', () => {
    const rising = credits.recencyWeightedMean([90, 10, 10]);   // 90 most recent
    const falling = credits.recencyWeightedMean([10, 10, 90]);  // 90 oldest
    assert.ok(rising > falling, 'form must be weighted toward what happened lately');
  });

  ok('an unseen player cold-starts at the default price', () => {
    const r = credits.computeCredits({ recentPoints: [], tournamentAverage: null }, W);
    assert.strictEqual(r.basis, 'cold_start');
    assert.strictEqual(r.credits, W.default_credits);
  });

  ok('a null tournament average does not drag the price down', () => {
    const withNull = credits.computeCredits({ recentPoints: [60, 60, 60], tournamentAverage: null }, W);
    const blended = credits.computeCredits({ recentPoints: [60, 60, 60], tournamentAverage: 60 }, W);
    assert.strictEqual(withNull.basis, 'recent_only');
    assert.strictEqual(withNull.credits, blended.credits, 'an absent average must not act like a zero');
  });

  ok('a star player is priced at or near the ceiling', () => {
    const r = credits.computeCredits({ recentPoints: [120, 110, 130], tournamentAverage: 115 }, W);
    assert.strictEqual(r.credits, W.max_credits);
  });

  ok('a poor run is priced at or near the floor', () => {
    const r = credits.computeCredits({ recentPoints: [2, 0, 1], tournamentAverage: 1 }, W);
    assert.strictEqual(r.credits, W.min_credits);
  });

  ok('prices are always a multiple of the rounding step', () => {
    for (const pts of [[10], [33], [47], [61], [88], [99]]) {
      const r = credits.computeCredits({ recentPoints: pts, tournamentAverage: null }, W);
      assert.strictEqual(Math.round((r.credits / W.round_to) * 100) / 100 % 1, 0, `${r.credits} is not on the grid`);
    }
  });

  ok('a price is never outside the configured band', () => {
    for (const pts of [[0], [500], [-20], [1000]]) {
      const r = credits.computeCredits({ recentPoints: pts, tournamentAverage: null }, W);
      assert.ok(r.credits >= W.min_credits && r.credits <= W.max_credits, `${r.credits} escaped the clamp`);
    }
  });

  ok('ordinary match-winning form does NOT clamp to the ceiling', () => {
    // The bug this guards against: with the divisor set too low, every form score above ~27 pinned
    // to max_credits. A real T20 innings is worth 40-80 points, so essentially every established
    // player would have cost the same, the 100-credit budget would constrain nothing, and every
    // team would look identical. The whole band has to be reachable.
    for (const form of [40, 60, 80]) {
      const r = credits.computeCredits({ recentPoints: [form], tournamentAverage: form }, W);
      assert.ok(
        r.credits > W.min_credits && r.credits < W.max_credits,
        `form ${form} priced at ${r.credits}, which is pinned to an edge of the band`
      );
    }
  });

  ok('the credit band is genuinely spread, not bunched at one end', () => {
    const prices = [0, 20, 40, 60, 80, 100].map(
      form => credits.computeCredits({ recentPoints: [form], tournamentAverage: form }, W).credits
    );
    assert.ok(new Set(prices).size >= 5, `only ${new Set(prices).size} distinct prices across the form range: ${prices}`);
  });

  ok('better form is never priced lower than worse form', () => {
    let previous = 0;
    for (const avg of [0, 20, 40, 60, 80, 100]) {
      const r = credits.computeCredits({ recentPoints: [avg], tournamentAverage: avg }, W);
      assert.ok(r.credits >= previous, 'pricing must be monotonic in form');
      previous = r.credits;
    }
  });

  // ---------------------------------------------------------------------------------------------
  section('config store: everything tunable, nothing signed off yet');

  await okAsync('defaults are returned when nothing is stored', async () => {
    install();
    const rules = await configStore.scoringFor('T20');
    assert.strictEqual(rules.wicket, 25);
  });

  await okAsync('every section reports as awaiting sign-off out of the box', async () => {
    install();
    configStore.clearCache();
    const pending = await configStore.needsSignoff();
    assert.deepStrictEqual(pending.sort(), ['boundary', 'contest', 'credits', 'house', 'scoring']);
  });

  await okAsync('a saved edit is merged over the defaults, not replacing them', async () => {
    install();
    configStore.clearCache();
    await configStore.set(configStore.KEYS.scoring, { T20: { wicket: 30 } }, { updatedBy: 'admin' });
    configStore.clearCache();
    const rules = await configStore.scoringFor('T20');
    assert.strictEqual(rules.wicket, 30, 'the edit applied');
    assert.strictEqual(rules.catch, 8, 'every other value survived');
  });

  await okAsync('saving a section marks it signed off', async () => {
    install();
    configStore.clearCache();
    await configStore.set(configStore.KEYS.scoring, { T20: { wicket: 30 } }, { updatedBy: 'admin' });
    configStore.clearCache();
    assert.ok(!(await configStore.needsSignoff()).includes('scoring'));
  });

  await okAsync('an unknown format falls back to T20 rather than scoring nothing', async () => {
    install();
    configStore.clearCache();
    const rules = await configStore.scoringFor('THE_HUNDRED');
    assert.strictEqual(rules.wicket, 25);
  });

  // ---------------------------------------------------------------------------------------------
  section('contests: prize table validation');

  // A prize table that does not sum to the pool is a table settlement cannot pay from. Every one of
  // these is rejected on SAVE rather than discovered when the money is being handed out.
  const goodBreakup = [
    { from: 1, to: 1, pct: 40 },
    { from: 2, to: 2, pct: 25 },
    { from: 3, to: 5, pct: 10 },
    { from: 6, to: 10, pct: 1 }
  ]; // 40 + 25 + 30 + 5 = 100

  ok('a contiguous table summing to 100 is accepted', () => {
    const v = contests.validatePrizeBreakup(goodBreakup);
    assert.strictEqual(v.ok, true, v.error);
    assert.strictEqual(round(v.total_pct), 100);
  });

  ok('a gap in the rank ranges is rejected', () => {
    const v = contests.validatePrizeBreakup([
      { from: 1, to: 1, pct: 50 },
      { from: 3, to: 3, pct: 50 }
    ]);
    assert.strictEqual(v.ok, false);
    assert.ok(/contiguous/i.test(v.error), v.error);
  });

  ok('overlapping rank ranges are rejected', () => {
    const v = contests.validatePrizeBreakup([
      { from: 1, to: 3, pct: 20 },
      { from: 2, to: 4, pct: 10 }
    ]);
    assert.strictEqual(v.ok, false);
  });

  ok('a table not starting at rank 1 is rejected', () => {
    const v = contests.validatePrizeBreakup([{ from: 2, to: 3, pct: 50 }]);
    assert.strictEqual(v.ok, false);
    assert.ok(/rank 1/i.test(v.error), v.error);
  });

  ok('a table summing to less than 100 is rejected', () => {
    const v = contests.validatePrizeBreakup([{ from: 1, to: 1, pct: 90 }]);
    assert.strictEqual(v.ok, false);
    assert.ok(/100/.test(v.error), v.error);
  });

  ok('a table summing to more than 100 is rejected', () => {
    const v = contests.validatePrizeBreakup([{ from: 1, to: 2, pct: 60 }]);
    assert.strictEqual(v.ok, false);
  });

  ok('a band pct is per RANK, not per band', () => {
    // 1 x 40 + 1 x 25 + 3 x 10 + 5 x 1. Read as per-band this totals 76 and would be rejected.
    assert.strictEqual(contests.validatePrizeBreakup(goodBreakup).ok, true);
  });

  ok('a zero or negative pct is rejected', () => {
    assert.strictEqual(contests.validatePrizeBreakup([{ from: 1, to: 1, pct: 0 }]).ok, false);
    assert.strictEqual(contests.validatePrizeBreakup([{ from: 1, to: 1, pct: -5 }]).ok, false);
  });

  ok('an empty table is rejected', () => {
    assert.strictEqual(contests.validatePrizeBreakup([]).ok, false);
    assert.strictEqual(contests.validatePrizeBreakup(null).ok, false);
  });

  // ---------------------------------------------------------------------------------------------
  section('contests: pool arithmetic');

  ok('gross, rake and prize pool follow the configured rake', () => {
    const p = contests.computePool(50, 10, 15);
    assert.strictEqual(p.gross_pool, 500);
    assert.strictEqual(p.rake, 75);
    assert.strictEqual(p.prize_pool, 425);
  });

  ok('a zero rake pays the whole pool out', () => {
    assert.strictEqual(contests.computePool(100, 4, 0).prize_pool, 400);
  });

  ok('the pool is exact to the paisa, never a float artefact', () => {
    const p = contests.computePool(9.99, 3, 12.5);
    assert.strictEqual(p.gross_pool, 29.97);
    assert.strictEqual(round(p.rake + p.prize_pool), p.gross_pool);
  });

  // ---------------------------------------------------------------------------------------------
  section('contests: lineup validation');

  // Two 11-man squads. Roles repeat in a fixed pattern so a legal XI can be assembled by hand
  // below, and everything is priced at 9 credits so the budget maths is obvious.
  const squad = [];
  const squadCredits = {};
  const roleOrder = ['WK', 'BAT', 'BAT', 'BAT', 'BAT', 'AR', 'AR', 'BOWL', 'BOWL', 'BOWL', 'BOWL'];
  for (let i = 0; i < 22; i += 1) {
    const key = `p${i}`;
    squad.push({
      player_key: key,
      role: roleOrder[i % roleOrder.length],
      team_key: i < 11 ? 'TA' : 'TB',
      name: `Player ${i}`
    });
    squadCredits[key] = 9;
  }

  const contestRules = {
    squad_size: 11,
    credit_budget: 100,
    max_per_real_team: 7,
    role_limits: {
      WK: { min: 1, max: 4 },
      BAT: { min: 3, max: 6 },
      AR: { min: 1, max: 4 },
      BOWL: { min: 3, max: 6 }
    }
  };

  // 1 WK, 4 BAT, 2 AR, 4 BOWL = 11, with 7 from TA and 4 from TB, at 9 credits each = 99.
  const validXi = ['p0', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p18', 'p19', 'p20', 'p21'];
  const validLineup = { players: validXi, captain: 'p1', vice_captain: 'p2' };

  const checkLineup = (lineup, over = {}) =>
    contests.validateLineup(lineup, {
      squad,
      credits: squadCredits,
      rules: { ...contestRules, ...over },
      defaultCredits: 8
    });

  ok('a legal XI is accepted', () => {
    const v = checkLineup(validLineup);
    assert.strictEqual(v.ok, true, v.error);
    assert.strictEqual(v.credits_used, 99);
  });

  ok('an XI of the wrong size is rejected', () => {
    assert.strictEqual(checkLineup({ ...validLineup, players: validXi.slice(0, 10) }).ok, false);
  });

  ok('the same player picked twice is rejected', () => {
    const dupe = [...validXi.slice(0, 10), validXi[0]];
    const v = checkLineup({ ...validLineup, players: dupe });
    assert.strictEqual(v.ok, false);
    assert.ok(/twice/i.test(v.error), v.error);
  });

  ok('a player outside the match squad is rejected', () => {
    const v = checkLineup({ ...validLineup, players: [...validXi.slice(0, 10), 'ghost'] });
    assert.strictEqual(v.ok, false);
    assert.ok(/squad/i.test(v.error), v.error);
  });

  ok('a captain outside the XI is rejected', () => {
    const v = checkLineup({ ...validLineup, captain: 'p15' });
    assert.strictEqual(v.ok, false);
    assert.ok(/captain/i.test(v.error), v.error);
  });

  ok('the same player as captain AND vice-captain is rejected', () => {
    const v = checkLineup({ ...validLineup, captain: 'p1', vice_captain: 'p1' });
    assert.strictEqual(v.ok, false);
    assert.ok(/different/i.test(v.error), v.error);
  });

  ok('too few of a role is rejected', () => {
    // Swap the only wicketkeeper out for another outfielder.
    const noWk = ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p18', 'p19', 'p20', 'p21', 'p12'];
    const v = checkLineup({ players: noWk, captain: 'p1', vice_captain: 'p2' });
    assert.strictEqual(v.ok, false);
    assert.ok(/WK/.test(v.error), v.error);
  });

  ok('too many from one real team is rejected', () => {
    // 8 from team A.
    const stacked = ['p0', 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p19', 'p20', 'p21'];
    const v = checkLineup({ players: stacked, captain: 'p1', vice_captain: 'p2' });
    assert.strictEqual(v.ok, false);
    assert.ok(/one team/i.test(v.error), v.error);
  });

  ok('an over-budget XI is rejected', () => {
    const v = checkLineup(validLineup, { credit_budget: 90 });
    assert.strictEqual(v.ok, false);
    assert.ok(/budget/i.test(v.error), v.error);
  });

  ok('an unpriced player costs the default, never nothing', () => {
    const partial = { ...squadCredits };
    delete partial.p0;
    const v = contests.validateLineup(validLineup, {
      squad, credits: partial, rules: contestRules, defaultCredits: 8
    });
    assert.strictEqual(v.ok, true, v.error);
    assert.strictEqual(v.credits_used, 98, 'the missing price must cost 8, not 0');
  });

  // ---------------------------------------------------------------------------------------------
  section('contests: auto-substitution for a player excluded from the confirmed XI');

  // A squad with real bench depth (unlike the 22-man fixture above, which is exactly two XIs with
  // no reserves) so a same-team, same-role replacement actually exists to pick from.
  const subSquad = [
    { player_key: 'wk1', role: 'WK', team_key: 'TA' },
    { player_key: 'bat_out', role: 'BAT', team_key: 'TA' }, // picked, then dropped from the XI
    { player_key: 'bat_out2', role: 'BAT', team_key: 'TA' }, // a second player dropped, same match
    { player_key: 'bat_used', role: 'BAT', team_key: 'TA' }, // already on the entry - not a candidate
    { player_key: 'bat_bench_low', role: 'BAT', team_key: 'TA' }, // valid candidate, cheaper
    { player_key: 'bat_bench_high', role: 'BAT', team_key: 'TA' }, // valid candidate, priciest that fits
    { player_key: 'bat_bench_pricey', role: 'BAT', team_key: 'TA' }, // valid candidate, too pricey for budget
    { player_key: 'ar1', role: 'AR', team_key: 'TA' },
    { player_key: 'bowl1', role: 'BOWL', team_key: 'TA' },
    { player_key: 'bowl_out', role: 'BOWL', team_key: 'TB' } // dropped, no BOWL/TB replacement exists
  ];
  const subCredits = {
    wk1: 10, bat_out: 9, bat_out2: 7, bat_used: 8,
    bat_bench_low: 6, bat_bench_high: 9, bat_bench_pricey: 50,
    ar1: 9, bowl1: 9, bowl_out: 9
  };
  const everyoneConfirmed = subSquad.map(p => p.player_key);
  const subOf = (entry, confirmedXi, rules = { credit_budget: 100 }) =>
    contests.substituteExcludedPlayers(entry, { squad: subSquad, credits: subCredits, rules, confirmedXi });

  ok('nobody excluded from the XI leaves the entry untouched', () => {
    const entry = { players: ['wk1', 'bat_out', 'ar1'], captain: 'bat_out', vice_captain: 'ar1' };
    const r = subOf(entry, everyoneConfirmed);
    assert.strictEqual(r.changed, false);
  });

  // A budget of 30 is used through most of these so bat_bench_high (9) fits but bat_bench_pricey
  // (50) does not - keeping the "highest credit AMONG VALID candidates" claim distinct from just
  // "highest credit, full stop".
  const modestBudget = { credit_budget: 30 };

  ok('the excluded player is swapped for the highest-credit same-team, same-role replacement', () => {
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bat_out');
    const entry = { players: ['wk1', 'bat_out', 'ar1'], captain: 'ar1', vice_captain: 'ar1' };
    const r = subOf(entry, confirmedXi, modestBudget);
    assert.strictEqual(r.ok, true, r.error);
    assert.ok(r.players.includes('bat_bench_high'), 'should have picked the priciest valid bench player');
    assert.ok(!r.players.includes('bat_out'));
    assert.strictEqual(r.players.length, entry.players.length);
  });

  ok('an already-used player is never picked as someone else\'s replacement', () => {
    // bat_used is BAT/TA and in the confirmed XI, but it is already on this entry.
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bat_out');
    const entry = { players: ['wk1', 'bat_out', 'bat_used'], captain: 'wk1', vice_captain: 'bat_used' };
    const r = subOf(entry, confirmedXi, modestBudget);
    assert.strictEqual(r.ok, true, r.error);
    assert.strictEqual(r.players.filter(p => p === 'bat_used').length, 1, 'bat_used must not be duplicated');
    assert.ok(r.players.includes('bat_bench_high'));
  });

  ok('captaincy transfers to the substitute when the captain is the one excluded', () => {
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bat_out');
    const entry = { players: ['wk1', 'bat_out', 'ar1'], captain: 'bat_out', vice_captain: 'ar1' };
    const r = subOf(entry, confirmedXi, modestBudget);
    assert.strictEqual(r.ok, true, r.error);
    assert.strictEqual(r.captain, 'bat_bench_high');
    assert.strictEqual(r.vice_captain, 'ar1', 'the untouched vice-captain must not move');
  });

  ok('vice-captaincy transfers the same way when the VC is the one excluded', () => {
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bat_out');
    const entry = { players: ['wk1', 'bat_out', 'ar1'], captain: 'ar1', vice_captain: 'bat_out' };
    const r = subOf(entry, confirmedXi, modestBudget);
    assert.strictEqual(r.ok, true, r.error);
    assert.strictEqual(r.vice_captain, 'bat_bench_high');
    assert.strictEqual(r.captain, 'ar1');
  });

  ok('a tight budget picks the highest-credit replacement that still fits, not the global highest', () => {
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bat_out');
    const entry = { players: ['bat_out', 'ar1'], captain: 'bat_out', vice_captain: 'ar1' };
    // credits_used starts at 9 + 9 = 18; removing bat_out frees room for exactly 6 (budget 15).
    const r = subOf(entry, confirmedXi, { credit_budget: 15 });
    assert.strictEqual(r.ok, true, r.error);
    assert.ok(r.players.includes('bat_bench_low'), 'bat_bench_high (9) and bat_bench_pricey (50) both overshoot the 6-credit room left');
    assert.strictEqual(r.credits_used, 15);
  });

  ok('two excluded players on the same entry are each substituted, without reusing a pick', () => {
    // bat_used takes the entry's other BAT/TA seat so it can't also be drawn as a replacement,
    // leaving exactly bat_bench_high and bat_bench_low to cover the two dropped players.
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bat_out' && k !== 'bat_out2');
    const entry = {
      players: ['wk1', 'bat_used', 'bat_out', 'bat_out2'], captain: 'wk1', vice_captain: 'bat_out'
    };
    const r = subOf(entry, confirmedXi, { credit_budget: 40 });
    assert.strictEqual(r.ok, true, r.error);
    assert.ok(r.players.includes('bat_bench_high') && r.players.includes('bat_bench_low'));
    assert.strictEqual(new Set(r.players).size, r.players.length, 'no player picked twice');
    assert.strictEqual(r.vice_captain, 'bat_bench_high', 'captaincy follows whichever seat bat_out lands in');
  });

  ok('no legal same-team, same-role replacement leaves the entry unchanged, reported as such', () => {
    // bowl_out is the only BOWL/TB player in the squad - there is nothing to swap it for.
    const confirmedXi = everyoneConfirmed.filter(k => k !== 'bowl_out');
    const entry = { players: ['wk1', 'bowl_out'], captain: 'wk1', vice_captain: 'bowl_out' };
    const r = subOf(entry, confirmedXi);
    assert.strictEqual(r.ok, false);
    assert.strictEqual(r.changed, false);
    assert.ok(/replacement/i.test(r.error), r.error);
  });

  ok('a picked player who is not even in this match\'s squad is reported, not silently dropped', () => {
    const entry = { players: ['wk1', 'ghost'], captain: 'wk1', vice_captain: 'ghost' };
    const r = subOf(entry, everyoneConfirmed);
    assert.strictEqual(r.ok, false);
    assert.strictEqual(r.changed, false);
    assert.ok(/squad/i.test(r.error), r.error);
  });

  // ---------------------------------------------------------------------------------------------
  section('contests: ranking and tie-breaks');

  const at = n => new Date(2026, 0, 1, 0, 0, n).toISOString();

  ok('entries rank by points, highest first', () => {
    const r = contests.rankEntries([
      { id: 'a', points: 10, created_at: at(1) },
      { id: 'b', points: 30, created_at: at(2) },
      { id: 'c', points: 20, created_at: at(3) }
    ]);
    assert.deepStrictEqual(r.map(e => e.id), ['b', 'c', 'a']);
    assert.deepStrictEqual(r.map(e => e.rank), [1, 2, 3]);
  });

  ok('equal points SHARE a rank rather than being split by submission time', () => {
    const r = contests.rankEntries([
      { id: 'a', points: 50, created_at: at(2) },
      { id: 'b', points: 50, created_at: at(1) },
      { id: 'c', points: 10, created_at: at(3) }
    ]);
    assert.deepStrictEqual(r.map(e => e.rank), [1, 1, 3], 'competition ranking: 1, 1, then 3');
    assert.strictEqual(r[0].id, 'b', 'the earlier submission is listed first within the tie');
  });

  ok('a rank is never skipped when there is no tie', () => {
    const r = contests.rankEntries([
      { id: 'a', points: 3, created_at: at(1) },
      { id: 'b', points: 2, created_at: at(2) }
    ]);
    assert.deepStrictEqual(r.map(e => e.rank), [1, 2]);
  });

  ok('an empty contest ranks to an empty list, not a crash', () => {
    assert.deepStrictEqual(contests.rankEntries([]), []);
  });

  // ---------------------------------------------------------------------------------------------
  section('contests: prize allocation');

  // The invariant everything else in this section serves: what goes out equals what the pool holds.
  const totalPaid = rows => round(rows.reduce((sum, r) => sum + r.prize, 0));

  const entriesOn = points => points.map((p, i) => ({
    id: `e${i}`, username: `u${i}`, points: p, created_at: at(i)
  }));

  const descending = [100, 90, 80, 70, 60, 50, 40, 30, 20, 10];

  ok('the whole pool is paid out and nothing more', () => {
    const rows = contests.allocatePrizes(entriesOn(descending), goodBreakup, 1000);
    assert.strictEqual(totalPaid(rows), 1000);
  });

  ok('each rank is paid its own band percentage', () => {
    const rows = contests.allocatePrizes(entriesOn(descending), goodBreakup, 1000);
    assert.strictEqual(rows[0].prize, 400, 'rank 1 = 40%');
    assert.strictEqual(rows[1].prize, 250, 'rank 2 = 25%');
    assert.strictEqual(rows[2].prize, 100, 'rank 3 is in the 3-5 band at 10% each');
    assert.strictEqual(rows[5].prize, 10, 'rank 6 is in the 6-10 band at 1% each');
  });

  ok('a rank outside the paid places wins nothing', () => {
    const rows = contests.allocatePrizes(entriesOn([...descending, 5]), goodBreakup, 1000);
    assert.strictEqual(rows[10].prize, 0);
    assert.strictEqual(totalPaid(rows), 1000);
  });

  ok('a tie POOLS the slots it occupies and splits them evenly', () => {
    // Two entries tied on top consume ranks 1 and 2: (40% + 25%) of 1000, split two ways.
    const rows = contests.allocatePrizes(entriesOn([100, 100, 80, 70, 60, 50, 40, 30, 20, 10]), goodBreakup, 1000);
    assert.strictEqual(rows[0].rank, 1);
    assert.strictEqual(rows[1].rank, 1);
    assert.strictEqual(rows[0].prize, 325);
    assert.strictEqual(rows[1].prize, 325);
    assert.strictEqual(rows[2].rank, 3, 'nobody is ranked 2nd behind a two-way tie for 1st');
  });

  ok('a tie that does not divide evenly still pays out the pool exactly', () => {
    // Three-way tie for 1st over ranks 1-3: (40 + 25 + 10)% of 100 = 75, which thirds as 24.99...
    const rows = contests.allocatePrizes(entriesOn([100, 100, 100, 70, 60, 50, 40, 30, 20, 10]), goodBreakup, 100);
    assert.strictEqual(totalPaid(rows), 100, 'the remainder must not be lost or invented');
  });

  ok('the undividable remainder goes to the earliest submission in the tie', () => {
    const rows = contests.allocatePrizes(entriesOn([100, 100, 100, 70, 60, 50, 40, 30, 20, 10]), goodBreakup, 100);
    const tied = rows.filter(r => r.rank === 1);
    assert.strictEqual(tied.length, 3);
    assert.ok(tied[0].prize >= tied[1].prize, 'the first-listed tied entry takes the remainder');
    assert.strictEqual(tied[1].prize, tied[2].prize, 'the rest of the tie is paid equally');
  });

  ok('a split prize is never rounded UP past the pool', () => {
    const rows = contests.allocatePrizes(entriesOn([100, 100, 100, 70, 60, 50, 40, 30, 20, 10]), goodBreakup, 100);
    assert.ok(totalPaid(rows) <= 100);
  });

  ok('an everyone-tied contest splits the entire pool evenly', () => {
    const rows = contests.allocatePrizes(entriesOn([5, 5, 5, 5, 5, 5, 5, 5, 5, 5]), goodBreakup, 1000);
    assert.strictEqual(totalPaid(rows), 1000);
    assert.strictEqual(rows[1].prize, rows[9].prize, 'no entry is favoured when all are level');
  });

  ok('a zero pool pays nobody, rather than dividing by zero', () => {
    const rows = contests.allocatePrizes(entriesOn([100, 90, 80]), goodBreakup, 0);
    assert.strictEqual(totalPaid(rows), 0);
  });

  ok('a contest smaller than the prize table pays only the ranks that exist', () => {
    const rows = contests.allocatePrizes(entriesOn([100, 90]), goodBreakup, 1000);
    assert.strictEqual(rows.length, 2);
    assert.strictEqual(totalPaid(rows), 650, 'only the 40% and 25% slots were actually occupied');
  });

  ok('a house entry is ranked and paid like any other entrant', () => {
    const rows = contests.allocatePrizes([
      { id: 'h', username: 'house', points: 120, created_at: at(1), is_house: true },
      { id: 'a', username: 'u1', points: 100, created_at: at(2) }
    ], goodBreakup, 1000);
    assert.strictEqual(rows[0].id, 'h');
    assert.strictEqual(rows[0].prize, 400, 'the house pays its own fee in, so it takes its winnings out');
  });

  // ---------------------------------------------------------------------------------------------
  section('house entry: guardrails');

  const houseCfg = { ...configStore.DEFAULT_HOUSE };
  const paidContest = (over = {}) => ({
    id: 'c1', fixture_key: MATCH, format: 'small', entry_fee: 50,
    max_entrants: 100, house_decided: false, ...over
  });

  ok('an ordinary paid contest with a full field is eligible', () => {
    assert.strictEqual(houseEntry.eligibility(paidContest(), 20, houseCfg).eligible, true);
  });

  ok('Head to Head is NEVER entered', () => {
    // Two entrants means the real player faces the house directly and loses nearly every time.
    const v = houseEntry.eligibility(paidContest({ format: 'h2h' }), 20, houseCfg);
    assert.strictEqual(v.eligible, false);
    assert.ok(/excluded_format/.test(v.reason), v.reason);
  });

  ok('Practice is NEVER entered', () => {
    assert.strictEqual(houseEntry.eligibility(paidContest({ format: 'practice' }), 20, houseCfg).eligible, false);
  });

  ok('Private is NEVER entered', () => {
    assert.strictEqual(houseEntry.eligibility(paidContest({ format: 'private' }), 20, houseCfg).eligible, false);
  });

  ok('a free contest is never entered - there is nothing to take', () => {
    const v = houseEntry.eligibility(paidContest({ entry_fee: 0 }), 20, houseCfg);
    assert.strictEqual(v.eligible, false);
    assert.ok(/no_money/.test(v.reason), v.reason);
  });

  ok('a field too small to hide an extra entrant is skipped', () => {
    const v = houseEntry.eligibility(paidContest(), 3, houseCfg);
    assert.strictEqual(v.eligible, false);
    assert.ok(/field_too_small/.test(v.reason), v.reason);
  });

  ok('a contest already decided is never decided twice', () => {
    const v = houseEntry.eligibility(paidContest({ house_decided: true }), 20, houseCfg);
    assert.strictEqual(v.eligible, false);
    assert.strictEqual(v.reason, 'already_decided');
  });

  ok('a full contest is skipped rather than displacing a real player', () => {
    const v = houseEntry.eligibility(paidContest({ max_entrants: 20 }), 20, houseCfg);
    assert.strictEqual(v.eligible, false);
    assert.strictEqual(v.reason, 'contest_full');
  });

  // ---------------------------------------------------------------------------------------------
  section('house entry: the climb');

  const rankAt = (p, n, cfg = houseCfg, seedKey = 'contest-x') =>
    houseEntry.targetRankAt(p, n, cfg, { seedKey });

  ok('it enters in the lower part of the field', () => {
    const rank = rankAt(0, 100);
    assert.ok(rank >= 55 && rank <= 90, `entered at ${rank} of 100`);
  });

  ok('it finishes near the top', () => {
    const rank = rankAt(1, 100);
    assert.ok(rank >= 2 && rank <= 10, `finished at ${rank} of 100`);
  });

  ok('the climb is MONOTONIC - it never slides backwards', () => {
    let last = Infinity;
    for (let i = 0; i <= 100; i += 1) {
      const rank = rankAt(i / 100, 100);
      assert.ok(rank <= last, `rank went backwards at progress ${i / 100}: ${last} -> ${rank}`);
      last = rank;
    }
  });

  ok('it never finishes first when never_rank_first is set', () => {
    for (let i = 0; i <= 100; i += 1) {
      assert.ok(rankAt(i / 100, 100) >= 2, `reached rank 1 at progress ${i / 100}`);
    }
  });

  ok('turning never_rank_first off does allow rank 1', () => {
    const cfg = { ...houseCfg, never_rank_first: false, finish_percentile_band: [0.5, 0.5] };
    assert.strictEqual(rankAt(1, 100, cfg), 1);
  });

  ok('the trajectory is stable: the same contest always climbs the same way', () => {
    const a = Array.from({ length: 20 }, (_, i) => rankAt(i / 19, 50, houseCfg, 'contest-a'));
    const b = Array.from({ length: 20 }, (_, i) => rankAt(i / 19, 50, houseCfg, 'contest-a'));
    assert.deepStrictEqual(a, b);
  });

  ok('different contests do not climb in lockstep', () => {
    const a = Array.from({ length: 20 }, (_, i) => rankAt(i / 19, 200, houseCfg, 'contest-a'));
    const b = Array.from({ length: 20 }, (_, i) => rankAt(i / 19, 200, houseCfg, 'contest-b'));
    assert.notDeepStrictEqual(a, b);
  });

  ok('the climb has plateaus rather than being a straight line', () => {
    const ranks = Array.from({ length: 40 }, (_, i) => rankAt(i / 39, 200));
    let flat = 0;
    for (let i = 1; i < ranks.length; i += 1) if (ranks[i] === ranks[i - 1]) flat += 1;
    assert.ok(flat > 5, `only ${flat} flat steps - the climb reads as a straight line`);
  });

  ok('progress is measured in balls bowled, not wall-clock time', () => {
    const state = { innings: [{ legal_balls: 120, super_over: false }], match_ended: false };
    assert.strictEqual(houseEntry.matchProgress(state, { format: 'T20' }), 0.5);
  });

  ok('a super over does not advance progress past the end', () => {
    const state = {
      innings: [
        { legal_balls: 120, super_over: false },
        { legal_balls: 120, super_over: false },
        { legal_balls: 6, super_over: true }
      ],
      match_ended: false
    };
    assert.strictEqual(houseEntry.matchProgress(state, { format: 'T20' }), 1);
  });

  ok('a finished match is fully progressed whatever the ball count says', () => {
    assert.strictEqual(houseEntry.matchProgress({ innings: [], match_ended: true }), 1);
  });

  // ---------------------------------------------------------------------------------------------
  section('house entry: target points for a rank');

  ok('the target sits between the entry to beat and the one to stay behind', () => {
    const target = houseEntry.targetPointsForRank([100, 80, 60, 40], 3);
    assert.ok(target > 60 && target < 80, `got ${target}`);
  });

  ok('a rank below the whole field targets less than the last entry', () => {
    const target = houseEntry.targetPointsForRank([100, 80], 5);
    assert.ok(target < 80, `got ${target}`);
  });

  ok('an empty field targets zero rather than NaN', () => {
    assert.strictEqual(houseEntry.targetPointsForRank([], 1), 0);
  });

  ok('the field is sorted defensively, not assumed sorted', () => {
    const a = houseEntry.targetPointsForRank([40, 100, 60, 80], 3);
    const b = houseEntry.targetPointsForRank([100, 80, 60, 40], 3);
    assert.strictEqual(a, b);
  });

  // ---------------------------------------------------------------------------------------------
  section('house entry: the lineup back-solver');

  // A pool with a real spread of scores, prices, roles and teams.
  const housePool = [];
  for (let i = 0; i < 22; i += 1) {
    housePool.push({
      player_key: `h${i}`,
      role: roleOrder[i % roleOrder.length],
      team_key: i < 11 ? 'TA' : 'TB',
      credits: 7 + ((i * 3) % 9) * 0.5,
      points: (i * 7) % 71
    });
  }

  const houseRules = { ...contestRules, captain_multiplier: 2, vice_captain_multiplier: 1.5 };
  const houseSquad = housePool.map(p => ({
    player_key: p.player_key, role: p.role, team_key: p.team_key, name: p.player_key
  }));
  const housePrices = Object.fromEntries(housePool.map(p => [p.player_key, p.credits]));

  const solveAt = (target, extra = {}) => houseEntry.solveLineup({
    pool: housePool, rules: houseRules, target, seed: 12345, attempts: 240, ...extra
  });

  ok('the solver produces a lineup at all', () => {
    assert.ok(solveAt(120), 'no lineup could be built from a 22-man pool');
  });

  ok('EVERY solved lineup is legal under the same validator a user faces', () => {
    // The whole design rests on this. A house team that could not have been picked by a real user
    // is the tell that undoes it, so the solver's output goes through the user validator verbatim.
    for (const target of [0, 40, 90, 150, 220, 400]) {
      const solved = solveAt(target);
      assert.ok(solved, `no lineup at target ${target}`);
      const v = contests.validateLineup(
        { players: solved.players, captain: solved.captain, vice_captain: solved.vice_captain },
        { squad: houseSquad, credits: housePrices, rules: houseRules, defaultCredits: 8 }
      );
      assert.strictEqual(v.ok, true, `target ${target}: ${v.error}`);
    }
  });

  // The reachable range for this pool: the weakest legal XI scores a little under 200, the
  // strongest a little over 600. Targets outside that are clamped to the nearest achievable team,
  // which is the honest behaviour — so the assertions below stay inside it.
  ok('the solved total actually lands near the target', () => {
    for (const target of [250, 350, 450]) {
      const solved = solveAt(target);
      assert.ok(Math.abs(solved.total - target) < 40, `target ${target}, got ${solved.total}`);
    }
  });

  ok('a higher target produces a higher-scoring team', () => {
    assert.ok(solveAt(450).total > solveAt(250).total,
      `450 -> ${solveAt(450).total}, 250 -> ${solveAt(250).total}`);
  });

  ok('the solver can aim LOW, not just high', () => {
    // The trajectory starts in the lower half of the field. A solver that could only build good
    // teams would enter near the top with nowhere left to climb.
    const low = solveAt(0);
    const high = solveAt(9999);
    assert.ok(low.total < high.total * 0.55, `low ${low.total} vs high ${high.total}`);
  });

  ok('the solver is deterministic - the same inputs give the same team', () => {
    const a = solveAt(140);
    const b = solveAt(140);
    assert.deepStrictEqual(a.players, b.players);
    assert.strictEqual(a.captain, b.captain);
    assert.strictEqual(a.vice_captain, b.vice_captain);
  });

  ok('the captain and vice-captain are different players inside the XI', () => {
    const solved = solveAt(150);
    assert.notStrictEqual(solved.captain, solved.vice_captain);
    assert.ok(solved.players.includes(solved.captain));
    assert.ok(solved.players.includes(solved.vice_captain));
  });

  ok('the floor is respected - the climb never goes backwards', () => {
    const solved = solveAt(50, { floor: 180 });
    assert.ok(solved.total >= 180 || solved.below_floor === false,
      `floor 180 violated with ${solved.total}`);
  });

  ok('the ceiling is respected - the house does not overtake the leader', () => {
    const solved = solveAt(600, { ceiling: 300 });
    assert.ok(solved.total <= 300.01, `ceiling 300 exceeded with ${solved.total}`);
    assert.strictEqual(solved.above_ceiling, false);
  });

  ok('an unreachable ceiling is flagged, not silently ignored', () => {
    // Nothing this pool can field scores under 50. The caller has to be able to tell, because
    // syncFixture holds the climb rather than publishing a lineup that breaks never_rank_first.
    const solved = solveAt(40, { ceiling: 50 });
    assert.strictEqual(solved.above_ceiling, true);
  });

  ok('when a bound cannot be met the least-violating team wins, not the nearest to target', () => {
    const solved = solveAt(9999, { ceiling: 50 });
    const unbounded = solveAt(9999);
    assert.ok(solved.total < unbounded.total,
      `violating ${solved.total} should be nearer the ceiling than ${unbounded.total}`);
  });

  ok('a pool too small for an XI yields nothing rather than an illegal team', () => {
    assert.strictEqual(houseEntry.solveLineup({
      pool: housePool.slice(0, 6), rules: houseRules, target: 100
    }), null);
  });

  ok('the credit budget is never exceeded', () => {
    for (const target of [0, 100, 300]) {
      assert.ok(solveAt(target).credits_used <= houseRules.credit_budget + 0.001);
    }
  });

  ok('points come from real figures, never from an invented number', () => {
    // The solved total must be reproducible by summing the pool's own points with the multipliers.
    const solved = solveAt(160);
    const byKey = Object.fromEntries(housePool.map(p => [p.player_key, p.points]));
    const recomputed = solved.players.reduce((sum, k) => {
      const mult = k === solved.captain ? 2 : k === solved.vice_captain ? 1.5 : 1;
      return sum + byKey[k] * mult;
    }, 0);
    assert.strictEqual(round(recomputed), solved.total);
  });

  // ---------------------------------------------------------------------------------------------
  section('Boundary Baazi: outcome classification');

  const outcomeOf = over => boundary.classifyOutcome(normalize.normalizeEvent(ball(1, 1, over)).detail);

  ok('a dot ball is a dot', () => {
    assert.strictEqual(outcomeOf({ runs: 0 }), 'dot');
  });

  ok('a four off the bat is a four', () => {
    assert.strictEqual(outcomeOf({ runs: 4 }), 'four');
  });

  ok('a six off the bat is a six', () => {
    assert.strictEqual(outcomeOf({ runs: 6 }), 'six');
  });

  ok('one, two and three all settle as runs', () => {
    [1, 2, 3].forEach(r => assert.strictEqual(outcomeOf({ runs: r }), 'runs', `${r} runs`));
  });

  ok('a wicket is a wicket', () => {
    assert.strictEqual(outcomeOf({ runs: 0, wicket: true, wicket_type: 'bowled' }), 'wicket');
  });

  ok('retired hurt is NOT a wicket - the batter is not out', () => {
    const o = outcomeOf({ runs: 0, wicket: true, wicket_type: 'retired hurt' });
    assert.notStrictEqual(o, 'wicket');
    assert.strictEqual(o, 'dot');
  });

  ok('a wide is an extra', () => {
    assert.strictEqual(outcomeOf({ extra_type: 'wide', extra_runs: 1 }), 'extra');
  });

  ok('a no-ball is an extra', () => {
    assert.strictEqual(outcomeOf({ extra_type: 'noball', extra_runs: 1 }), 'extra');
  });

  ok('a no-ball hit for six settles as an extra, per the documented precedence', () => {
    assert.strictEqual(outcomeOf({ runs: 6, extra_type: 'noball', extra_runs: 1 }), 'extra');
  });

  ok('a wicket off a no-ball is still a wicket - wicket outranks extra', () => {
    assert.strictEqual(
      outcomeOf({ extra_type: 'noball', extra_runs: 1, wicket: true, wicket_type: 'run out' }),
      'wicket');
  });

  ok('four leg-byes are NOT a four - they are not the batter\'s runs', () => {
    assert.strictEqual(outcomeOf({ extra_type: 'legbye', extra_runs: 4 }), 'runs');
  });

  ok('a bye is not a dot: something came of the ball', () => {
    assert.strictEqual(outcomeOf({ extra_type: 'bye', extra_runs: 1 }), 'runs');
  });

  ok('classification is EXHAUSTIVE - every delivery lands on exactly one option', () => {
    // Nothing may fall through to null: an unsettleable ball is money stuck in a market forever.
    const keys = configStore.DEFAULT_BOUNDARY.options.map(o => o.key);
    const cases = [];
    for (const runs of [0, 1, 2, 3, 4, 5, 6]) {
      for (const extra of [null, 'wide', 'noball', 'bye', 'legbye']) {
        for (const wicket of [false, true]) {
          cases.push({ runs, extra_type: extra, extra_runs: extra ? 1 : 0, wicket,
            wicket_type: wicket ? 'bowled' : undefined });
        }
      }
    }
    for (const c of cases) {
      const o = outcomeOf(c);
      assert.ok(keys.includes(o), `unclassifiable delivery ${JSON.stringify(c)} -> ${o}`);
    }
  });

  ok('an unreadable delivery classifies to nothing rather than guessing', () => {
    assert.strictEqual(boundary.classifyOutcome(null), null);
  });

  // ---------------------------------------------------------------------------------------------
  section('Boundary Baazi: parimutuel payouts');

  const bet = (id, user, option, stake, at = 0) => ({
    id, username: user, option_key: option, stake, created_at: new Date(2026, 0, 1, 0, 0, at).toISOString()
  });

  const paidTotal = r => round(r.payouts.reduce((sum, b) => sum + b.payout, 0));

  ok('winners split the post-rake pool in proportion to stake', () => {
    const r = boundary.allocatePayouts(
      [bet('a', 'u1', 'four', 100), bet('b', 'u2', 'four', 300), bet('c', 'u3', 'dot', 600)],
      'four', 10
    );
    assert.strictEqual(r.pool, 1000);
    assert.strictEqual(r.rake, 100);
    assert.strictEqual(r.distributable, 900);
    // 100:300 of 900 -> 225 and 675.
    const byId = Object.fromEntries(r.payouts.map(p => [p.id, p.payout]));
    assert.strictEqual(byId.a, 225);
    assert.strictEqual(byId.b, 675);
    assert.strictEqual(byId.c, 0);
  });

  ok('the whole distributable pool is paid out and never a paisa more', () => {
    const r = boundary.allocatePayouts(
      [bet('a', 'u1', 'six', 33), bet('b', 'u2', 'six', 33), bet('c', 'u3', 'six', 34)],
      'six', 10
    );
    assert.strictEqual(paidTotal(r), r.distributable);
  });

  ok('an indivisible split gives the remainder to the largest stake', () => {
    const r = boundary.allocatePayouts(
      [bet('small', 'u1', 'dot', 1), bet('big', 'u2', 'dot', 2)],
      'dot', 0
    );
    assert.strictEqual(paidTotal(r), 3);
    const byId = Object.fromEntries(r.payouts.map(p => [p.id, p.payout]));
    assert.ok(byId.big >= byId.small * 2, 'the larger stake takes the odd paisa');
  });

  ok('nobody backing the winner means nobody is paid, and it is REPORTED', () => {
    const r = boundary.allocatePayouts(
      [bet('a', 'u1', 'dot', 100), bet('b', 'u2', 'four', 100)],
      'wicket', 10
    );
    assert.strictEqual(paidTotal(r), 0);
    assert.strictEqual(r.house_keeps, 180, 'the undistributed pool must be visible, not absorbed silently');
  });

  ok('everybody backing the winner gets their stake back less the rake', () => {
    const r = boundary.allocatePayouts([bet('a', 'u1', 'dot', 100), bet('b', 'u2', 'dot', 100)], 'dot', 10);
    const byId = Object.fromEntries(r.payouts.map(p => [p.id, p.payout]));
    assert.strictEqual(byId.a, 90);
    assert.strictEqual(byId.b, 90);
  });

  ok('a lone winner takes the whole distributable pool', () => {
    const r = boundary.allocatePayouts(
      [bet('a', 'u1', 'six', 50), bet('b', 'u2', 'dot', 450)],
      'six', 20
    );
    assert.strictEqual(r.payouts.find(p => p.id === 'a').payout, 400);
  });

  ok('an empty market pays nothing rather than dividing by zero', () => {
    const r = boundary.allocatePayouts([], 'dot', 10);
    assert.strictEqual(r.pool, 0);
    assert.strictEqual(paidTotal(r), 0);
  });

  ok('a zero rake pays the entire pool to the winners', () => {
    const r = boundary.allocatePayouts([bet('a', 'u1', 'dot', 100), bet('b', 'u2', 'four', 100)], 'dot', 0);
    assert.strictEqual(paidTotal(r), 200);
  });

  ok('losing bets are marked lost, not merely unpaid', () => {
    const r = boundary.allocatePayouts([bet('a', 'u1', 'dot', 100), bet('b', 'u2', 'four', 100)], 'dot', 10);
    assert.strictEqual(r.payouts.find(p => p.id === 'b').won, false);
    assert.strictEqual(r.payouts.find(p => p.id === 'a').won, true);
  });

  ok('the house never pays out more than came in, across many random markets', () => {
    // The single invariant that matters for a pool game: money out <= money in, always.
    let seed = 7;
    const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
    const keys = configStore.DEFAULT_BOUNDARY.options.map(o => o.key);
    for (let trial = 0; trial < 200; trial += 1) {
      const bets = [];
      const n = 1 + Math.floor(rnd() * 12);
      for (let i = 0; i < n; i += 1) {
        bets.push(bet(`b${i}`, `u${i}`, keys[Math.floor(rnd() * keys.length)],
          Math.round(rnd() * 5000) / 100 + 1, i));
      }
      const outcome = keys[Math.floor(rnd() * keys.length)];
      const rake = Math.floor(rnd() * 25);
      const r = boundary.allocatePayouts(bets, outcome, rake);
      assert.ok(paidTotal(r) <= r.pool + 0.001,
        `trial ${trial}: paid ${paidTotal(r)} out of a pool of ${r.pool}`);
      assert.ok(paidTotal(r) <= r.distributable + 0.001,
        `trial ${trial}: paid ${paidTotal(r)} of a distributable ${r.distributable}`);
    }
  });

  // ---------------------------------------------------------------------------------------------
  console.log('\n=================================================');
  console.log(`  ${passed} passed, ${failed} failed`);
  console.log('=================================================\n');

  fanout.shutdown();
  health.reset();
  process.exit(failed === 0 ? 0 : 1);
}

run().catch(e => {
  console.error('\nsuite crashed:', e);
  process.exit(1);
});
