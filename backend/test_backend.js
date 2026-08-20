/**
 * bet1x backend smoke + security regression suite.
 *
 *   npm test          (from the repo root)
 *   node backend/test_backend.js
 *
 * Boots the Express app in-process on an ephemeral port and exercises it over real HTTP. Alongside
 * the ordinary happy paths it re-checks every hole that was closed during the production hardening
 * pass, so a future refactor that reopens one fails the suite instead of shipping.
 *
 * It writes to whatever DATABASE_URL points at, so run it against a development database.
 */

process.env.NODE_ENV = process.env.NODE_ENV || 'development';
process.env.APP_SECRET = process.env.APP_SECRET || 'test-suite-secret-value-at-least-32-chars-long';
process.env.ADMIN_USERNAME = process.env.ADMIN_USERNAME || 'admin';
process.env.ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'test-admin-password';
delete process.env.ADMIN_PASSWORD_HASH;

const path = require('path');
const fs = require('fs');

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

async function main() {
  section('file layout');
  const dataDir = path.join(__dirname, 'data');
  if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir, { recursive: true });
  check('backend/data exists', fs.existsSync(dataDir));
  for (const f of ['server.js', 'config.js', 'lib/auth.js', 'lib/logger.js', 'prisma/schema.prisma']) {
    check(`backend/${f} present`, fs.existsSync(path.join(__dirname, f)));
  }
  check('backend/.env.example present', fs.existsSync(path.join(__dirname, '.env.example')));
  check('.gitignore present at repo root', fs.existsSync(path.join(__dirname, '..', '.gitignore')));

  section('boot');
  const app = require('./server.js');
  const server = await new Promise(resolve => {
    const s = app.listen(0, '127.0.0.1', () => resolve(s));
  });
  const BASE = `http://127.0.0.1:${server.address().port}`;
  check('server accepts connections', !!server.address().port);

  async function req(method, p, { token, json, form } = {}) {
    const headers = {};
    let body;
    if (token) headers.Authorization = 'Bearer ' + token;
    if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    if (form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
    const res = await fetch(BASE + p, { method, headers, body });
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch (e) { data = text; }
    return { status: res.status, data, text, headers: res.headers };
  }

  const stamp = Date.now().toString(36);
  const alice = 'tsta' + stamp;
  const mallory = 'tstm' + stamp;
  const PASSWORD = 'Str0ngPassw0rd!';

  section('health');
  const health = await req('GET', '/api/health');
  check('GET /api/health', health.status === 200 && health.data.status === 'ok', health.data);
  const ready = await req('GET', '/api/ready');
  check('GET /api/ready reports a store', ready.status === 200 && !!ready.data.store, ready.data);

  section('accounts');
  check('short password rejected',
    (await req('POST', '/api/auth/signup', { json: { username: alice, password: 'abc' } })).status === 400);

  const signup = await req('POST', '/api/auth/signup', { json: { username: alice, password: PASSWORD, email: `${alice}@test.local` } });
  check('signup succeeds', signup.status === 200 && signup.data.success === true, signup.data);
  const aliceToken = signup.data && signup.data.token;
  check('token is signed (version.payload.signature)', typeof aliceToken === 'string' && aliceToken.split('.').length === 3);

  const inflated = await req('POST', '/api/auth/signup', { json: { username: alice + 'z', password: PASSWORD, starting_balance: 999999 } });
  check('client cannot choose its own opening balance',
    inflated.status === 200 && Number(inflated.data.user.wallet_balance) !== 999999, inflated.data);

  check('wrong password rejected',
    (await req('POST', '/api/auth/login', { json: { username: alice, password: 'nope' } })).status === 400);
  check('correct password accepted',
    (await req('POST', '/api/auth/login', { json: { username: alice, password: PASSWORD } })).status === 200);

  const signup2 = await req('POST', '/api/auth/signup', { json: { username: mallory, password: PASSWORD } });
  const malloryToken = signup2.data && signup2.data.token;
  check('second account created', signup2.status === 200, signup2.data);

  section('token integrity');
  const legacy = Buffer.from(JSON.stringify({ id: 1, username: 'admin', role: 'admin', exp: Date.now() + 1e9 })).toString('base64');
  check('unsigned legacy base64 token rejected',
    (await req('GET', '/api/wallet/balance', { token: legacy })).status === 401);

  const parts = aliceToken.split('.');
  const swapped = Buffer.from(JSON.stringify({ v: 'v1', username: 'admin', role: 'admin', exp: Date.now() + 1e9 }))
    .toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  check('payload swapped under a valid signature rejected',
    (await req('GET', '/api/wallet/balance', { token: `${parts[0]}.${swapped}.${parts[2]}` })).status === 401);

  section('wallet isolation');
  check('anonymous balance read rejected', (await req('GET', '/api/wallet/balance')).status === 401);
  const aliceBal = await req('GET', '/api/wallet/balance', { token: aliceToken });
  check('own balance readable', aliceBal.status === 200 && typeof aliceBal.data.balance === 'number', aliceBal.data);

  await req('POST', '/api/game_sync.php?action=color_place_bet', { token: malloryToken, json: { room: 'sapre', category: 'color', value: 'red', amount: 7 } });
  const malloryBal = (await req('GET', '/api/wallet/balance', { token: malloryToken })).data.balance;
  const redirected = await req('GET', `/api/wallet/balance?username=${alice}`, { token: malloryToken });
  check('?username= cannot redirect a read to another account',
    redirected.status === 200 && Math.abs(redirected.data.balance - malloryBal) < 0.01, { redirected: redirected.data, malloryBal });

  section('free-money holes');
  check('anonymous /api/wallet/adjust blocked',
    [401, 403].includes((await req('POST', '/api/wallet/adjust', { json: { username: alice, delta: 100000 } })).status));
  check('player /api/wallet/adjust blocked',
    (await req('POST', '/api/wallet/adjust', { token: aliceToken, json: { username: alice, delta: 100000 } })).status === 403);
  check('player /api/wallet.php adjust blocked',
    (await req('POST', '/api/wallet.php', { token: aliceToken, json: { delta: 100000 } })).status === 403);
  check('anonymous /api/wallet/reset blocked',
    [401, 403].includes((await req('POST', '/api/wallet/reset', { json: { username: alice } })).status));

  section('operator surface');
  const adminOnly = [
    '/api/admin/stats', '/api/admin/super-dashboard', '/api/admin/game-stats',
    '/api/db/users', '/api/db/deposits', '/api/db/withdrawals', '/api/db/game-bets',
    '/api/mines/active-users', '/api/mines/admin/rig', '/api/bot_status/aviator'
  ];
  for (const p of adminOnly) {
    check(`anonymous ${p} blocked`, [401, 403].includes((await req('GET', p)).status));
    check(`player ${p} blocked`, (await req('GET', p, { token: aliceToken })).status === 403);
  }
  check('anonymous mines rig blocked',
    [401, 403].includes((await req('POST', '/api/mines/admin/rig', { json: { trigger_trap: true } })).status));
  check('player house-takeover toggle blocked',
    (await req('POST', '/api/game_sync.php?action=admin_set_bot_takeover', { token: aliceToken, json: { game: 'global', enabled: true, profit_pct: 100 } })).status === 403);
  check('anonymous admin_get_live_state blocked',
    (await req('GET', '/api/game_sync.php?action=admin_get_live_state')).status === 403);

  section('operator login');
  check('bad admin password rejected',
    (await req('POST', '/api/admin.php?action=login', { form: { username: 'admin', password: 'wrong' } })).status === 401);
  check('login_bypass no longer exists',
    (await req('POST', '/api/admin.php?action=login_bypass', {})).status === 401);

  const adminLogin = await req('POST', '/api/admin.php?action=login', { form: { username: 'admin', password: process.env.ADMIN_PASSWORD } });
  check('admin login succeeds', adminLogin.status === 200 && !!adminLogin.data.token, adminLogin.data);
  const adminToken = adminLogin.data && adminLogin.data.token;

  const adminUsers = await req('GET', '/api/admin.php?action=users', { token: adminToken });
  check('admin can list users', adminUsers.status === 200 && Array.isArray(adminUsers.data));
  check('user list never contains password hashes',
    Array.isArray(adminUsers.data) && adminUsers.data.every(u => !('password' in u)));
  check('admin stats reachable',
    (await req('GET', '/api/admin.php?action=stats', { token: adminToken })).status === 200);
  check('admin can credit a player',
    (await req('POST', '/api/admin.php', { token: adminToken, form: { action: 'adjust_balance', username: alice, amount: 500, type: 'add' } })).data.success === true);

  section('gameplay');
  const before = (await req('GET', '/api/wallet/balance', { token: aliceToken })).data.balance;
  check('anonymous colour bet blocked',
    (await req('POST', '/api/game_sync.php?action=color_place_bet', { json: { room: 'sapre', category: 'color', value: 'red', amount: 10 } })).status === 401);

  const bet = await req('POST', '/api/game_sync.php?action=color_place_bet', { token: aliceToken, json: { room: 'sapre', category: 'color', value: 'red', amount: 10 } });
  check('colour bet accepted', bet.status === 200 && bet.data.success, bet.data);
  check('exactly the stake was debited', Math.abs((before - 10) - bet.data.new_balance) < 0.01, { before, after: bet.data.new_balance });

  await req('POST', '/api/game_sync.php?action=color_place_bet', { token: malloryToken, json: { username: alice, room: 'sapre', category: 'color', value: 'red', amount: 10 } });
  const aliceUntouched = (await req('GET', '/api/wallet/balance', { token: aliceToken })).data.balance;
  check('one player cannot bet from another wallet', Math.abs(aliceUntouched - bet.data.new_balance) < 0.01, { aliceUntouched, expected: bet.data.new_balance });

  check('over-limit stake rejected',
    (await req('POST', '/api/game_sync.php?action=color_place_bet', { token: aliceToken, json: { room: 'sapre', category: 'color', value: 'red', amount: 99999999 } })).status === 400);
  check('negative stake rejected',
    (await req('POST', '/api/game_sync.php?action=color_place_bet', { token: aliceToken, json: { room: 'sapre', category: 'color', value: 'red', amount: -500 } })).status === 400);
  check('unknown room rejected',
    (await req('POST', '/api/game_sync.php?action=color_place_bet', { token: aliceToken, json: { room: 'nowhere', category: 'color', value: 'red', amount: 10 } })).status === 400);

  const preMines = (await req('GET', '/api/wallet/balance', { token: aliceToken })).data.balance;
  const mines = await req('POST', '/api/mines/start', { token: aliceToken, json: { bet_amount: 10, mines_count: 3 } });
  check('mines round starts', mines.status === 200 && mines.data.ok, mines.data);
  check('mines debits the stake', Math.abs((preMines - 10) - mines.data.state.balance) < 0.01, { preMines, after: mines.data.state.balance });
  check('a second concurrent mines round is refused',
    (await req('POST', '/api/mines/start', { token: aliceToken, json: { bet_amount: 10, mines_count: 3 } })).status === 400);
  check('anonymous mines start blocked',
    (await req('POST', '/api/mines/start', { json: { username: alice, bet_amount: 10 } })).status === 401);

  section('concurrency');
  await req('POST', '/api/admin.php', { token: adminToken, form: { action: 'adjust_balance', username: mallory, amount: 100, type: 'add' } });
  const raceBalance = (await req('GET', '/api/wallet/balance', { token: malloryToken })).data.balance;
  const allIn = Math.floor(raceBalance);
  const races = await Promise.all([0, 1, 2, 3, 4].map(() =>
    req('POST', '/api/game_sync.php?action=color_place_bet', { token: malloryToken, json: { room: 'becone', category: 'color', value: 'green', amount: allIn } })));
  const accepted = races.filter(r => r.status === 200).length;
  const raceAfter = (await req('GET', '/api/wallet/balance', { token: malloryToken })).data.balance;
  check(`only one of five simultaneous all-in bets settles (got ${accepted})`, accepted === 1, races.map(r => r.status));
  check('balance never goes negative', raceAfter >= -0.001, { raceAfter });

  section('chat');
  check('anonymous chat post blocked',
    (await req('POST', '/api/chat.php', { json: { username: 'admin', message: 'hello' } })).status === 401);
  const spoof = await req('POST', '/api/chat.php', { token: aliceToken, json: { username: 'SystemAdmin', message: 'name spoof attempt' } });
  check('chat name comes from the session, not the body',
    spoof.status === 200 && spoof.data.message.username.toLowerCase() === alice.toLowerCase(), spoof.data);

  section('cashier');
  check('anonymous deposit blocked',
    (await req('POST', '/api/deposit.php?action=submit_upi_deposit', { form: { amount: 500, utr: 'UTRANON' + stamp } })).status === 401);
  const preDeposit = (await req('GET', '/api/wallet/balance', { token: aliceToken })).data.balance;
  const deposit = await req('POST', '/api/deposit.php?action=submit_upi_deposit', { token: aliceToken, form: { amount: 500, utr: 'UTR' + stamp } });
  check('deposit request accepted', deposit.status === 200 && deposit.data.success, deposit.data);
  check('deposit is recorded Pending, not credited', deposit.data.status === 'Pending', deposit.data);
  const postDeposit = (await req('GET', '/api/wallet/balance', { token: aliceToken })).data.balance;
  check('an unverified UTR does not credit the wallet', Math.abs(postDeposit - preDeposit) < 0.01, { preDeposit, postDeposit });

  check('below-minimum withdrawal rejected',
    (await req('POST', '/api/withdraw.php', { token: aliceToken, form: { amount: 5, method: 'upi', upi_id: 'someone@okaxis' } })).status === 400);
  check('malformed UPI id rejected',
    (await req('POST', '/api/withdraw.php', { token: aliceToken, form: { amount: 300, method: 'upi', upi_id: 'not-a-upi' } })).status === 400);
  check('withdrawal above balance rejected',
    (await req('POST', '/api/withdraw.php', { token: aliceToken, form: { amount: 49000, method: 'upi', upi_id: 'someone@okaxis' } })).status === 400);

  section('static exposure');
  const mustNotLeak = [
    '/backend/.env', '/backend/server.js', '/backend/config.js', '/backend/lib/auth.js',
    '/backend/data/users.json', '/api/config.php', '/backend/api/config.php',
    '/.git/config', '/backend/package.json', '/backend/prisma/schema.prisma',
    '/backend/server.js.orig-backup'
  ];
  for (const p of mustNotLeak) {
    const res = await fetch(BASE + p);
    const body = await res.text();
    const secretish = /RAZORPAY_KEY_SECRET|DATABASE_URL|postgresql:\/\/|\$2[aby]\$|PrismaClient|APP_SECRET/.test(body);
    check(`${p} not served`, res.status === 404 && !secretish, { status: res.status, snippet: body.slice(0, 100) });
  }
  check('traversal to backend/.env blocked', (await fetch(BASE + '/assets/../backend/.env')).status === 404);
  check('index.html still served', (await fetch(BASE + '/index.html')).status === 200);
  check('stylesheet still served', (await fetch(BASE + '/assets/css/style.css')).status === 200);
  check('shared script still served', (await fetch(BASE + '/assets/js/ui-common.js')).status === 200);

  section('response headers');
  const headRes = await fetch(BASE + '/api/health');
  const csp = headRes.headers.get('content-security-policy') || '';
  check('X-Powered-By removed', !headRes.headers.get('x-powered-by'));
  check('Content-Security-Policy set', !!csp);
  check("CSP forbids 'unsafe-eval'", !csp.includes('unsafe-eval'), csp);
  // These pages wire up ~150 buttons with onclick=/onsubmit=, including the login/signup modal's
  // tabs, close button and submit handler. Helmet defaults script-src-attr to 'none', which blocks
  // inline event-handler attributes even though script-src allows 'unsafe-inline' — every one of
  // those buttons goes dead in the browser while the server still looks perfectly healthy. If this
  // assertion ever fails, the site is unusable no matter what the other 99 checks say.
  check('CSP still permits inline event handlers', /script-src-attr[^;]*'unsafe-inline'/.test(csp), csp);
  check('X-Content-Type-Options: nosniff', headRes.headers.get('x-content-type-options') === 'nosniff');
  check('framing blocked', !!headRes.headers.get('x-frame-options') || csp.includes('frame-ancestors'));
  const unknown = await req('GET', '/api/no-such-endpoint');
  check('unknown /api path answers JSON 404', unknown.status === 404 && typeof unknown.data === 'object', unknown.data);

  section('bot rig percentage engine');
  // Regression for two reported bugs, in order:
  //
  //   1. "Even at 50% bot activation, admin is winning almost all of the matches." The v1 decision
  //      engine was a running counter — `shouldRig = (counter % 100) < pct` — which handed out the
  //      first pct calls out of every 100 all true in an unbroken row, then the rest all false.
  //   2. After fixing (1) with a single fully-shuffled 100-slot bag: "10 games, 8 won by admin, at
  //      50%, is not 50%." A genuinely independent shuffle of 50 true / 50 false slots can still
  //      cluster locally by chance — measured at ~4.6% of 10-round windows showing 8-or-worse, almost
  //      as bad as a plain coin flip. The v3 fix (this one) splits the 100 slots into 10 buckets of
  //      10, spreads the pct true slots across buckets as evenly as the standard "K into N buckets"
  //      formula allows (exact for any integer 1-100, no rounding drift), shuffles within each
  //      bucket, and shuffles bucket order — cutting that 8-or-worse rate to well under 1%.
  //
  //      Separately, Teen Patti had its own architectural double-count: a room-selection step at
  //      toggle time picked round(pct/100 * 6) rooms to independently reserve the house's own seat,
  //      stacking on top of (not summing with) the per-round engine every other rig path already
  //      drew from. That mechanism (pendingAdminSeats / entryPosition / roomsToRigCount) is gone —
  //      Admin is seated by exactly one decision per round now, the same shouldBotRigThisRound draw.
  //
  // This extracts and executes the actual current source of shouldBotRigThisRound() and its helpers
  // straight out of server.js (not a hand-copied snapshot), so the test can never quietly drift out
  // of sync with the real implementation.
  {
    const src = fs.readFileSync(path.join(__dirname, 'server.js'), 'utf8');

    function extractFunction(source, signature) {
      const start = source.indexOf(signature);
      if (start === -1) throw new Error(`Could not find "${signature}" in server.js`);
      const braceStart = source.indexOf('{', start);
      let depth = 0, i = braceStart;
      for (; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') { depth--; if (depth === 0) break; }
      }
      return source.slice(start, i + 1);
    }

    function extractLine(source, marker) {
      const start = source.indexOf(marker);
      if (start === -1) throw new Error(`Could not find "${marker}" in server.js`);
      return source.slice(start, source.indexOf('\n', start));
    }

    function newSandbox() {
      const vm = require('vm');
      const parts = [
        extractLine(src, 'const BOT_RIG_BUCKETS ='),
        extractLine(src, 'const BOT_RIG_BUCKET_SIZE ='),
        extractFunction(src, 'function tpShuffle('),
        extractFunction(src, 'function buildBotRigBag('),
        extractFunction(src, 'function ensureBotRigBag('),
        extractFunction(src, 'function isBotTakeoverActive('),
        extractFunction(src, 'function shouldBotRigThisRound(')
      ];
      const sandbox = {
        botRigBags: { color_guess: null },
        botTakeoverState: { color_guess: { enabled: true, profit_pct: 50 } },
        persistBotRigBag: () => {} // real persistence isn't the concern of this test
      };
      vm.createContext(sandbox);
      vm.runInContext(parts.join('\n'), sandbox);
      return sandbox;
    }

    let bagStatsOk = false, bagDetail = {};
    try {
      const sandbox = newSandbox();
      sandbox.botTakeoverState.color_guess.profit_pct = 50;
      const draw = () => sandbox.shouldBotRigThisRound('color_guess').shouldRig;

      const TRIALS = 400, ROUNDS = 100;
      let totalDraws = 0, totalRigged = 0, extreme8 = 0, windows = 0;
      const perCycleCounts = [];
      for (let t = 0; t < TRIALS; t++) {
        sandbox.botRigBags.color_guess = null; // fresh cycle per trial
        const seq = [];
        for (let i = 0; i < ROUNDS; i++) { const r = draw(); seq.push(r); totalDraws++; if (r) totalRigged++; }
        perCycleCounts.push(seq.filter(Boolean).length);
        for (let s0 = 0; s0 + 10 <= ROUNDS; s0++) {
          if (seq.slice(s0, s0 + 10).filter(Boolean).length >= 8) extreme8++;
          windows++;
        }
      }

      const exactEveryCycle = perCycleCounts.every(c => c === 50);
      const overallExact = totalRigged === totalDraws / 2;
      // The reported symptom, directly: how often does a 10-round window show 8 or more rigged? A
      // naive independent 50% coin flip already sits near 5.5%; the old fully-shuffled-bag measured
      // ~4.6%. This must land far below that.
      const extreme8Rate = extreme8 / windows;
      const tenRoundWindowsTight = extreme8Rate < 0.02;
      bagDetail = { totalRigged, totalDraws, exactEveryCycle, extreme8Rate: (extreme8Rate * 100).toFixed(2) + '%' };
      bagStatsOk = exactEveryCycle && overallExact && tenRoundWindowsTight;
    } catch (e) {
      bagDetail = { error: e.message, stack: e.stack };
    }
    check('50% config is exactly 50/100 every complete cycle, and 10-round windows stay tight (not just the 100-round total)', bagStatsOk, bagDetail);

    // A percentage that isn't a multiple of ten (most operator-chosen values) must still land exactly
    // — no rounding bias from however the 100 slots happen to be bucketed internally.
    try {
      const sandbox33 = newSandbox();
      sandbox33.botTakeoverState.color_guess.profit_pct = 33;
      let rigged33 = 0;
      for (let i = 0; i < 100; i++) { if (sandbox33.shouldBotRigThisRound('color_guess').shouldRig) rigged33++; }
      check('a non-round percentage (33%) still rigs exactly 33 out of 100, no rounding drift', rigged33 === 33, { rigged33 });
    } catch (e) {
      check('a non-round percentage (33%) still rigs exactly 33 out of 100, no rounding drift', false, e.message);
    }

    // Edge percentages: 100% must never be false, 1% must be exactly 1 in every 100.
    try {
      const sandbox100 = newSandbox();
      sandbox100.botTakeoverState.color_guess.profit_pct = 100;
      let all100 = true;
      for (let i = 0; i < 100; i++) { if (!sandbox100.shouldBotRigThisRound('color_guess').shouldRig) all100 = false; }
      check('100% config never returns a fair round', all100);

      const sandbox1 = newSandbox();
      sandbox1.botTakeoverState.color_guess.profit_pct = 1;
      let onePctCount = 0;
      for (let i = 0; i < 100; i++) { if (sandbox1.shouldBotRigThisRound('color_guess').shouldRig) onePctCount++; }
      check('1% config rigs exactly 1 out of 100', onePctCount === 1, { onePctCount });

      // The disabled-game gate is the other correctness property this function must never lose: an
      // operator who explicitly turns a game's bot off must get shouldRig:false unconditionally, no
      // matter what percentage is still configured underneath.
      const sandboxOff = newSandbox();
      sandboxOff.botTakeoverState.color_guess = { enabled: false, profit_pct: 90 };
      const offDecision = sandboxOff.shouldBotRigThisRound('color_guess');
      check('a game explicitly turned off never rigs, regardless of its stored percentage', offDecision.shouldRig === false && offDecision.active === false, offDecision);
    } catch (e) {
      check('edge percentages (1%, 100%) behave correctly', false, e.message);
    }

    // Teen Patti's own architectural double-count: a room-selection step used to independently
    // reserve round(pct/100 * 6) rooms for the house's own seat at toggle time, stacking a second,
    // separate application of the same percentage on top of the per-round engine above. It is gone
    // now — Admin is seated by exactly one decision per round, drawn from the same bag every other
    // rig path uses. A literal string check on the source is enough: this mechanism is a handful of
    // uniquely-named identifiers, and it does not partially exist — either the whole thing is there
    // or it isn't.
    const doubleCountGone = !src.includes('pendingAdminSeats') && !src.includes('roomsToRigCount') && !src.includes('entryPosition');
    check('Teen Patti no longer has a second, independent room-selection percentage mechanism', doubleCountGone);
  }

  console.log(`\n=================================================`);
  console.log(`  ${pass} passed, ${failures.length} failed`);
  if (failures.length) failures.forEach(f => console.log(`  - ${f}`));
  console.log(`=================================================\n`);

  server.close();
  process.exit(failures.length ? 1 : 0);
}

main().catch(err => {
  console.error('\nTest runner crashed:', err);
  process.exit(2);
});
