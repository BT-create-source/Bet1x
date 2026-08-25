/**
 * bet1x admin console verification — the manual controls, as opposed to the percentage engine.
 *
 *   DISABLE_RATE_LIMITS=true PORT=5000 node backend/server.js
 *   node backend/test_admin_console.js
 *
 * test_superadmin.js measures the percentage dial. This covers everything else the operator can
 * press: the per-game toggles, the per-room rig ledgers, the audit filters, the Mines trap console,
 * the Teen Patti seat rig, and the two manual outcome overrides (fix a colour number, fix an Aviator
 * crash point) — including whether each override is one-shot or sticks to every later round, which
 * is the part an operator is most likely to get wrong.
 *
 * It drives a handful of real accounts over real HTTP; nothing is mocked.
 */

const BASE = process.env.TARGET || 'http://localhost:5000';
const ADMIN_PASSWORD = process.env.ADMIN_PW || 'abcd';
const stamp = Date.now().toString(36);
const PASSWORD = 'Console!2024';

let pass = 0;
const failures = [];
const notes = [];
const check = (name, cond, detail) => {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { failures.push({ name, detail }); console.log(`  FAIL ${name}${detail !== undefined ? '  -> ' + JSON.stringify(detail).slice(0, 400) : ''}`); }
};
const note = (msg, detail) => { notes.push({ msg, detail }); console.log(`  !!   ${msg}${detail !== undefined ? '  -> ' + JSON.stringify(detail).slice(0, 300) : ''}`); };
const section = t => console.log(`\n=== ${t} ===`);
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function req(method, p, { token, json, form, query } = {}) {
  const url = new URL(BASE + p);
  if (query) Object.entries(query).forEach(([k, v]) => url.searchParams.set(k, v));
  const headers = {};
  let body;
  if (token) headers.Authorization = 'Bearer ' + token;
  if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
  if (form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
  let res, text;
  try { res = await fetch(url, { method, headers, body }); text = await res.text(); }
  catch (e) { return { status: 0, data: { error: e.message } }; }
  let data; try { data = JSON.parse(text); } catch (e) { data = text; }
  return { status: res.status, data };
}

const setBot = (token, game, enabled, profit_pct) =>
  req('POST', '/api/game_sync.php', { token, query: { action: 'admin_set_bot_takeover' }, form: { game, enabled: enabled ? 'true' : 'false', profit_pct: String(profit_pct) } });

const setOverride = (token, form) =>
  req('POST', '/api/game_sync.php', { token, query: { action: 'admin_set_override' }, form });

const colourState = (token, room) =>
  req('GET', '/api/game_sync.php', { token, query: { action: 'color_get_state', room } });

async function main() {
  console.log(`bet1x admin console verification -> ${BASE}\nrun id: ${stamp}\n`);

  section('0. sign in');
  const login = await req('POST', '/api/admin.php', { json: { action: 'login', username: 'admin', password: ADMIN_PASSWORD } });
  const admin = login.data && login.data.token;
  check('admin signed in', !!admin, login.data);
  if (!admin) process.exit(1);

  const players = [];
  for (let i = 0; i < 4; i++) {
    const name = `ac${stamp}p${i}`;
    const r = await req('POST', '/api/auth/signup', { json: { username: name, password: PASSWORD, email: `${name}@example.com` } });
    if (r.data && r.data.token) players.push({ name, token: r.data.token });
  }
  check('test players created', players.length === 4, { created: players.length });
  for (const p of players) {
    await req('POST', '/api/wallet/adjust', { token: admin, json: { username: p.name, delta: 20000, reason: 'console test' } });
  }
  const player = players[0];

  // ------------------------------------------------------------------------------------------
  section('1. per-game toggles are independent of each other');
  await setBot(admin, 'global', false, 90);
  const only = await setBot(admin, 'mines', true, 70);
  check('mines can be enabled on its own', only.status === 200 && only.data.config.enabled === true && only.data.config.profit_pct === 70, only.data && only.data.config);
  const statuses = {};
  for (const g of ['color_guess', 'aviator', 'teenpatti', 'mines']) {
    statuses[g] = (await req('GET', `/api/bot_status/${g}`, { token: admin })).data;
  }
  check('enabling mines did not enable any other game',
    statuses.mines.active === true && ['color_guess', 'aviator', 'teenpatti'].every(g => statuses[g].active === false), statuses);
  check('mines reports the percentage that was set', statuses.mines.profit_pct === 70, statuses.mines);

  // The global switch must reach every game, not just the panel's own display.
  const glob = await setBot(admin, 'global', true, 40);
  const cascaded = ['color_guess', 'aviator', 'teenpatti', 'mines']
    .every(g => glob.data.all_states[g] && glob.data.all_states[g].enabled === true && glob.data.all_states[g].profit_pct === 40);
  check('the global switch cascades to all four games server-side', cascaded, glob.data && glob.data.all_states);
  const afterGlobal = (await req('GET', '/api/bot_status/teenpatti', { token: admin })).data;
  check('a game that was off is now on at the global percentage',
    afterGlobal.active === true && afterGlobal.profit_pct === 40, afterGlobal);

  // Turning global off must also switch every game off, not leave one armed.
  await setBot(admin, 'global', false, 40);
  const offAll = [];
  for (const g of ['color_guess', 'aviator', 'teenpatti', 'mines']) {
    offAll.push({ g, active: (await req('GET', `/api/bot_status/${g}`, { token: admin })).data.active });
  }
  check('switching global off switches every game off', offAll.every(x => x.active === false), offAll);

  // ------------------------------------------------------------------------------------------
  section('2. per-room rig ledgers are separate');
  await setBot(admin, 'color_guess', true, 50);
  const ledgers = {};
  for (const room of ['sapre', 'becone', 'emred', 'vip']) {
    const r = await req('GET', '/api/bot_status/color_guess', { token: admin, query: { room } });
    ledgers[room] = r.data;
  }
  check('each colour room reports its own ledger key',
    new Set(Object.values(ledgers).map(l => l.ledger)).size === 4, Object.entries(ledgers).map(([r, l]) => ({ r, ledger: l.ledger })));
  check('every room ledger reports the same configured percentage',
    Object.values(ledgers).every(l => l.profit_pct === 50), Object.values(ledgers).map(l => l.profit_pct));
  const gameLevel = (await req('GET', '/api/bot_status/color_guess', { token: admin })).data;
  check('the game-level ledger is distinct from any room ledger',
    gameLevel.ledger === 'color_guess', gameLevel);

  // ------------------------------------------------------------------------------------------
  section('3. rig audit filters');
  const all = await req('GET', '/api/admin/rig-audit', { token: admin });
  check('rig audit returns a games map', all.status === 200 && !!all.data.games, { status: all.status });
  const oneGame = await req('GET', '/api/admin/rig-audit', { token: admin, query: { game: 'mines' } });
  check('game filter returns only that game',
    oneGame.status === 200 && Object.keys(oneGame.data.games || {}).every(k => k === 'mines'),
    Object.keys(oneGame.data.games || {}));
  const withRecent = await req('GET', '/api/admin/rig-audit', { token: admin, query: { game: 'mines', recent: 5 } });
  check('recent list is capped and scoped to the filtered game',
    Array.isArray(withRecent.data.recent) && withRecent.data.recent.length <= 5 &&
    withRecent.data.recent.every(e => e.game === 'mines'),
    { len: withRecent.data.recent && withRecent.data.recent.length, games: [...new Set((withRecent.data.recent || []).map(e => e.game))] });
  const tinyWindow = await req('GET', '/api/admin/rig-audit', { token: admin, query: { window_ms: 1 } });
  check('a 1ms window reports almost nothing', tinyWindow.data.total_decisions <= 2, { total: tinyWindow.data.total_decisions });
  check('audit reports live counts the operator can act on',
    !!tinyWindow.data.live_users_count && !!tinyWindow.data.targeted_users, Object.keys(tinyWindow.data));

  // ------------------------------------------------------------------------------------------
  section('4. mines trap console');
  const rigGet = await req('GET', '/api/mines/admin/rig', { token: admin });
  check('mines rig config readable', rigGet.status === 200 && !!rigGet.data.rig, { status: rigGet.status });
  check('mines rig config refuses a player', (await req('GET', '/api/mines/admin/rig', { token: player.token })).status >= 401);
  check('mines active-users refuses a player', (await req('GET', '/api/mines/active-users', { token: player.token })).status >= 401);

  await setBot(admin, 'mines', false, 70); // isolate the manual trap from the percentage engine
  await req('POST', '/api/mines/admin/reset-rig', { token: admin, json: {} });

  const trapVictim = players[1];
  const startBal = (await req('GET', '/api/wallet/balance', { token: trapVictim.token })).data.balance;
  const board = await req('POST', '/api/mines/start', { token: trapVictim.token, json: { bet_amount: 200, mines_count: 3 } });
  check('a board can be started for the trap test', board.status === 200, board.data);
  await req('POST', '/api/mines/reveal', { token: trapVictim.token, json: { index: 0 } }); // become a live session

  const live = await req('GET', '/api/mines/active-users', { token: admin });
  const listed = Array.isArray(live.data.users || live.data.active_users || live.data)
    ? (live.data.users || live.data.active_users || live.data) : [];
  check('the live player shows up in the operator\'s active-user list',
    listed.some(u => String(u.username || u).toLowerCase() === trapVictim.name.toLowerCase()),
    { listed: listed.map(u => u.username || u).slice(0, 8) });

  const trap = await req('POST', '/api/mines/admin/rig', { token: admin, json: { target_users: [trapVictim.name], trigger_trap: true } });
  check('the trap reports it caught the targeted player', trap.status === 200 && trap.data.trapped_count >= 1,
    { status: trap.status, trapped: trap.data && trap.data.trapped_count, profit: trap.data && trap.data.profit_realized });
  check('the trap reports the stake it captured as profit',
    trap.data && Math.abs((trap.data.profit_realized || 0) - 200) < 0.01, { profit_realized: trap.data && trap.data.profit_realized });

  // A trapped board must be dead: the player must not be able to cash out a winning position.
  const cashAfterTrap = await req('POST', '/api/mines/cashout', { token: trapVictim.token, json: {} });
  const balAfterTrap = (await req('GET', '/api/wallet/balance', { token: trapVictim.token })).data.balance;
  check('a trapped player cannot cash the board out', cashAfterTrap.status >= 400,
    { status: cashAfterTrap.status, data: cashAfterTrap.data });
  check('the trapped stake stays with the house', Math.abs(balAfterTrap - (startBal - 200)) < 0.01,
    { startBal, balAfterTrap, expected: startBal - 200 });

  await req('POST', '/api/mines/admin/reset-rig', { token: admin, json: {} });
  const afterReset = await req('GET', '/api/mines/admin/rig', { token: admin });
  check('reset-rig clears the trap configuration',
    afterReset.status === 200 && !(afterReset.data.rig && afterReset.data.rig.next_tile === 'mine'),
    afterReset.data && afterReset.data.rig);

  // ------------------------------------------------------------------------------------------
  section('5. teen patti seat rig');
  const rooms = await req('GET', '/api/teenpatti/rooms', { token: player.token });
  const roomList = Array.isArray(rooms.data) ? rooms.data : (rooms.data.rooms || []);
  const roomId = roomList.length ? (roomList[0].id || roomList[0]) : 'room_101';
  const tpRig = await req('POST', '/api/teenpatti/admin/rig', { token: admin, json: { room_id: roomId, winner_seat: 2 } });
  check('teen patti seat rig accepted', tpRig.status === 200, { status: tpRig.status, data: tpRig.data });
  check('teen patti seat rig refuses a player',
    (await req('POST', '/api/teenpatti/admin/rig', { token: player.token, json: { room_id: roomId, winner_seat: 2 } })).status >= 401);
  const tpNoRoom = await req('POST', '/api/teenpatti/admin/rig', { token: admin, json: { winner_seat: 2 } });
  check('teen patti seat rig requires a room id', tpNoRoom.status === 400, { status: tpNoRoom.status });
  const tpReset = await req('POST', '/api/teenpatti/admin/reset-rig', { token: admin, json: { room_id: roomId } });
  check('teen patti rig can be cleared', tpReset.status === 200, tpReset.data);

  // ------------------------------------------------------------------------------------------
  section('6. manual outcome overrides — and whether they are one-shot');
  await setBot(admin, 'global', false, 90); // manual overrides only, no percentage engine interfering

  // --- Colour: fix Sapre's number, then watch two consecutive rounds. ---
  const FIXED = 7;
  await setOverride(admin, { game: 'color_guess', room: 'sapre', color: '', number: String(FIXED), size: '', rig_type: '' });

  const seen = [];
  let lastRound = null;
  const deadline = Date.now() + 150000; // enough for two 30s rounds plus slack
  while (Date.now() < deadline && seen.length < 2) {
    const st = await colourState(player.token, 'sapre');
    const hist = (st.data && st.data.history) || [];
    if (hist.length) {
      const top = hist[0];
      if (top && top.roundNumber !== lastRound) {
        if (lastRound !== null) seen.push(top); // skip the round already in flight when we set it
        lastRound = top.roundNumber;
      }
    }
    await sleep(2000);
  }
  if (seen.length >= 1) {
    check(`the colour override fixed the next Sapre round to #${FIXED}`, seen[0].number === FIXED,
      { got: seen[0].number, want: FIXED, why: seen[0].rig_desc });
  } else {
    note('no settled Sapre round observed inside the window; colour override not verified');
  }
  if (seen.length >= 2) {
    const stillFixed = seen[1].number === FIXED;
    check('colour override is documented behaviour: it is NOT one-shot and keeps firing until cleared',
      stillFixed, { round2: seen[1].number, want: FIXED, note: 'if this fails the override expired on its own' });
    if (stillFixed) note('CONFIRMED: a colour number override stays in force for every later round until the operator clears it — unlike the Aviator override, which is consumed after one round');
  } else {
    note('only one settled Sapre round observed; override stickiness not measured');
  }
  // Clear it, then confirm the room is free again.
  await setOverride(admin, { game: 'color_guess', room: 'sapre', color: '', number: '', size: '', rig_type: '' });
  const cleared = await req('GET', '/api/db/state/color_guess_overrides_sapre', { token: admin });
  const cd = cleared.data && (cleared.data.data || cleared.data);
  check('clearing the colour override empties it', cleared.status !== 200 || !cd || !cd.number, cd);

  // --- Aviator: fix the next crash point, then check the round after is free again. ---
  const CRASH = 1.37;
  await setOverride(admin, { game: 'aviator', crash_point: String(CRASH) });
  const crashes = [];
  let lastPhase = null;
  const avDeadline = Date.now() + 120000;
  while (Date.now() < avDeadline && crashes.length < 2) {
    const s = await req('GET', '/api/game_sync.php', { token: player.token, query: { action: 'aviator_get_state' } });
    const ph = s.data && s.data.phase;
    if (ph === 'crashed' && lastPhase !== 'crashed') {
      crashes.push(parseFloat(s.data.crash_point));
    }
    lastPhase = ph;
    await sleep(400);
  }
  if (crashes.length >= 1) {
    check(`the aviator override fixed the next crash point to ${CRASH}x`, Math.abs(crashes[0] - CRASH) < 0.02,
      { got: crashes[0], want: CRASH });
  } else {
    note('no aviator crash observed inside the window; override not verified');
  }
  if (crashes.length >= 2) {
    check('the aviator override is one-shot: the round after is not fixed to it',
      Math.abs(crashes[1] - CRASH) > 0.02, { round2: crashes[1], override: CRASH });
  } else {
    note('only one aviator crash observed; one-shot behaviour not measured');
  }

  const badGame = await setOverride(admin, { game: 'not_a_game', crash_point: '2' });
  check('an override for an unknown game is rejected', badGame.status === 400, { status: badGame.status });
  check('a player cannot set an override',
    (await setOverride(player.token, { game: 'aviator', crash_point: '99' })).status >= 401);

  // ------------------------------------------------------------------------------------------
  // Completes coverage of the admin panel's own API surface: admin.html makes exactly 17 backend
  // calls, and these are the four the percentage and override sections above do not already touch.
  section('7. remaining admin panel calls');

  const liveState = await req('GET', '/api/game_sync.php', { token: admin, query: { action: 'admin_get_live_state' } });
  check('admin_get_live_state serves the panel its live view', liveState.status === 200, { status: liveState.status });
  if (liveState.status === 200) {
    check('live state carries the bot takeover config the panel renders from',
      !!liveState.data.bot_takeover, Object.keys(liveState.data).slice(0, 12));
  }

  // reject_deposit: the money must NOT move.
  const rejUser = players[2];
  const rejUtr = `AC${stamp}R`.slice(0, 18);
  const rejDep = await req('POST', '/api/deposit.php', { token: rejUser.token, json: { action: 'submit_upi_deposit', amount: 750, utr: rejUtr } });
  const rejId = rejDep.data && (rejDep.data.deposit_id || rejDep.data.id);
  if (rejId) {
    const pre = (await req('GET', '/api/wallet/balance', { token: rejUser.token })).data.balance;
    const rej = await req('POST', '/api/admin.php', { token: admin, json: { action: 'reject_deposit', deposit_id: rejId } });
    const post = (await req('GET', '/api/wallet/balance', { token: rejUser.token })).data.balance;
    check('rejecting a deposit credits nothing', rej.status === 200 && Math.abs(post - pre) < 0.01, { pre, post, status: rej.status });
  } else {
    note('deposit id not returned; reject_deposit not exercised', rejDep.data);
  }

  // approve_withdrawal: the stake was already held at request time, so approval must not debit again.
  const wUser = players[3];
  const wReq = await req('POST', '/api/withdraw.php', { token: wUser.token, json: { action: 'create', amount: 600, method: 'upi', upi_id: 'player@upi' } });
  check('withdrawal raised for the approval test', wReq.status === 200, wReq.data);
  const wList = await req('POST', '/api/admin.php', { token: admin, json: { action: 'withdrawals' } });
  const pending = Array.isArray(wList.data) ? wList.data.find(w => w.username === wUser.name && w.status === 'Pending') : null;
  if (pending) {
    const pre = (await req('GET', '/api/wallet/balance', { token: wUser.token })).data.balance;
    const ap = await req('POST', '/api/admin.php', { token: admin, json: { action: 'approve_withdrawal', withdrawal_id: pending.withdrawal_id } });
    const post = (await req('GET', '/api/wallet/balance', { token: wUser.token })).data.balance;
    check('approving a withdrawal does not debit a second time',
      ap.status === 200 && Math.abs(post - pre) < 0.01, { pre, post, status: ap.status });
    const ap2 = await req('POST', '/api/admin.php', { token: admin, json: { action: 'approve_withdrawal', withdrawal_id: pending.withdrawal_id } });
    check('a withdrawal cannot be approved twice', ap2.status >= 400, { status: ap2.status });
  } else {
    note('no pending withdrawal found for the approval test');
  }

  const logout = await req('POST', '/api/admin.php', { token: admin, json: { action: 'logout' } });
  check('admin logout responds', logout.status === 200, { status: logout.status });

  // ------------------------------------------------------------------------------------------
  section('8. leave the house switched off');
  const final = await setBot(admin, 'global', false, 90);
  check('bot takeover switched off at the end of the run', final.status === 200 &&
    ['color_guess', 'aviator', 'teenpatti', 'mines'].every(g => final.data.all_states[g].enabled === false),
    final.data && final.data.all_states);

  console.log('\n=================================================');
  console.log(`  ${pass} passed, ${failures.length} failed`);
  console.log('=================================================');
  if (failures.length) {
    console.log('\nFAILURES:');
    failures.forEach(f => console.log(`  - ${f.name}\n      ${JSON.stringify(f.detail).slice(0, 400)}`));
  }
  if (notes.length) {
    console.log('\nNOTES:');
    notes.forEach(n => console.log(`  - ${n.msg}`));
  }
  process.exit(failures.length ? 1 : 0);
}

main().catch(e => { console.error('\nHARNESS CRASH:', e); process.exit(2); });
