/**
 * Cricket end-to-end product suite — Your 11 and Boundary Baazi driven exactly the way production
 * will: real HTTP against the real Express app, a real wallet, a real Postgres database, and a
 * simulated Roanuz push feed hitting the actual webhook route (unsigned, since dev mode with no
 * configured secret skips verification — the same bypass config.js documents).
 *
 *   npm run test:cricket:e2e     (from the repo root)
 *   node backend/test_cricket_e2e.js
 *
 * What this cannot prove: the exact field names Roanuz's live payloads use (normalize.js's
 * FIELD_MAP is written from documentation, never checked against a real delivery — see the
 * UNVERIFIED note at the top of that file) and anything that needs a live match. Everything else —
 * fixture/contest lifecycle, lineup lock and the confirmed-XI auto-substitution, ball-by-ball
 * scoring, settlement and its reconciliation hold, and Boundary Baazi's open/lock/resolve/payout
 * cycle including extras — is real, over real HTTP, with real money moving through the real wallet.
 *
 * Writes to whatever DATABASE_URL points at. Run it against a development database.
 */

process.env.NODE_ENV = process.env.NODE_ENV || 'development';
process.env.APP_SECRET = process.env.APP_SECRET || 'test-suite-secret-value-at-least-32-chars-long';
process.env.ADMIN_USERNAME = process.env.ADMIN_USERNAME || 'admin';
process.env.ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'test-admin-password';
delete process.env.ADMIN_PASSWORD_HASH;

process.env.CRICKET_ENABLED = 'true';
// Kept empty so the fixture-sync timer this boot starts stays fully offline (isConfigured() false,
// so it just logs and returns) rather than making a real network call to an account we know is
// still blocked on Roanuz's side — see the CRICKET-BUILD-BRIEF amendment from today.
process.env.ROANUZ_API_TOKEN = '';
process.env.ROANUZ_PROJECT_KEY = '';
delete process.env.ROANUZ_WEBHOOK_SECRET; // dev + no secret => signature check is skipped, not enforced
delete process.env.CRICKET_HOUSE_ACCOUNT; // house entry off, so every payout here is a real user's

let pass = 0;
const failures = [];
function check(name, condition, detail) {
  if (condition) {
    pass++;
    console.log(`  ok   ${name}`);
  } else {
    failures.push(name);
    console.log(`  FAIL ${name}${detail !== undefined ? '  -> ' + JSON.stringify(detail).slice(0, 400) : ''}`);
  }
}
function section(title) {
  console.log(`\n--- ${title} ---`);
}
const money = (a, b) => Math.abs((Number(a) || 0) - (Number(b) || 0)) < 0.01;
const round2 = n => Math.round((Number(n) || 0) * 100) / 100;

