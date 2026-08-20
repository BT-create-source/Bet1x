/**
 * bet1x end-to-end product suite.
 *
 *   npm run test:e2e     (from the repo root)
 *   node backend/test_e2e.js
 *
 * Where test_backend.js asks "is this safe", this asks "does this work": it plays a full player
 * journey over real HTTP — sign up, get topped up, bet in every game, win or lose, deposit, withdraw
 * — and checks the money after each step. Every assertion here is about the wallet arithmetic or the
 * round lifecycle a paying customer would notice, so a refactor that silently swallows a bet or
 * double-pays a cash-out fails the suite instead of shipping.
 *
 * Some of it is necessarily slow: colour rounds are 30 seconds and Aviator flies on its own clock,
 * so the suite waits on the real game loops rather than mocking them. Budget about two minutes.
 *
 * It writes to whatever DATABASE_URL points at, so run it against a development database.
 */

process.env.NODE_ENV = process.env.NODE_ENV || 'development';
process.env.APP_SECRET = process.env.APP_SECRET || 'test-suite-secret-value-at-least-32-chars-long';
process.env.ADMIN_USERNAME = process.env.ADMIN_USERNAME || 'admin';
process.env.ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'test-admin-password';
delete process.env.ADMIN_PASSWORD_HASH;

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

const sleep = ms => new Promise(r => setTimeout(r, ms));

// Wallet balances are money: compare them with a cent of tolerance rather than ===, because payouts
// go through a multiplier and a round-trip via the database.
const money = (a, b) => Math.abs(a - b) < 0.01;

