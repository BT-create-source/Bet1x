/**
 * bet1x house-edge / rigging suite.
 *
 *   npm run test:rig     (from the repo root)
 *   node backend/test_rigging.js
 *
 * test_backend.js asks "is this safe" and test_e2e.js asks "does this work". This one asks a
 * question neither of those can: **does the configured percentage mean what it says?**
 *
 * When an operator sets a game's bot takeover to 50%, that is a claim about proportion — half the
 * live games belong to the house. Nothing previously verified that claim, so the only evidence
 * available was impression, and impressions are exactly how this engine has gone wrong before: a
 * configured 50% was once reported in the field as "8 of 10 games". Two separate causes were found
 * and fixed (a plain sequential counter that produced long unbroken streaks, then two independent
 * percentage mechanisms stacking on top of each other), and neither would have shipped if a suite
 * like this had existed.
 *
 * So every assertion here is about proportion and scoping rather than any single round's outcome:
 *  - each game rigs the configured share, exactly, over a full cycle
 *  - the unit that share is measured in is the right one for that game (tables for Teen Patti,
 *    per-room cycles for Colour Prediction, rounds for Aviator, players for Mines)
 *  - no room, table or player is starved or favoured relative to the others
 *  - turning the bot off actually stops everything
 *
 * Unlike the other two suites this one needs no HTTP server and no database: it drives the engine
 * functions directly through the internals the server exports, so it runs in about a second.
 */

process.env.NODE_ENV = process.env.NODE_ENV || 'development';
process.env.APP_SECRET = process.env.APP_SECRET || 'rigging-suite-secret-value-at-least-32-chars';
process.env.ADMIN_USERNAME = process.env.ADMIN_USERNAME || 'admin';
process.env.ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'test-admin-password';
delete process.env.ADMIN_PASSWORD_HASH;

const app = require('./server.js');
const H = app._houseEdgeInternals;

let pass = 0;
const failures = [];

function check(name, condition, detail) {
  if (condition) {
    pass++;
    console.log(`  ok   ${name}`);
  } else {
    failures.push(name);
    console.log(`  FAIL ${name}${detail !== undefined ? '  -> ' + JSON.stringify(detail).slice(0, 300) : ''}`);
  }
}

function section(title) {
  console.log(`\n--- ${title} ---`);
}

const TP_ROOMS = ['room_101', 'room_102', 'room_103', 'room_104', 'room_105', 'room_106'];
const COLOR_ROOMS = ['sapre', 'becone', 'emred', 'vip'];

function setBot(game, enabled, pct) {
  H.botTakeoverState[game] = { enabled, profit_pct: pct };
}

function clearLiveInstances(game) {
  Object.keys(H.LIVE_INSTANCES[game] || {}).forEach(k => delete H.LIVE_INSTANCES[game][k]);
}

function clearLiveUsers(game) {
  Object.keys(H.LIVE_USERS[game] || {}).forEach(k => delete H.LIVE_USERS[game][k]);
}