async function main() {
  section('boot');
  const app = require('./server.js');
  const server = await new Promise(resolve => {
    const s = app.listen(0, '127.0.0.1', () => resolve(s));
  });
  const BASE = `http://127.0.0.1:${server.address().port}`;
  check('server accepts connections', !!server.address().port);

  const { PrismaClient } = require('@prisma/client');
  const prisma = new PrismaClient();
  const boundaryPure = require('./lib/cricket/boundary'); // pure allocatePayouts, used as an oracle below

  async function req(method, p, { token, json, query } = {}) {
    const url = new URL(BASE + p);
    if (query) Object.entries(query).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = {};
    let body;
    if (token) headers.Authorization = 'Bearer ' + token;
    if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    const res = await fetch(url, { method, headers, body });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch (e) { data = text; }
    return { status: res.status, data, text };
  }

  let webhookSeq = 0;
  async function webhook(payload) {
    webhookSeq += 1;
    // event_id is globally unique across every fixture (matching the real provider's own event
    // ids), so it must be scoped to this run, not just this call - a bare counter collides with a
    // previous run's rows and every event silently becomes a "duplicate", stalling the whole match.
    const withId = { event_id: payload.event_id || `e2e_${stamp}_${webhookSeq}`, ...payload };
    const res = await fetch(BASE + '/api/cricket/webhook', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(withId)
    });
    const data = await res.json().catch(() => null);
    return { status: res.status, data };
  }

  const balance = async token => (await req('GET', '/api/wallet/balance', { token })).data.balance;

  const stamp = Date.now().toString(36);
  const FIXTURE = 'e2e_match_' + stamp;
  const TA = 'TA_' + stamp, TB = 'TB_' + stamp;

  section('sign up');
  const admin = await req('POST', '/api/admin.php', { json: { action: 'login', username: 'admin', password: process.env.ADMIN_PASSWORD } });
  const adminToken = admin.data && admin.data.token;
  check('operator signs in', admin.status === 200 && !!adminToken, admin.data);

  const playerA = 'e2eA' + stamp, playerB = 'e2eB' + stamp;
  const PASSWORD = 'Str0ngPassw0rd!';
  let tokenA, tokenB;
  {
    const a = await req('POST', '/api/auth/signup', { json: { username: playerA, password: PASSWORD, email: playerA + '@example.com' } });
    tokenA = a.data && a.data.token;
    const b = await req('POST', '/api/auth/signup', { json: { username: playerB, password: PASSWORD, email: playerB + '@example.com' } });
    tokenB = b.data && b.data.token;
    check('both players sign up', !!tokenA && !!tokenB, { a: a.data, b: b.data });

    for (const [name, token] of [[playerA, tokenA], [playerB, tokenB]]) {
      const adj = await req('POST', '/api/wallet/adjust', { token: adminToken, json: { username: name, delta: 5000, reason: 'e2e funding' } });
      check(`operator funds ${name}`, adj.status === 200, adj.data);
    }
  }

  section('fixture, squad and credits (hand-created — the only way to exercise this before Roanuz credentials work)');
  {
    const fx = await req('POST', '/api/cricket/admin/fixtures', {
      token: adminToken,
      json: {
        key: FIXTURE, tournament_key: 'e2e_tour', name: `${TA} vs ${TB}`, format: 'T20',
        team_a_key: TA, team_a_name: TA, team_b_key: TB, team_b_name: TB,
        start_time: new Date().toISOString()
      }
    });
    check('fixture created', fx.status === 200 && fx.data.ok, fx.data);

    // 13 players per side (11 confirmed XI + 2 held back), so there is real bench depth for the
    // confirmed-XI auto-substitution to draw from — a squad that is exactly two XIs (as in the pure
    // unit-test fixture) has none.
    const squad = [
      // team A
      ['TA_wk1', 'WK', TA, 9], ['TA_wk2', 'WK', TA, 7.5],
      ['TA_bat1', 'BAT', TA, 10], ['TA_bat2', 'BAT', TA, 9], ['TA_bat3', 'BAT', TA, 8.5], ['TA_bat4', 'BAT', TA, 8], ['TA_bat5', 'BAT', TA, 7],
      ['TA_ar1', 'AR', TA, 9.5], ['TA_ar2', 'AR', TA, 8], ['TA_ar3', 'AR', TA, 7],
      ['TA_bowl1', 'BOWL', TA, 9], ['TA_bowl2', 'BOWL', TA, 8], ['TA_bowl3', 'BOWL', TA, 7.5],
      // team B
      ['TB_wk1', 'WK', TB, 8], ['TB_wk2', 'WK', TB, 7],
      ['TB_bat1', 'BAT', TB, 9], ['TB_bat2', 'BAT', TB, 8.5], ['TB_bat3', 'BAT', TB, 8], ['TB_bat4', 'BAT', TB, 7.5], ['TB_bat5', 'BAT', TB, 7],
      ['TB_ar1', 'AR', TB, 9], ['TB_ar2', 'AR', TB, 7.5], ['TB_ar3', 'AR', TB, 7],
      ['TB_bowl1', 'BOWL', TB, 11], ['TB_bowl2', 'BOWL', TB, 8], ['TB_bowl3', 'BOWL', TB, 7]
    ];
    for (const [player_key, role, team_key, credits] of squad) {
      await prisma.cricketSquadPlayer.create({ data: { fixture_key: FIXTURE, player_key, name: player_key, role, team_key, in_confirmed_xi: false } });
      await prisma.cricketPlayerCredit.create({ data: { fixture_key: FIXTURE, player_key, credits, source: 'e2e' } });
    }
    const count = await prisma.cricketSquadPlayer.count({ where: { fixture_key: FIXTURE } });
    check('26-player squad (13 per side) seeded with credits', count === 26, count);
  }

  // Excluded from the confirmed XI once the toss lineup event lands: TA_bat5, TA_ar3, TB_wk2, TB_bat5.
  const EXCLUDED = ['TA_bat5', 'TA_ar3', 'TB_wk2', 'TB_bat5'];
  const ALL_SQUAD_KEYS = (await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: FIXTURE } })).map(p => p.player_key);
  const CONFIRMED_XI = ALL_SQUAD_KEYS.filter(k => !EXCLUDED.includes(k));
  check('confirmed XI is 22 (11 a side)', CONFIRMED_XI.length === 22, CONFIRMED_XI.length);

  section('contest creation and joining — BEFORE the toss, from the full squad');
  let contestId;
  {
    const c = await req('POST', '/api/cricket/admin/contests', {
      token: adminToken,
      json: {
        fixture_key: FIXTURE, name: 'E2E Head to Head', format: 'small', entry_fee: 50,
        rake_pct: 15, prize_breakup: [{ from: 1, to: 1, pct: 100 }],
        min_entrants: 2, max_entrants: 2, max_entries_per_user: 1
      }
    });
    check('contest created', c.status === 200 && c.data.ok, c.data);
    contestId = c.data.contest && c.data.contest.id;
  }

  // playerA's team deliberately includes TA_bat5, a player about to be dropped from the confirmed
  // XI, as vice-captain — this is the exact scenario docs/YOUR11-SCOPE.md section 3 decided must
  // auto-substitute, with captaincy carried over to whoever replaces the seat.
  const lineupA = {
    team_name: 'Team Alpha',
    players: ['TA_wk1', 'TA_bat1', 'TA_bat2', 'TA_bat5', 'TA_ar1', 'TA_bowl1', 'TA_bowl2', 'TB_bat1', 'TB_bat2', 'TB_ar1', 'TB_bowl1'],
    captain: 'TA_bowl1', vice_captain: 'TA_bat5'
  };
  // playerB's team includes TB_bat5, a different dropped player, on a different team - proving the
  // substitution isn't a one-shot special case tied to a single entry.
  const lineupB = {
    team_name: 'Team Bravo',
    players: ['TB_wk1', 'TB_bat3', 'TB_bat4', 'TB_bat5', 'TB_ar2', 'TB_bowl2', 'TB_bowl3', 'TA_wk2', 'TA_bat3', 'TA_bat4', 'TA_bowl3'],
    captain: 'TB_bowl2', vice_captain: 'TB_bat5'
  };

  let balA0, balB0;
  {
    balA0 = await balance(tokenA);
    balB0 = await balance(tokenB);
    const jA = await req('POST', `/api/cricket/contests/${contestId}/join`, { token: tokenA, json: lineupA });
    check('player A joins with a legal, pre-toss lineup', jA.status === 200 && jA.data.ok, jA.data);
    check('player A is charged the entry fee', money(await balance(tokenA), balA0 - 50));

    const jB = await req('POST', `/api/cricket/contests/${contestId}/join`, { token: tokenB, json: lineupB });
    check('player B joins with a legal, pre-toss lineup', jB.status === 200 && jB.data.ok, jB.data);
    check('player B is charged the entry fee', money(await balance(tokenB), balB0 - 50));

    const dupe = await req('POST', `/api/cricket/contests/${contestId}/join`, { token: tokenA, json: lineupA });
    check('a third entry is refused - the contest is full at 2', dupe.status !== 200);
  }

  section('toss and lineup lock — the confirmed-XI auto-substitution, over real HTTP');
  {
    const toss = await webhook({ match_key: FIXTURE, event_type: 'toss', winner: TA, decision: 'bat' });
    check('toss event ingested', toss.status === 200 && toss.data.ok, toss.data);

    const lineup = await webhook({ match_key: FIXTURE, event_type: 'lineup', playing_xi: CONFIRMED_XI });
    check('lineup event ingested', lineup.status === 200 && lineup.data.ok, lineup.data);

    const fixtureRow = await prisma.cricketFixture.findUnique({ where: { key: FIXTURE } });
    check('the fixture is marked lineups-confirmed', !!fixtureRow.lineups_confirmed_at);

    const squadRows = await prisma.cricketSquadPlayer.findMany({ where: { fixture_key: FIXTURE } });
    const confirmedNow = squadRows.filter(p => p.in_confirmed_xi).map(p => p.player_key).sort();
    check('in_confirmed_xi is stamped onto exactly the 22 confirmed players', JSON.stringify(confirmedNow) === JSON.stringify([...CONFIRMED_XI].sort()), confirmedNow.length);

    const contestRow = await prisma.cricketContest.findUnique({ where: { id: contestId } });
    check('the contest locked on the lineup event', contestRow.status === 'locked', contestRow.status);

    const entryA = await prisma.cricketEntry.findFirst({ where: { contest_id: contestId, username: playerA } });
    check('player A no longer has the dropped player on the team', !entryA.players.includes('TA_bat5'));
    check('player A gained exactly one replacement, keeping 11 players', entryA.players.length === 11);
    check('the replacement is the highest-credit fit within budget (TA_bat4, not the pricier TA_bat3 that would break it)', entryA.players.includes('TA_bat4') && !entryA.players.includes('TA_bat3'), entryA.players);
    check('captaincy is untouched (captain was never the dropped player)', entryA.captain === 'TA_bowl1');
    check('vice-captaincy TRANSFERRED to the substitute', entryA.vice_captain === 'TA_bat4', entryA.vice_captain);
    check('credits_used lands exactly on the 100 budget after the swap', money(entryA.credits_used, 100), entryA.credits_used);

    const entryB = await prisma.cricketEntry.findFirst({ where: { contest_id: contestId, username: playerB } });
    check('player B no longer has their own dropped player on the team', !entryB.players.includes('TB_bat5'));
    check('player B\'s vice-captaincy also transferred to their substitute', entryB.vice_captain !== 'TB_bat5' && entryB.players.includes(entryB.vice_captain), entryB.vice_captain);
  }

  section('match_start — the very first ball of the match gets a Boundary Baazi market');
  {
    await webhook({ match_key: FIXTURE, event_type: 'match_start', innings: 1 });
    const cur = await req('GET', `/api/cricket/boundary/${FIXTURE}/current`);
    check('a market is open for delivery 1:0:1 before any ball has been bowled', cur.data.ok && cur.data.round && cur.data.round.delivery_key === '1:0:1:1', cur.data);
  }

  // ------------------------------------------------------------------------------------------------
  // Innings 1, over 0. One legal-looking wide is folded in deliberately: it and the legal re-bowl
  // that follows it share the SAME over.ball position, which is exactly what used to make the
  // second market silently unresolved (or resolved on the wrong ball) before today's boundary.js fix.
  // ------------------------------------------------------------------------------------------------
  const bettingLog = []; // { label, bets: [{token, option, stake}], outcome }

  async function playDelivery(label, ballPayload, bets) {
    const cur = await req('GET', `/api/cricket/boundary/${FIXTURE}/current`);
    check(`${label}: a market is open beforehand`, cur.data.ok && !!cur.data.round, cur.data);
    const deliveryKey = cur.data.round && cur.data.round.delivery_key;

    const placed = [];
    for (const b of bets || []) {
      const r = await req('POST', `/api/cricket/boundary/${FIXTURE}/bet`, {
        token: b.token, json: { delivery_key: deliveryKey, option: b.option, stake: b.stake }
      });
      check(`${label}: bet on ${b.option} (${b.stake}) accepted`, r.status === 200 && r.data.ok, r.data);
      placed.push({ ...b });
    }

    await webhook({ match_key: FIXTURE, event_type: 'ball_start' });

    const lateBet = await req('POST', `/api/cricket/boundary/${FIXTURE}/bet`, {
      token: bets && bets[0] ? bets[0].token : tokenA, json: { delivery_key: deliveryKey, option: 'dot', stake: 5 }
    });
    check(`${label}: a bet placed AFTER ball_start is refused - the lock actually closed the market`, lateBet.status !== 200);

    await webhook({ match_key: FIXTURE, event_type: 'ball', innings: 1, ...ballPayload });

    return { deliveryKey, bets: placed };
  }

  section('innings 1, over 0 — runs, a boundary, an extra colliding with its own re-bowl, a wicket');
  {
    const d1 = await playDelivery('D1 (1 run)', { over: 0, ball: 1, batsman: 'TA_wk1', non_striker: 'TA_bat1', bowler: 'TB_bowl1', batsman_run: 1 },
      [{ token: tokenA, option: 'runs', stake: 100 }]);
    bettingLog.push({ ...d1, outcome: 'runs' });

    const d2 = await playDelivery('D2 (four)', { over: 0, ball: 2, batsman: 'TA_wk1', non_striker: 'TA_bat1', bowler: 'TB_bowl1', batsman_run: 4 },
      [{ token: tokenB, option: 'four', stake: 50 }, { token: tokenA, option: 'dot', stake: 30 }]);
    bettingLog.push({ ...d2, outcome: 'four' });

    const d3 = await playDelivery('D3 (wide)', { over: 0, ball: 3, batsman: 'TA_wk1', non_striker: 'TA_bat1', bowler: 'TB_bowl1', extra_type: 'wide', extra_runs: 1, batsman_run: 0 },
      [{ token: tokenA, option: 'extra', stake: 20 }]);
    bettingLog.push({ ...d3, outcome: 'extra' });

    const d4 = await playDelivery('D4 (the legal re-bowl at the SAME over.ball as D3)', { over: 0, ball: 3, batsman: 'TA_wk1', non_striker: 'TA_bat1', bowler: 'TB_bowl1', batsman_run: 0 },
      [{ token: tokenB, option: 'dot', stake: 40 }]);
    bettingLog.push({ ...d4, outcome: 'dot' });
    check('D3 and D4 got DIFFERENT delivery keys despite sharing an over.ball label', d3.deliveryKey !== d4.deliveryKey, { d3: d3.deliveryKey, d4: d4.deliveryKey });

    const d5 = await playDelivery('D5 (six)', { over: 0, ball: 4, batsman: 'TA_wk1', non_striker: 'TA_bat1', bowler: 'TB_bowl1', batsman_run: 6 },
      [{ token: tokenA, option: 'six', stake: 25 }]);
    bettingLog.push({ ...d5, outcome: 'six' });

    const d6 = await playDelivery('D6 (wicket, bowled)', { over: 0, ball: 5, batsman: 'TA_wk1', non_striker: 'TA_bat1', bowler: 'TB_bowl1', is_wicket: true, wicket_type: 'bowled', out_player: 'TA_wk1', batsman_run: 0 },
      [{ token: tokenB, option: 'wicket', stake: 35 }]);
    bettingLog.push({ ...d6, outcome: 'wicket' });

    const d7 = await playDelivery('D7 (1 run, over complete, no bets)', { over: 0, ball: 6, batsman: 'TA_bat2', non_striker: 'TA_bat1', bowler: 'TB_bowl1', batsman_run: 1 }, []);
    bettingLog.push({ ...d7, outcome: 'runs' });
  }

  section('innings break — the SECOND fix: the opening delivery of innings 2 gets its own market');
  {
    await webhook({ match_key: FIXTURE, event_type: 'innings_break' });
    const cur = await req('GET', `/api/cricket/boundary/${FIXTURE}/current`);
    check('a market is open for delivery 2:0:1 immediately after the break, before any innings-2 ball', cur.data.ok && cur.data.round && cur.data.round.delivery_key === '2:0:1:1', cur.data);

    const d8 = await playDelivery('innings-2 D1 (six)', { over: 0, ball: 1, innings: 2, batsman: 'TB_bat1', non_striker: 'TB_bat2', bowler: 'TA_bowl1', batsman_run: 6 },
      [{ token: tokenB, option: 'six', stake: 60 }, { token: tokenA, option: 'wicket', stake: 60 }]);
    bettingLog.push({ ...d8, outcome: 'six' });

    const d9 = await playDelivery('innings-2 D2 (wicket, caught)', { over: 0, ball: 2, innings: 2, batsman: 'TB_bat1', non_striker: 'TB_bat2', bowler: 'TA_bowl1', is_wicket: true, wicket_type: 'caught', out_player: 'TB_bat1', fielders: ['TA_ar1'], batsman_run: 0 }, []);
    bettingLog.push({ ...d9, outcome: 'wicket' });
  }

  section('Boundary Baazi payouts — checked against the same pure allocatePayouts every market settles through');
  {
    const history = await req('GET', `/api/cricket/boundary/${FIXTURE}/history`);
    check('every delivery with a market resolved', history.data.ok && history.data.rounds.length >= bettingLog.length, { got: history.data.rounds && history.data.rounds.length, expected: bettingLog.length });

    let totalStakedA = 0, totalStakedB = 0, totalWonA = 0, totalWonB = 0;
    for (const round of bettingLog) {
      const dbRound = history.data.rounds.find(r => r.delivery_key === round.deliveryKey);
      check(`${round.deliveryKey}: resolved with the expected outcome`, !!dbRound && dbRound.outcome === round.outcome, { dbRound, expected: round.outcome });
      if (!dbRound) continue;

      // allocatePayouts attributes each returned payout back to its bet by `id` - a real bet row
      // always has a unique one, but a synthetic object needs one given explicitly, or every bet in
      // a round (winners and losers alike) collides onto the same key and inherits the winner's share.
      const asBets = round.bets.map((b, i) => ({ id: `${round.deliveryKey}#${i}`, username: b.token === tokenA ? playerA : playerB, option_key: b.option, stake: b.stake }));
      const expected = boundaryPure.allocatePayouts(asBets, round.outcome, dbRound.rake_pct);
      check(`${round.deliveryKey}: pool matches what was actually staked`, money(dbRound.pool, expected.pool), { db: dbRound.pool, expected: expected.pool });
      check(`${round.deliveryKey}: paid matches the pure-function oracle exactly`, money(dbRound.paid, expected.paid), { db: dbRound.paid, expected: expected.paid });

      for (const b of round.bets) {
        totalStakedA += b.token === tokenA ? b.stake : 0;
        totalStakedB += b.token === tokenB ? b.stake : 0;
      }
      for (const p of expected.payouts) {
        if (p.username === playerA) totalWonA += p.payout;
        if (p.username === playerB) totalWonB += p.payout;
      }
    }

    const balAafterBoundary = await balance(tokenA);
    const balBafterBoundary = await balance(tokenB);
    // Your 11 hasn't settled yet at this point, so the only money movement since balA0/balB0 is the
    // 50 entry fee plus every Boundary Baazi stake and payout.
    check('player A wallet reconciles exactly: -entry -stakes +winnings', money(balAafterBoundary, balA0 - 50 - totalStakedA + totalWonA), { balAafterBoundary, balA0, totalStakedA, totalWonA });
    check('player B wallet reconciles exactly: -entry -stakes +winnings', money(balBafterBoundary, balB0 - 50 - totalStakedB + totalWonB), { balBafterBoundary, balB0, totalStakedB, totalWonB });
  }

  section('match end — auto-settlement correctly PAUSES without an official scorecard to reconcile against');
  let balA1, balB1;
  {
    await webhook({ match_key: FIXTURE, event_type: 'match_end' });
    const fixtureRow = await prisma.cricketFixture.findUnique({ where: { key: FIXTURE } });
    check('fixture marked completed', fixtureRow.status === 'completed');

    const contestRow = await prisma.cricketContest.findUnique({ where: { id: contestId } });
    check('the contest was NOT auto-settled - there is no official scorecard to reconcile against', contestRow.status === 'locked' && !contestRow.settled_at, contestRow.status);

    balA1 = await balance(tokenA);
    balB1 = await balance(tokenB);
  }

  section('operator forces settlement past the reconciliation hold — the documented exception path');
  let leaderboardBefore;
  {
    leaderboardBefore = await req('GET', `/api/cricket/contests/${contestId}/leaderboard`);
    check('opponent lineups are hidden before the match ends... but it already has, so this just confirms the endpoint answers', leaderboardBefore.data.ok);

    const settle = await req('POST', `/api/cricket/admin/contests/${contestId}/settle`, { token: adminToken, json: { force: true } });
    check('forced settlement succeeds', settle.status === 200 && settle.data.ok, settle.data);

    const contestRow = await prisma.cricketContest.findUnique({ where: { id: contestId } });
    check('the contest is now settled', contestRow.status === 'settled' && !!contestRow.settled_at);

    const entries = await prisma.cricketEntry.findMany({ where: { contest_id: contestId } });
    const totalPrize = round2(entries.reduce((s, e) => s + (Number(e.prize) || 0), 0));
    const expectedPool = round2(2 * 50 * (1 - 15 / 100));
    check('the whole prize pool was paid out and not a paisa more', money(totalPrize, expectedPool), { totalPrize, expectedPool });
    check('every entry has a rank', entries.every(e => Number.isInteger(e.rank)), entries.map(e => e.rank));

    const winner = entries.find(e => e.prize > 0);
    check('exactly one entry won the winner-takes-all pool', entries.filter(e => e.prize > 0).length === 1, entries.map(e => e.prize));

    const balA2 = await balance(tokenA);
    const balB2 = await balance(tokenB);
    const winnerToken = winner && winner.username === playerA ? tokenA : tokenB;
    const winnerBalBefore = winner && winner.username === playerA ? balA1 : balB1;
    const winnerBalAfter = winner && winner.username === playerA ? balA2 : balB2;
    const loserBalBefore = winner && winner.username === playerA ? balB1 : balA1;
    const loserBalAfter = winner && winner.username === playerA ? balB2 : balA2;
    check('the winner\'s wallet credited exactly the prize amount', money(winnerBalAfter, winnerBalBefore + winner.prize), { winnerBalAfter, winnerBalBefore, prize: winner.prize });
    check('the loser\'s wallet did not move at settlement (they already paid their entry fee)', money(loserBalAfter, loserBalBefore));
  }

  section('settlement is idempotent — running it again pays nothing a second time');
  {
    const balABefore = await balance(tokenA);
    const balBBefore = await balance(tokenB);
    const again = await req('POST', `/api/cricket/admin/contests/${contestId}/settle`, { token: adminToken, json: { force: true } });
    check('re-settling reports already_settled, not a fresh payout', again.status === 200 && again.data.already_settled === true, again.data);
    check('no money moved on the second settlement', money(await balance(tokenA), balABefore) && money(await balance(tokenB), balBBefore));
  }

  section('operator records the official scorecard (Section 12 step 8 — no automatic writer exists yet)');
  {
    const bad = await req('POST', `/api/cricket/admin/fixtures/no_such_fixture_${stamp}/scorecard`, { token: adminToken, json: { players: {} } });
    check('writing a scorecard for an unknown fixture 404s', bad.status === 404, bad.data);

    const malformed = await req('POST', `/api/cricket/admin/fixtures/${FIXTURE}/scorecard`, { token: adminToken, json: { players: 'not-an-object' } });
    check('a malformed body is rejected with 400 rather than silently accepted', malformed.status === 400, malformed.data);

    // TB_bowl1 bowled every ball of the match above, so it certainly has computed bowling figures -
    // deliberately-impossible reference numbers make the mismatch deterministic without needing to
    // predict the engine's exact totals.
    const scorecardPlayers = { TB_bowl1: { runs_conceded: 999, wickets: 99 } };
    const write = await req('POST', `/api/cricket/admin/fixtures/${FIXTURE}/scorecard`, { token: adminToken, json: { players: scorecardPlayers } });
    check('operator records the official scorecard', write.status === 200 && write.data.ok, write.data);

    const stored = await prisma.cricketFixture.findUnique({ where: { key: FIXTURE } });
    // Postgres JSONB reorders object keys on storage, so compare values rather than serialized order.
    const storedBowl1 = stored.official_scorecard && stored.official_scorecard.players && stored.official_scorecard.players.TB_bowl1;
    check('it persisted exactly what was posted', !!storedBowl1 && storedBowl1.runs_conceded === 999 && storedBowl1.wickets === 99, stored.official_scorecard);

    const validate = await req('GET', `/api/cricket/admin/validate/${FIXTURE}`, { token: adminToken });
    check('the validation endpoint reads the recorded scorecard back and reconciles against it', validate.status === 200 && validate.data.ok, validate.data);
    check('deliberately-wrong reference figures are caught as a mismatch, not waved through', validate.data.reconciliation && validate.data.reconciliation.reason === 'mismatch' && validate.data.reconciliation.discrepancies.some(d => d.player_key === 'TB_bowl1'), validate.data.reconciliation);
  }

  section('a gzip-compressed full-match SNAPSHOT delivery — the documented Roanuz shape, over the real webhook route');
  {
    // Separate fixture: this proves the OTHER delivery shape Roanuz's docs describe (a full
    // match-state push, gzip-compressed) works end to end through the real route, independently
    // of everything the discrete-event match above already proved.
    const zlib = require('zlib');
    const SNAP_FIXTURE = 'e2e_snap_' + stamp;

    const fx = await req('POST', '/api/cricket/admin/fixtures', {
      token: adminToken,
      json: {
        key: SNAP_FIXTURE, tournament_key: 'e2e_tour', name: 'Snapshot Test Match', format: 'T20',
        team_a_key: TA, team_a_name: TA, team_b_key: TB, team_b_name: TB, start_time: new Date().toISOString()
      }
    });
    check('a second fixture exists for the snapshot-delivery test', fx.status === 200 && fx.data.ok, fx.data);

    const snapshot = {
      key: SNAP_FIXTURE,
      score: { runs: 5, wickets: 0 },
      toss: { winner: TA, decision: 'bat' },
      players: { confirmed_xi: ['TA_wk1', 'TA_bat1'] },
      related_balls: [
        { innings: 1, over: 0, ball: 1, batsman: { key: 'TA_wk1' }, bowler: { key: 'TB_bowl1' }, score: { batsman_runs: 1 } },
        { innings: 1, over: 0, ball: 2, batsman: { key: 'TA_wk1' }, bowler: { key: 'TB_bowl1' }, score: { batsman_runs: 4 } }
      ]
    };
    const gz = zlib.gzipSync(Buffer.from(JSON.stringify(snapshot)));

    // A Content-Type header has to be present (any type - the route accepts '*/*') for Express's
    // raw-body parser to engage at all; a real provider's POST always carries one too.
    const res = await fetch(BASE + '/api/cricket/webhook', { method: 'POST', headers: { 'Content-Type': 'application/gzip' }, body: gz });
    const body = await res.json().catch(() => null);
    check('the gzip-compressed snapshot is accepted (not rejected as unparseable)', res.status === 200 && body && body.ok, body);
    check('every event in the snapshot was stored (2 balls + toss + lineup)', body && body.stored === 4, body);

    const state = await req('GET', `/api/cricket/fixtures/${SNAP_FIXTURE}/state`);
    check('the derived live state reflects both balls from the snapshot', state.data.ok && state.data.state.score.runs === 5, state.data && state.data.state && state.data.state.score);
    check('the toss embedded in the snapshot was captured as its own event', state.data.state.toss && state.data.state.toss.winner === TA, state.data.state.toss);
    check('the confirmed XI embedded in the snapshot was captured', state.data.state.lineups_confirmed === true, state.data.state.lineups_confirmed);

    // Redelivering the SAME snapshot (Roanuz resends full state on every update) must not double-count.
    const gz2 = zlib.gzipSync(Buffer.from(JSON.stringify(snapshot)));
    const res2 = await fetch(BASE + '/api/cricket/webhook', { method: 'POST', headers: { 'Content-Type': 'application/gzip' }, body: gz2 });
    const body2 = await res2.json().catch(() => null);
    check('redelivering the identical snapshot stores nothing new', res2.status === 200 && body2 && body2.stored === 0 && body2.duplicates > 0, body2);
    const stateAgain = await req('GET', `/api/cricket/fixtures/${SNAP_FIXTURE}/state`);
    check('the score is unchanged after the redelivery', stateAgain.data.state.score.runs === 5, stateAgain.data.state.score);
  }

  section('cleanup');
  await prisma.$disconnect();
  await new Promise(resolve => server.close(resolve));

  console.log(`\n=================================================`);
  console.log(`  ${pass} passed, ${failures.length} failed`);
  console.log(`=================================================`);
  if (failures.length) {
    console.log('\nFailed:');
    for (const f of failures) console.log('  - ' + f);
  }
  process.exit(failures.length ? 1 : 0);
}

main().catch(e => {
  console.error('E2E suite threw:', e);
  process.exit(1);
});