async function main() {
  section('boot');
  const app = require('./server.js');
  const server = await new Promise(resolve => {
    const s = app.listen(0, '127.0.0.1', () => resolve(s));
  });
  const BASE = `http://127.0.0.1:${server.address().port}`;
  check('server accepts connections', !!server.address().port);

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

  const balance = async token => (await req('GET', '/api/wallet/balance', { token })).data.balance;
  const colorState = async (token, room) =>
    (await req('GET', '/api/game_sync.php', { token, query: { action: 'color_get_state', room } })).data;
  const aviatorPhase = async token => {
    const d = (await req('GET', '/api/game_sync.php', { token, query: { action: 'aviator_get_state' } })).data;
    return (d && (d.phase || (d.state && d.state.phase))) || null;
  };

  const stamp = Date.now().toString(36);
  const player = 'e2e' + stamp;
  const PASSWORD = 'Str0ngPassw0rd!';
  let token, adminToken;

  section('sign up and sign in');
  {
    const signup = await req('POST', '/api/auth/signup', { json: { username: player, password: PASSWORD, email: player + '@example.com' } });
    check('signup succeeds', signup.status === 200, signup.data);
    token = signup.data && signup.data.token;
    check('signup issues a session token', !!token);
    check('new account is funded with the signup bonus', (await balance(token)) > 0);

    const login = await req('POST', '/api/auth/login', { json: { username: player, password: PASSWORD } });
    check('the password works on the login endpoint too', login.status === 200 && !!login.data.token, login.data);
    token = login.data.token || token;

    const admin = await req('POST', '/api/admin.php', { json: { action: 'login', username: 'admin', password: process.env.ADMIN_PASSWORD } });
    adminToken = admin.data && admin.data.token;
    check('the operator can sign in', admin.status === 200 && !!adminToken, admin.data);
  }

  section('operator tops the player up');
  {
    const before = await balance(token);
    const adj = await req('POST', '/api/wallet/adjust', { token: adminToken, json: { username: player, delta: 50000, reason: 'e2e funding' } });
    check('operator credit accepted', adj.status === 200, adj.data);
    check('credit lands to the cent', money(await balance(token), before + 50000));
  }

  section('colour prediction: a selection that cannot win is refused');
  {
    // settleColorRound() matches a bet against the canonical outcome with ===, so anything outside
    // the real selection set would take the stake and lose every single round.
    const cases = [
      ['color', 'banana', 'nonsense colour'],
      ['size', 'huge', 'nonsense size'],
      ['number', '42', 'out-of-range number'],
      ['number', 'x', 'non-numeric number'],
      ['sandwich', 'Green', 'unknown category']
    ];
    for (const [category, value, label] of cases) {
      const r = await req('POST', '/api/game_sync.php', { token, json: { action: 'color_place_bet', room: 'sapre', category, value, amount: 10 } });
      check(`${label} rejected`, r.status === 400, { status: r.status, body: r.text });
    }
    const badRoom = await req('POST', '/api/game_sync.php', { token, json: { action: 'color_place_bet', room: 'nope', category: 'color', value: 'Green', amount: 10 } });
    check('unknown room rejected', badRoom.status === 400, badRoom.data);
    const tooBig = await req('POST', '/api/game_sync.php', { token, json: { action: 'color_place_bet', room: 'sapre', category: 'color', value: 'Green', amount: 99999999 } });
    check('a stake past the table maximum is refused', tooBig.status === 400, tooBig.data);

    for (const room of ['sapre', 'becone', 'emred', 'vip']) {
      const s = await colorState(token, room);
      check(`room ${room} reports a round and a countdown`, !!s && s.round_id !== undefined && s.time_left !== undefined, s);
    }
  }

  section('colour prediction: a round settles and pays');
  {
    // Cover all three colours in one round so exactly one bet must win whatever the house does.
    let s = await colorState(token, 'sapre');
    while (s.time_left < 12) { await sleep(1000); s = await colorState(token, 'sapre'); }
    const roundId = s.round_id;
    const before = await balance(token);
    const STAKE = 100;

    for (const value of ['green', 'Red', 'VIOLET']) { // mixed case on purpose: must be canonicalised
      const r = await req('POST', '/api/game_sync.php', { token, json: { action: 'color_place_bet', room: 'sapre', category: 'color', value, amount: STAKE } });
      check(`bet on "${value}" accepted`, r.status === 200 && r.data.success, r.data);
    }
    const afterBets = await balance(token);
    check('three stakes debited, nothing more', money(afterBets, before - 3 * STAKE), { before, afterBets });

    // Settlement runs lazily on the first state read after the round boundary, so keep polling.
    let result = null, waited = 0;
    while (!result && waited < 90000) {
      await sleep(2000); waited += 2000;
      const cur = await colorState(token, 'sapre');
      result = (cur.history || []).find(h => String(h.roundNumber) === String(roundId));
    }
    check('the round resolves and reaches the results table', !!result, { roundId, waited });

    if (result) {
      console.log(`       result #${result.number} ${result.color}/${result.size}${result.is_rigged ? '  [' + result.rig_desc + ']' : ''}`);
      await sleep(1500); // let the payout write land
      const settled = await balance(token);
      const expected = afterBets + STAKE * (result.color === 'Violet' ? 4.5 : 2.0);
      check('the winning colour pays at its stated multiplier', money(settled, expected), { afterBets, settled, expected, color: result.color });

      const tx = await req('GET', '/api/wallet/transactions', { token });
      const rows = Array.isArray(tx.data) ? tx.data : tx.data.transactions;
      check('the payout is written to the transaction history', rows.some(t => (t.details || '').includes('Win Payout') && (t.details || '').includes('#' + roundId)));
      check('all three wagers are written to the transaction history', rows.filter(t => (t.details || '').includes('Round #' + roundId) && (t.details || '').includes('Wager')).length === 3);
    }
  }

  section('aviator');
  {
    // A full cycle is 5s of betting, then a flight, then a 4s pause. The flight lasts as long as the
    // crash point takes to reach at exp(0.06t), so a 50x round is ~65 seconds in the air and one
    // cycle can run to about 74s. Wait past that or the suite fails on a legitimately long round.
    let phase = await aviatorPhase(token), waited = 0;
    while (phase !== 'waiting' && waited < 90000) { await sleep(1000); waited += 1000; phase = await aviatorPhase(token); }
    check('a betting window opens', phase === 'waiting', { phase, waited });

    if (phase === 'waiting') {
      const before = await balance(token);
      const bet = await req('POST', '/api/game_sync.php', { token, json: { action: 'aviator_place_bet', console_id: 1, amount: 50 } });
      check('bet accepted', bet.status === 200 && bet.data.success, bet.data);
      const afterBet = await balance(token);
      check('bet debits exactly the stake', money(afterBet, before - 50), { before, afterBet });

      const dup = await req('POST', '/api/game_sync.php', { token, json: { action: 'aviator_place_bet', console_id: 1, amount: 50 } });
      check('a second bet on the same console in the same round is refused', dup.status === 400, dup.data);

      // A console id outside the two the UI offers parses to NaN, and a bet filed under a NaN console
      // can never be matched by aviator_cashout — the stake would be unrecoverable.
      const held = await balance(token);
      for (const console_id of ['x', 0, 3, null]) {
        const r = await req('POST', '/api/game_sync.php', { token, json: { action: 'aviator_place_bet', console_id, amount: 50 } });
        check(`console "${console_id}" is refused`, r.status === 400, { status: r.status, body: r.text });
      }
      check('the refused bets took no stake', money(await balance(token), held), { held });

      let running = false, w = 0;
      while (!running && w < 30000) { await sleep(400); w += 400; running = (await aviatorPhase(token)) === 'running'; }
      check('the round takes off', running, { waited: w });

      if (running) {
        const out = await req('POST', '/api/game_sync.php', { token, json: { action: 'aviator_cashout', console_id: 1 } });
        // A crash can legitimately beat the request to the server; both outcomes are correct, a
        // double payout is not.
        check('cash-out either pays or reports the round already gone', out.status === 200 || out.status === 400, { status: out.status, body: out.text });
        if (out.status === 200) {
          const paid = await balance(token);
          check('cash-out returns at least the stake', paid >= afterBet + 50 - 0.01, { afterBet, paid });
          const again = await req('POST', '/api/game_sync.php', { token, json: { action: 'aviator_cashout', console_id: 1 } });
          check('the same bet cannot be cashed out twice', again.status === 400, again.data);
          check('the refused second cash-out paid nothing', money(await balance(token), paid));
        }
      }
    }
  }

  section('mines');
  {
    const before = await balance(token);
    // A mines_count that parses to NaN used to pass the 1..24 range check and lay `slice(0, NaN)` —
    // that is, zero mines — giving a board that could be cleared to the top multiplier risk-free.
    for (const [mines_count, label] of [['x', 'a non-numeric mine count'], [0, 'a board with no mines'], [25, 'more mines than tiles']]) {
      const r = await req('POST', '/api/mines/start', { token, json: { bet_amount: 100, mines_count } });
      check(`${label} is refused`, r.status === 400, { status: r.status, body: r.text });
    }
    check('a refused start took no stake', money(await balance(token), before), { before });

    const start = await req('POST', '/api/mines/start', { token, json: { bet_amount: 100, mines_count: 3 } });
    check('a game starts', start.status === 200, start.data);
    const afterStart = await balance(token);
    check('starting debits the stake', money(afterStart, before - 100), { before, afterStart });

    const second = await req('POST', '/api/mines/start', { token, json: { bet_amount: 100, mines_count: 3 } });
    check('a second game cannot be started while one is live', second.status === 400, { status: second.status, body: second.text });

    // A tile index that parses to NaN used to slip past `tileIndex < 0 || tileIndex >= 25` (every NaN
    // comparison is false) and could never be found in mine_positions — a free, repeatable safe
    // reveal. Each of these must be refused outright.
    for (const [json, label] of [
      [{}, 'a reveal with no tile at all'],
      [{ index: 'x' }, 'a non-numeric tile'],
      [{ index: 25 }, 'a tile past the end of the grid'],
      [{ index: -1 }, 'a negative tile']
    ]) {
      const r = await req('POST', '/api/mines/reveal', { token, json });
      check(`${label} is refused`, r.status === 400, { status: r.status, body: r.text });
    }
    // A fractional index is not in the same class: parseInt truncates 1.5 to tile 1, which is a real
    // tile on the grid, so the reveal is honest and is expected to go through.
    const fractional = await req('POST', '/api/mines/reveal', { token, json: { index: 1.5 } });
    check('a fractional tile is truncated to a real tile, not refused', fractional.status === 200 && (fractional.data.state.revealed || []).includes(1), fractional.data);

    const reveal = await req('POST', '/api/mines/reveal', { token, json: { index: 0 } });
    check('a real tile can be revealed', reveal.status === 200, reveal.data);
    const busted = !!(reveal.data && (reveal.data.hit_mine || reveal.data.is_mine || reveal.data.game_over || reveal.data.busted));

    if (busted) {
      check('hitting a mine ends the game and keeps the stake', money(await balance(token), afterStart));
    } else {
      const cash = await req('POST', '/api/mines/cashout', { token, json: {} });
      check('cash-out succeeds after a safe tile', cash.status === 200, cash.data);
      check('cash-out returns more than the stake', (await balance(token)) > afterStart, { afterStart });
    }
    const noGame = await req('POST', '/api/mines/cashout', { token, json: {} });
    check('cashing out with no game running is refused', noGame.status === 400, { status: noGame.status, body: noGame.text });
  }

  section('teen patti');
  {
    const rooms = await req('GET', '/api/teenpatti/rooms', { token });
    check('the lobby lists rooms', rooms.status === 200, rooms.data);
    const list = Array.isArray(rooms.data) ? rooms.data : (rooms.data && rooms.data.rooms);
    check('at least one table exists', Array.isArray(list) && list.length > 0, { n: list && list.length });

    if (Array.isArray(list) && list.length) {
      const room = list[0];
      const roomId = room.id || room.room_id;
      const boot = parseFloat(room.boot_amount) || 0;
      const before = await balance(token);
      const join = await req('POST', '/api/teenpatti/join', { token, json: { room_id: roomId } });
      check('joining a table responds', join.status === 200 || join.status === 400, { status: join.status, body: join.text });

      const state = await req('GET', '/api/teenpatti/state', { token, query: { room_id: roomId } });
      check('table state is readable', state.status === 200, state.text);

      if (join.status === 200) {
        const leave = await req('POST', '/api/teenpatti/leave', { token, json: { room_id: roomId } });
        check('leaving a table responds', leave.status === 200, leave.text);
        const after = await balance(token);
        check('a join then an immediate leave costs at most the boot', after >= before - boot - 0.01, { before, after, boot });
      }
    }
  }

  section('teen patti: turning the bot off must evict an already-seated Admin');
  {
    // Reported bug: the operator turns bot takeover off, but "Admin" — the house's own seat — is
    // left sitting in a room from before the toggle (tpEndGame cosmetically pre-seeds one random
    // filler into a room right after a hand finishes, while bot takeover is on, and that filler can
    // be Admin). The toggle-off handler used to only stop FUTURE rounds from being rigged; it never
    // looked at seats that were already occupied, so a room sitting idle — pot not active, status
    // 'waiting' — kept an Admin seat that would go on to auto-win the very next real hand there,
    // no matter that the operator had just turned the bot off.
    //
    // This needs direct DB access to plant deterministically: the pre-seed only happens on the tail
    // of tpEndGame's 5-second post-round timer, so reaching it by only calling the HTTP API would
    // make this section flaky and slow. Reading and writing Teen Patti seats directly is exactly what
    // the admin console itself does through Prisma, so this is not reaching past a boundary the real
    // app respects.
    const { PrismaClient } = require('@prisma/client');
    const prisma = new PrismaClient();
    const roomId = 'room_106'; // a room untouched by the join/leave test just above

    try {
      await req('POST', '/api/game_sync.php', { token: adminToken, json: { action: 'admin_set_bot_takeover', game: 'teenpatti', enabled: false } });
      await prisma.teenPattiSeat.updateMany({ where: { room_id: roomId }, data: { username: null, is_bot: false, cards: null, folded: false } });
      await prisma.teenPattiRoom.update({ where: { id: roomId }, data: { status: 'waiting', pot: 0, winner_seat: null, admin_rig: null } });
      await prisma.teenPattiSeat.updateMany({ where: { room_id: roomId, seat: 0 }, data: { username: 'Admin', is_bot: false } });

      const listRooms = async () => {
        const r = await req('GET', '/api/teenpatti/rooms', { token });
        const rows = Array.isArray(r.data) ? r.data : (r.data && r.data.rooms) || [];
        return rows.find(x => x.id === roomId);
      };

      const planted = await listRooms();
      check('the idle room is planted with Admin seated (pot not active)', planted && planted.status === 'waiting' && planted.pot === 0 && planted.players.some(p => p.username === 'Admin'), planted);

      const off = await req('POST', '/api/game_sync.php', { token: adminToken, json: { action: 'admin_set_bot_takeover', game: 'teenpatti', enabled: false } });
      check('turning the bot off is accepted', off.status === 200, off.data);

      const after = await listRooms();
      check('Admin is evicted from the idle room the moment the bot is turned off', after && !after.players.some(p => p.username === 'Admin'), after);
    } finally {
      await prisma.$disconnect();
    }
  }

  section('cashier: money in');
  {
    const utr = 'E2E' + stamp.toUpperCase();
    const before = await balance(token);
    const dep = await req('POST', '/api/deposit.php', { token, json: { action: 'submit_upi_deposit', amount: 1000, utr } });
    check('a UPI deposit is accepted', dep.status === 200, dep.data);
    check('it is recorded as Pending, never Completed', dep.data && dep.data.status === 'Pending', dep.data);
    check('the wallet is untouched while it is pending', money(await balance(token), before));

    const replay = await req('POST', '/api/deposit.php', { token, json: { action: 'submit_upi_deposit', amount: 1000, utr } });
    check('the same UTR cannot be submitted twice', replay.status === 409, { status: replay.status });

    const gateway = await req('POST', '/api/deposit.php', { token, json: { action: 'create_order', amount: 1000 } });
    check('the unconfigured card gateway refuses cleanly', gateway.status === 501, { status: gateway.status });

    const depId = dep.data.deposit_id;
    const queue = await req('POST', '/api/admin.php', { token: adminToken, json: { action: 'deposits' } });
    check('the operator sees it in the queue', Array.isArray(queue.data) && queue.data.some(d => d.deposit_id === depId));

    // Two operators clicking Approve at the same instant must credit once, not twice.
    const [a, b] = await Promise.all([
      req('POST', '/api/admin.php', { token: adminToken, json: { action: 'approve_deposit', deposit_id: depId } }),
      req('POST', '/api/admin.php', { token: adminToken, json: { action: 'approve_deposit', deposit_id: depId } })
    ]);
    check('exactly one of two simultaneous approvals wins', [a, b].filter(r => r.status === 200).length === 1, { a: a.status, b: b.status });
    const credited = await balance(token);
    check('the deposit is credited exactly once', money(credited, before + 1000), { before, credited });

    const late = await req('POST', '/api/admin.php', { token: adminToken, json: { action: 'approve_deposit', deposit_id: depId } });
    check('approving an already-settled deposit is refused', late.status === 400, { status: late.status });
    check('the refused approval moved no money', money(await balance(token), credited));
  }

  section('cashier: money out');
  {
    const UPI = 'player.one@okaxis';
    const tooSmall = await req('POST', '/api/withdraw.php', { token, json: { action: 'create', amount: 1, method: 'upi', upi_id: UPI } });
    check('a withdrawal below the minimum is refused', tooSmall.status === 400, tooSmall.data);
    const tooBig = await req('POST', '/api/withdraw.php', { token, json: { action: 'create', amount: 9999999, method: 'upi', upi_id: UPI } });
    check('a withdrawal past the balance is refused', tooBig.status === 400, tooBig.data);
    const badUpi = await req('POST', '/api/withdraw.php', { token, json: { action: 'create', amount: 500, method: 'upi', upi_id: 'nope' } });
    check('a malformed UPI ID is refused', badUpi.status === 400, badUpi.data);

    const before = await balance(token);
    const wd = await req('POST', '/api/withdraw.php', { token, json: { action: 'create', amount: 500, method: 'upi', upi_id: UPI } });
    check('a valid withdrawal is accepted', wd.status === 200, wd.data);
    check('the funds are held the moment it is raised', money(await balance(token), before - 500));

    const wdId = wd.data && wd.data.withdrawal_id;
    if (wdId) {
      const reject = await req('POST', '/api/admin.php', { token: adminToken, json: { action: 'reject_withdrawal', withdrawal_id: wdId } });
      check('the operator can reject it', reject.status === 200, reject.data);
      check('rejection refunds the player in full', money(await balance(token), before));
      const twice = await req('POST', '/api/admin.php', { token: adminToken, json: { action: 'reject_withdrawal', withdrawal_id: wdId } });
      check('it cannot be rejected twice', twice.status === 400, { status: twice.status });
      check('the refused second rejection paid no second refund', money(await balance(token), before));
    }

    const held = await balance(token);
    const wd2 = await req('POST', '/api/withdraw.php', { token, json: { action: 'create', amount: 300, method: 'upi', upi_id: UPI } });
    const wd2Id = wd2.data && wd2.data.withdrawal_id;
    if (wd2Id) {
      const [a, b] = await Promise.all([
        req('POST', '/api/admin.php', { token: adminToken, json: { action: 'approve_withdrawal', withdrawal_id: wd2Id } }),
        req('POST', '/api/admin.php', { token: adminToken, json: { action: 'approve_withdrawal', withdrawal_id: wd2Id } })
      ]);
      check('exactly one of two simultaneous payouts wins', [a, b].filter(r => r.status === 200).length === 1, { a: a.status, b: b.status });
      check('an approved withdrawal stays withdrawn', money(await balance(token), held - 300), { held });
    }
  }

  section('the pages a player actually opens');
  {
    const pages = [
      '/index.html', '/aviator.html', '/win.html', '/win1.html', '/win2.html', '/win3.html',
      '/mining.html', '/teenpatti.html', '/cashier.html', '/admin.html', '/superadmin.html', '/parity.html',
      '/assets/css/style.css', '/assets/js/ui-common.js', '/assets/js/sound-fx.js'
    ];
    for (const p of pages) {
      const res = await fetch(BASE + p);
      check(`serves ${p}`, res.status === 200, { status: res.status });
    }
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