function main() {
  // ---------------------------------------------------------------------------------------------
  section('the rig cycle is exact for every percentage');

  // Not just the round tens: an operator can type any integer, and a formula that only happens to be
  // exact at multiples of ten would drift silently at 37%.
  [1, 7, 13, 25, 33, 50, 66, 80, 91, 99, 100].forEach(pct => {
    setBot('aviator', true, pct);
    delete H.botRigBags.aviator;
    let rigged = 0;
    for (let i = 0; i < 100; i++) if (H.shouldBotRigThisRound('aviator').shouldRig) rigged++;
    check(`${pct}% rigs exactly ${pct} of 100 rounds`, rigged === pct, { pct, rigged });
  });

  section('the rig cycle does not clump inside a window');

  // Exactness over 100 rounds is not enough on its own: a player watching ten rounds sees a window,
  // not a cycle, and "8 of 10 at 50%" is what a clumped-but-technically-exact cycle looks like.
  //
  // The guarantee has to be stated carefully. The engine splits its 100 slots into ten aligned
  // buckets of ten and fills each with exactly its share, so ALIGNED windows are exact by
  // construction. A *sliding* window straddling two buckets is not, and cannot be: if one bucket
  // ends with five rigged slots and the next begins with five, a ten-round window across that seam
  // legitimately contains ten. So asserting a hard maximum over thousands of sliding windows fails
  // by design, given enough samples. What actually matters — and what the reported bug was about —
  // is how OFTEN a player sees an extreme window, so that is what is measured here, the same way
  // test_backend.js measures it.
  setBot('aviator', true, 50);
  let alignedExact = true;
  let extreme8 = 0, windows = 0;
  for (let cycle = 0; cycle < 200; cycle++) {
    delete H.botRigBags.aviator;
    const seq = [];
    for (let i = 0; i < 100; i++) seq.push(H.shouldBotRigThisRound('aviator').shouldRig);
    for (let b = 0; b < 10; b++) {
      if (seq.slice(b * 10, b * 10 + 10).filter(Boolean).length !== 5) alignedExact = false;
    }
    for (let i = 0; i + 10 <= seq.length; i++) {
      if (seq.slice(i, i + 10).filter(Boolean).length >= 8) extreme8++;
      windows++;
    }
  }
  check('every aligned 10-round bucket is exactly 5 of 10 at 50%', alignedExact);
  // A naive independent coin flip sits near 5.5% and the old fully-shuffled bag measured ~4.6%.
  const extremeRate = extreme8 / windows;
  check('a player rarely sees an 8-of-10 window at 50%', extremeRate < 0.02,
    { rate: (extremeRate * 100).toFixed(2) + '%', windows });

  // ---------------------------------------------------------------------------------------------
  section('Teen Patti: every table keeps its own exact cycle');

  // An earlier design selected a live subset of TABLES and rigged whatever hands those tables dealt.
  // It held "half the live tables are the house's" at any instant, but the selection was sticky, so
  // the first table to go live kept the slot for ever and the share of HANDS rigged tracked table
  // activity rather than the configured figure — measured at 11% of hands for a configured 50%, with
  // one table taking the entire share. A per-table ledger satisfies both readings at once.
  [30, 50, 80, 100].forEach(pct => {
    setBot('teenpatti', true, pct);
    TP_ROOMS.forEach(r => { delete H.botRigBags[`teenpatti:${r}`]; });
    TP_ROOMS.forEach(room => {
      let rigged = 0;
      for (let i = 0; i < 100; i++) {
        if (H.shouldBotRigThisRound('teenpatti', `teenpatti:${room}`).shouldRig) rigged++;
      }
      check(`${room} @ ${pct}% rigs exactly ${pct} of its own 100 hands`, rigged === pct,
        { room, pct, rigged });
    });
  });

  section('Teen Patti: a busy table cannot starve a quiet one');

  // The exact failure the per-table ledger exists to prevent: one table dealing far more hands than
  // the others must not consume their share.
  setBot('teenpatti', true, 50);
  TP_ROOMS.forEach(r => { delete H.botRigBags[`teenpatti:${r}`]; });
  let busyRigged = 0;
  for (let i = 0; i < 100; i++) {
    if (H.shouldBotRigThisRound('teenpatti', 'teenpatti:room_101').shouldRig) busyRigged++;
  }
  let quietRigged = 0;
  for (let i = 0; i < 10; i++) {
    if (H.shouldBotRigThisRound('teenpatti', 'teenpatti:room_106').shouldRig) quietRigged++;
  }
  check('the busy table draws its own full cycle', busyRigged === 50, { busyRigged });
  check('the quiet table still gets ~half of its own hands',
    quietRigged >= 4 && quietRigged <= 6, { quietRigged });

  TP_ROOMS.forEach(r => { delete H.botRigBags[`teenpatti:${r}`]; });
  for (let i = 0; i < 23; i++) H.shouldBotRigThisRound('teenpatti', 'teenpatti:room_101');
  check('dealing at one table does not touch another table\'s ledger',
    !H.botRigBags['teenpatti:room_102'], { room_102: H.botRigBags['teenpatti:room_102'] });
  check('the dealing table recorded exactly its own hands',
    H.botRigBags['teenpatti:room_101'].totalDecisions === 23,
    { totalDecisions: H.botRigBags['teenpatti:room_101'].totalDecisions });

  section('Teen Patti: every table gets an equal share');

  // With each table on its own ledger, equal activity must produce equal rigging — no table
  // permanently favoured, which is precisely what the sticky selection got wrong.
  setBot('teenpatti', true, 50);
  TP_ROOMS.forEach(r => { delete H.botRigBags[`teenpatti:${r}`]; });
  const perTable = {};
  TP_ROOMS.forEach(room => {
    let n = 0;
    for (let i = 0; i < 100; i++) if (H.shouldBotRigThisRound('teenpatti', `teenpatti:${room}`).shouldRig) n++;
    perTable[room] = n;
  });
  check('all six tables rig the identical share on identical activity',
    Object.values(perTable).every(n => n === 50), perTable);

  section('Teen Patti: the bot switch still stops everything');

  setBot('teenpatti', false, 50);
  const offDecision = H.shouldBotRigThisRound('teenpatti', 'teenpatti:room_101');
  check('bot off -> no hand is rigged', offDecision.shouldRig === false && offDecision.active === false,
    offDecision);
  let anyRigged = false;
  TP_ROOMS.forEach(room => {
    for (let i = 0; i < 30; i++) {
      if (H.shouldBotRigThisRound('teenpatti', `teenpatti:${room}`).shouldRig) anyRigged = true;
    }
  });
  check('bot off -> not one hand at any table is rigged', anyRigged === false);

  section('Teen Patti: live-table counting still reports correctly');

  // Table liveness no longer drives the decision, but the audit reports it, so it must stay honest.
  setBot('teenpatti', true, 50);
  clearLiveInstances('teenpatti');
  check('no tables live when nobody is seated', H.getLiveInstances('teenpatti').length === 0);
  TP_ROOMS.slice(0, 4).forEach(r => H.markInstanceActive('teenpatti', r));
  check('live-table count follows the tables actually in play',
    H.getLiveInstances('teenpatti').length === 4, { live: H.getLiveInstances('teenpatti') });

  // ---------------------------------------------------------------------------------------------
  section('Colour Prediction: every room keeps its own exact cycle');

  [30, 50, 80].forEach(pct => {
    setBot('color_guess', true, pct);
    COLOR_ROOMS.forEach(r => { delete H.botRigBags[`color_guess:${r}`]; });
    COLOR_ROOMS.forEach(room => {
      let rigged = 0;
      for (let i = 0; i < 100; i++) {
        if (H.shouldBotRigThisRound('color_guess', `color_guess:${room}`).shouldRig) rigged++;
      }
      check(`${room} @ ${pct}% rigs exactly ${pct} of its own 100 rounds`, rigged === pct,
        { room, pct, rigged });
    });
  });

  // The rooms settle at 30s / 60s / 180s / 300s. Under a single shared cycle the 30s room consumed
  // most of the rigged slots before the 300s room had settled a handful of rounds, so both rooms
  // reported the configured percentage while neither actually received it.
  setBot('color_guess', true, 50);
  COLOR_ROOMS.forEach(r => { delete H.botRigBags[`color_guess:${r}`]; });
  let fastRigged = 0;
  for (let i = 0; i < 100; i++) {
    if (H.shouldBotRigThisRound('color_guess', 'color_guess:sapre').shouldRig) fastRigged++;
  }
  let slowRigged = 0;
  for (let i = 0; i < 10; i++) {
    if (H.shouldBotRigThisRound('color_guess', 'color_guess:vip').shouldRig) slowRigged++;
  }
  check('the fast room draws its own full cycle', fastRigged === 50, { fastRigged });
  check('the slow room is not starved by it', slowRigged >= 4 && slowRigged <= 6, { slowRigged });

  COLOR_ROOMS.forEach(r => { delete H.botRigBags[`color_guess:${r}`]; });
  for (let i = 0; i < 37; i++) H.shouldBotRigThisRound('color_guess', 'color_guess:sapre');
  check('drawing in one room does not touch another room\'s ledger',
    !H.botRigBags['color_guess:becone'], { becone: H.botRigBags['color_guess:becone'] });
  check('the drawing room recorded exactly its own draws',
    H.botRigBags['color_guess:sapre'].totalDecisions === 37,
    { totalDecisions: H.botRigBags['color_guess:sapre'].totalDecisions });

  section('Colour Prediction: the rigged number is the most profitable one');

  // Bets that make exactly one number clearly best for the house, so the "optimal" claim is checkable
  // rather than merely plausible: heavy money on Green (1,3,7,9) and on Big (5-9).
  const bets = [
    { username: 'p1', category: 'color', value: 'Green', amount: 1000 },
    { username: 'p2', category: 'size', value: 'Big', amount: 1000 },
    { username: 'p3', category: 'number', value: '7', amount: 500 }
  ];
  const optimal = H.calculateColorOptimalOutcome(bets, '2024010112001');
  const chosen = optimal.outcomes[optimal.best_number];
  const bestPossible = Math.max(...optimal.outcomes.map(o => o.adminProfit));
  check('the chosen number is genuinely the maximum-profit one',
    chosen.adminProfit === bestPossible, { best_number: optimal.best_number, profit: chosen.adminProfit });
  check('the chosen number is not one the players backed',
    chosen.color !== 'Green' && chosen.size !== 'Big' && optimal.best_number !== 7,
    { number: optimal.best_number, color: chosen.color, size: chosen.size });
  check('house profit equals total volume when no bet wins',
    chosen.adminProfit === optimal.total_volume, { profit: chosen.adminProfit, volume: optimal.total_volume });

  // Scoping to the targeted subset must change the answer, or targeting means nothing.
  const scoped = H.calculateColorOptimalOutcome(bets, '2024010112001', ['p3']);
  check('scoping to a targeted subset narrows the volume considered',
    scoped.scoped_volume === 500 && scoped.total_volume === 2500,
    { scoped: scoped.scoped_volume, total: scoped.total_volume });

  // ---------------------------------------------------------------------------------------------
  section('Aviator: the crash point is driven by the live book');

  const avBet = (username, amount, status = 'pending', mult) =>
    ({ username, amount, status, cashed_multiplier: mult });

  const heavy = H.pickAviatorCrashPoint([avBet('alice', 5000)], ['alice']);
  const light = H.pickAviatorCrashPoint([avBet('alice', 5)], ['alice']);
  check('heavy exposure crashes near the aggressive end',
    heavy <= H.AVIATOR_CRASH_AGGRESSIVE + 0.06, { heavy });
  check('light exposure is allowed a natural-looking multiplier',
    light >= H.AVIATOR_CRASH_RELAXED - 0.06, { light });
  check('more money on the table means an earlier crash', heavy < light, { heavy, light });

  let outOfBand = null;
  for (let i = 0; i < 500; i++) {
    const c = H.pickAviatorCrashPoint([avBet('alice', Math.random() * 4000)], ['alice']);
    if (c < H.AVIATOR_CRASH_FLOOR || c > H.AVIATOR_CRASH_RELAXED + 0.05) { outOfBand = c; break; }
  }
  // The computed point must stay inside the band the original random draw already used, so smart
  // selection can never produce a crash the old code could not have produced anyway.
  check('500 computed crash points all stay inside the original band', outOfBand === null, { outOfBand });

  const distinct = new Set();
  for (let i = 0; i < 50; i++) distinct.add(H.pickAviatorCrashPoint([avBet('alice', 1000)], ['alice']));
  check('identical rounds do not produce one constant multiplier', distinct.size > 5,
    { distinct: distinct.size });

  check('untargeted stake alone does not trigger a rig',
    H.pickAviatorCrashPoint([avBet('bob', 5000)], ['alice']) === null);
  check('no pending stake -> caller keeps its own behaviour',
    H.pickAviatorCrashPoint([], ['alice']) === null);
  check('settled bets are not counted as live exposure',
    H.pickAviatorCrashPoint([avBet('alice', 900, 'lost')], ['alice']) === null);

  section('Aviator: erosion detection');

  check('profit steady -> keep flying', H.aviatorShouldCrashNow(1.30, 500, 500) === false);
  check('profit dropped -> crash now', H.aviatorShouldCrashNow(1.30, 500, 420) === true);
  check('erosion below the floor multiplier is ignored',
    H.aviatorShouldCrashNow(1.05, 500, 100) === false);
  check('floating-point noise does not trigger a crash',
    H.aviatorShouldCrashNow(1.30, 500, 499.995) === false);
  check('non-finite input is safe', H.aviatorShouldCrashNow(1.30, NaN, 100) === false);

  // The premise the whole erosion design rests on: profit can only fall during a flight.
  const beforeCashout = H.calculateAviatorLiveProfit([avBet('alice', 100)], ['alice']).profit_if_crash_now;
  const afterCashout = H.calculateAviatorLiveProfit([avBet('alice', 100, 'won', 2.0)], ['alice']).profit_if_crash_now;
  check('a cash-out strictly reduces house profit', afterCashout < beforeCashout,
    { beforeCashout, afterCashout });
  check('the drop equals stake x (1 + multiplier)',
    Math.abs((beforeCashout - afterCashout) - 300) < 0.01, { beforeCashout, afterCashout });

  // ---------------------------------------------------------------------------------------------
  section('Mines: the share is measured in live PLAYERS');

  // One board per player, so a live player is a live game and the two denominators coincide.
  [[100, 10], [80, 8], [50, 5], [30, 3], [10, 1]].forEach(([pct, expected]) => {
    setBot('mines', true, pct);
    clearLiveUsers('mines');
    for (let i = 0; i < 10; i++) H.markUserActive('mines', `player${i}`);
    H.refreshBotTargeting('mines');
    const got = H.botTargetedUsers.mines.length;
    check(`${pct}% of 10 live players targets ${expected}`, got === expected, { pct, got, expected });
  });

  setBot('mines', true, 50);
  clearLiveUsers('mines');
  for (let i = 0; i < 10; i++) H.markUserActive('mines', `player${i}`);
  H.refreshBotTargeting('mines');
  const targetedMines = H.botTargetedUsers.mines;
  check('every targeted player is a live player',
    targetedMines.every(u => /^player\d$/.test(u)), targetedMines);
  check('no player is targeted twice', new Set(targetedMines).size === targetedMines.length);
  check('targeted players report as targeted',
    targetedMines.every(u => H.isUserTargeted('mines', u)));
  check('untargeted players do not',
    ['player0','player1','player2','player3','player4','player5','player6','player7','player8','player9']
      .filter(u => !targetedMines.includes(u))
      .every(u => H.isUserTargeted('mines', u) === false));

  setBot('mines', false, 50);
  H.refreshBotTargeting('mines');
  check('bot off -> no player targeted', H.botTargetedUsers.mines.length === 0);

  // ---------------------------------------------------------------------------------------------
  section('the master switch reaches every game');

  ['color_guess', 'aviator', 'teenpatti', 'mines'].forEach(g => setBot(g, false, 60));
  H.botTakeoverState.global = { enabled: false, profit_pct: 60 };
  ['color_guess', 'aviator', 'teenpatti', 'mines'].forEach(g => {
    check(`${g} reports inactive when everything is off`, H.isBotTakeoverActive(g).active === false);
  });

  // A per-game "off" is an explicit operator decision and must survive the global switch.
  H.botTakeoverState.global = { enabled: true, profit_pct: 60 };
  check('an explicitly disabled game stays off under the global switch',
    H.isBotTakeoverActive('mines').active === false);

  // ---------------------------------------------------------------------------------------------
  section('Your 11: the share is measured in CONTESTS, per match');

  const Y11_MATCHES = ['match_aaa', 'match_bbb', 'match_ccc'];
  const y11Key = m => `youreleven:${m}`;
  const clearY11 = () => Y11_MATCHES.forEach(m => { delete H.botRigBags[y11Key(m)]; });

  // The concurrent unit for Your 11 is the contest — one match carries many at once — so the
  // percentage has to mean "this proportion of contests", drawn from each match's own cycle.
  [25, 50, 75].forEach(pct => {
    setBot('youreleven', true, pct);
    clearY11();
    Y11_MATCHES.forEach(match => {
      let rigged = 0;
      for (let i = 0; i < 100; i++) {
        if (H.shouldBotRigThisRound('youreleven', y11Key(match)).shouldRig) rigged++;
      }
      check(`${match} @ ${pct}% enters exactly ${pct} of its own 100 contests`, rigged === pct,
        { match, pct, rigged });
    });
  });

  // A marquee match with forty contests must not eat the rigged slots belonging to a quiet one.
  setBot('youreleven', true, 50);
  clearY11();
  let y11BusyRigged = 0;
  for (let i = 0; i < 100; i++) {
    if (H.shouldBotRigThisRound('youreleven', y11Key('match_aaa')).shouldRig) y11BusyRigged++;
  }
  let y11QuietRigged = 0;
  for (let i = 0; i < 10; i++) {
    if (H.shouldBotRigThisRound('youreleven', y11Key('match_bbb')).shouldRig) y11QuietRigged++;
  }
  check('a busy match draws its own full cycle', y11BusyRigged === 50, { y11BusyRigged });
  check('a quiet match is not starved by it', y11QuietRigged >= 4 && y11QuietRigged <= 6, { y11QuietRigged });

  clearY11();
  for (let i = 0; i < 23; i++) H.shouldBotRigThisRound('youreleven', y11Key('match_aaa'));
  check('drawing for one match does not touch another match\'s ledger',
    !H.botRigBags[y11Key('match_ccc')], { ccc: H.botRigBags[y11Key('match_ccc')] });
  check('the drawing match recorded exactly its own draws',
    H.botRigBags[y11Key('match_aaa')].totalDecisions === 23,
    { totalDecisions: H.botRigBags[y11Key('match_aaa')].totalDecisions });

  // There is exactly ONE percentage mechanism. Being drawn from the bag IS the decision — no second
  // roll, no separate "arm N contests" pass. Stacking two is what once turned 50% into 8 of 10.
  setBot('youreleven', true, 50);
  clearY11();
  let y11Drawn = 0;
  for (let i = 0; i < 400; i++) {
    if (H.shouldBotRigThisRound('youreleven', y11Key('match_aaa')).shouldRig) y11Drawn++;
  }
  check('over four full cycles the share is exactly the configured one', y11Drawn === 200, { y11Drawn });

  setBot('youreleven', false, 50);
  check('disabling Your 11 stops every house entry',
    H.shouldBotRigThisRound('youreleven', y11Key('match_aaa')).shouldRig === false);

  // ---------------------------------------------------------------------------------------------
  section('Boundary Baazi: no rig path exists, and must not be added');

  // Asserted positively so it cannot be reintroduced by accident. Ball-by-ball outcomes come from
  // the permanent event log and nothing else.
  check('there is no `boundary` key in the takeover state',
    H.botTakeoverState.boundary === undefined,
    { keys: Object.keys(H.botTakeoverState) });

  H.botTakeoverState.global = { enabled: true, profit_pct: 90 };
  check('boundary is not activated even by the global master switch',
    H.isBotTakeoverActive('boundary').active === false);
  H.botTakeoverState.global = { enabled: false, profit_pct: 90 };

  check('an unregistered game key is inactive even under the global switch',
    H.isBotTakeoverActive('not_a_real_game').active === false);

  // Structural, not textual: scan every cricket module for calls into the rig engine and assert the
  // only game key any of them passes is Your 11's. This is what would actually catch a Boundary
  // Baazi rig path being wired in later, which a comment or a naming convention would not.
  const fs = require('fs');
  const path = require('path');
  const cricketDir = path.join(__dirname, 'lib/cricket');
  const rigCalls = [];
  for (const file of fs.readdirSync(cricketDir).filter(f => f.endsWith('.js'))) {
    const src = fs.readFileSync(path.join(cricketDir, file), 'utf8');
    for (const m of src.matchAll(/shouldRig\s*\(\s*([^,)]+)/g)) {
      rigCalls.push({ file, arg: m[1].trim() });
    }
  }
  check('the cricket pipeline does consult the rig engine somewhere', rigCalls.length > 0,
    { note: 'a vacuous pass here would hide the assertion below' });
  check('every rig call from the cricket pipeline is for youreleven and nothing else',
    rigCalls.every(c => c.arg === 'GAME_KEY' || /^['"]youreleven['"]$/.test(c.arg)),
    { rigCalls });

  console.log(`\n${pass} passed, ${failures.length} failed`);
  if (failures.length) {
    console.log('\nFailures:');
    failures.forEach(f => console.log(`  - ${f}`));
    process.exit(1);
  }
  process.exit(0);
}

main();
