/**
 * bet1x Teen Patti house-edge verification.
 *
 *   node backend/test_teenpatti_rig.js
 *
 * Teen Patti is the one game where "the house took this hand" and "the house made money on this
 * hand" can come apart, and the broader percentage test never caught it because ten players spread
 * over six tables produced no eligible hands at all.
 *
 * tpStartRound picks the winning seat for a rigged hand like this:
 *
 *     const botSeat  = activeOccupied.find(s => s.is_bot);
 *     const targetSeat = botSeat || activeOccupied[0];
 *
 * When a table has an NPC on it, the NPC is renamed "Admin" and the pot goes to the house. When a
 * table is full of REAL people there is no NPC to find, so it falls back to activeOccupied[0] — a
 * real player — and hands them the pot. The audit still records the hand as rigged.
 *
 * Teen Patti's join endpoint evicts NPCs to seat humans and has an explicit "Room is full with 4
 * real players" path, so an all-human table is reachable in normal play, not a contrivance.
 *
 * This runs the same percentage twice and compares:
 *   A) SPARSE  — one real player per table, three NPCs. The rig has an NPC to hand the pot to.
 *   B) PACKED  — four real players per table, no NPCs. The rig must fall back to a real player.
 * If the fallback is harmless, both should hold money for the house at a similar rate.
 */

const BASE = process.env.TARGET || 'http://localhost:5000';
const ADMIN_PASSWORD = process.env.ADMIN_PW || 'abcd';
const PCT = parseInt(process.env.PCT, 10) || 100;   // 100% makes every eligible hand a rigged hand
const PLAY_MS = (parseInt(process.env.PLAY_SEC, 10) || 240) * 1000;
const stamp = Date.now().toString(36);

let pass = 0;
const failures = [];
const notes = [];
const check = (n, c, d) => { if (c) { pass++; console.log(`  ok   ${n}`); } else { failures.push({ n, d }); console.log(`  FAIL ${n}${d !== undefined ? '  -> ' + JSON.stringify(d).slice(0, 300) : ''}`); } };
const note = (m, d) => { notes.push({ m, d }); console.log(`  !!   ${m}${d !== undefined ? '  -> ' + JSON.stringify(d).slice(0, 250) : ''}`); };
const section = t => console.log(`\n=== ${t} ===`);
const sleep = ms => new Promise(r => setTimeout(r, ms));
const f2 = n => parseFloat((n || 0).toFixed(2));

async function req(method, p, { token, json, form, query } = {}) {
  const url = new URL(BASE + p);
  if (query) Object.entries(query).forEach(([k, v]) => url.searchParams.set(k, v));
  const headers = {}; let body;
  if (token) headers.Authorization = 'Bearer ' + token;
  if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
  if (form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form).toString(); }
  let res, text;
  try { res = await fetch(url, { method, headers, body }); text = await res.text(); }
  catch (e) { return { status: 0, data: { error: e.message } }; }
  let data; try { data = JSON.parse(text); } catch (e) { data = text; }
  return { status: res.status, data };
}
async function pool(items, limit, fn) {
  const out = []; let i = 0;
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (i < items.length) { const idx = i++; out[idx] = await fn(items[idx], idx); }
  }));
  return out;
}

// Teen Patti money for a set of accounts inside a window, from the real Transaction ledger.
async function tpMoney(players, since, until) {
  let wagered = 0, won = 0, betRows = 0, winRows = 0;
  const rows = await pool(players, 8, async p => {
    const tx = await req('GET', '/api/wallet/transactions', { token: p.token });
    return Array.isArray(tx.data) ? tx.data : (tx.data && tx.data.transactions) || [];
  });
  rows.flat().forEach(t => {
    const ts = new Date(t.timestamp || 0).getTime();
    if (!(ts >= since && ts <= until)) return;
    const d = String(t.details || '');
    const amt = parseFloat(t.amount) || 0;
    if (d.includes('Teen Patti Boot') || d.includes('Teen Patti Chaal')) { wagered += amt; betRows++; }
    else if (d.includes('Teen Patti Won Pot')) { won += amt; winRows++; }
  });
  return { wagered: f2(wagered), won: f2(won), profit: f2(wagered - won), betRows, winRows,
           hold_pct: wagered > 0 ? f2(((wagered - won) / wagered) * 100) : 0 };
}

async function runScenario(label, players, perTable, admin, roomIds) {
  section(label);
  // Seat `perTable` real players at each room, in room order.
  const assignments = [];
  let idx = 0;
  for (const room of roomIds) {
    for (let k = 0; k < perTable && idx < players.length; k++) {
      assignments.push({ p: players[idx++], room });
    }
  }
  const joins = await pool(assignments, 6, async a => {
    const r = await req('POST', '/api/teenpatti/join', { token: a.p.token, json: { room_id: a.room } });
    return { ...a, ok: r.status === 200, err: r.data && r.data.error };
  });
  const seated = joins.filter(j => j.ok);
  check(`${label}: players seated`, seated.length >= assignments.length * 0.8,
    { seated: seated.length, of: assignments.length, sampleErr: joins.find(j => !j.ok)?.err });

  const t0 = Date.now();
  // Polling alone is not enough: tpProcessAction gates on room.turn_seat, so a hand stalls on a real
  // player's turn until they act. Seating players and only reading state produced one to three
  // completed hands in three and a half minutes, far too few to measure anything. Each player now
  // plays its own turn — mostly chaal, folding occasionally so hands actually reach a showdown.
  const deadline = t0 + PLAY_MS;
  let actions = 0, folds = 0, rejoins = 0;
  console.log(`  .. playing hands for ${PLAY_MS / 1000}s with ${seated.length} real players (${perTable}/table)`);
  while (Date.now() < deadline) {
    await pool(seated, 8, async (s, i) => {
      const st = await req('GET', '/api/teenpatti/state', { token: s.p.token, query: { room_id: s.room } });
      const d = st.data || {};
      // A room empties every seat when a hand finishes, real players included, and then refills with
      // NPCs. Joining once therefore buys a single hand and the table spends the rest of the window
      // dealing NPC-only hands the rig engine correctly ignores. Re-seat whenever we have been
      // dropped — this is what the real client does by keeping the page open.
      if (d.my_seat === null || d.my_seat === undefined) {
        await req('POST', '/api/teenpatti/join', { token: s.p.token, json: { room_id: s.room } });
        rejoins++;
        return;
      }
      if (d.status !== 'playing') return;
      if (d.turn_seat !== d.my_seat) return;
      // Fold roughly one turn in six so pots resolve instead of raising for ever.
      const act = (actions + i) % 6 === 5 ? 'fold' : 'chaal';
      const r = await req('POST', '/api/teenpatti/action', { token: s.p.token, json: { room_id: s.room, action: act } });
      if (r.status === 200 && !(r.data && r.data.error)) { actions++; if (act === 'fold') folds++; }
    });
    await sleep(900);
  }
  console.log(`  .. ${actions} turns played (${folds} folds, ${rejoins} re-seats after being dropped)`);
  const t1 = Date.now();
  await sleep(5000);

  const audit = await req('GET', '/api/admin/rig-audit', { token: admin, query: { game: 'teenpatti', window_ms: (t1 - t0) + 5000 } });
  const tp = (audit.data && audit.data.games && audit.data.games.teenpatti) || null;
  const money = await tpMoney(players, t0, Date.now());

  await pool(seated, 6, async s => req('POST', '/api/teenpatti/leave', { token: s.p.token, json: { room_id: s.room } }));

  console.log(`  frequency: ${tp ? `${tp.decisions} eligible hands, ${tp.rigged} rigged (${tp.observed_pct}% vs ${tp.configured_pct}% configured), ${tp.skipped} NPC-only hands skipped` : 'no audit entries'}`);
  console.log(`  money:     wagered ${money.wagered}, paid to players ${money.won}, house profit ${money.profit}, hold ${money.hold_pct}%`);
  return { label, perTable, tp, money };
}

async function main() {
  console.log(`bet1x Teen Patti rig verification -> ${BASE}\npct: ${PCT}%   play: ${PLAY_MS / 1000}s per scenario   run id: ${stamp}\n`);

  const login = await req('POST', '/api/admin.php', { json: { action: 'login', username: 'admin', password: ADMIN_PASSWORD } });
  const admin = login.data && login.data.token;
  check('admin signed in', !!admin);
  if (!admin) process.exit(1);

  const roomsRes = await req('GET', '/api/teenpatti/rooms', { token: admin });
  const list = Array.isArray(roomsRes.data) ? roomsRes.data : (roomsRes.data.rooms || []);
  const roomIds = list.map(r => r.id || r).filter(Boolean);
  check('room list served', roomIds.length >= 6, { rooms: roomIds.length });

  // 24 accounts = 4 per table across 6 tables, which is exactly a full house of real players.
  const names = Array.from({ length: 24 }, (_, i) => `tp${stamp}p${String(i).padStart(2, '0')}`);
  const created = await pool(names, 8, async n =>
    req('POST', '/api/auth/signup', { json: { username: n, password: 'TeenPatti!2024', email: `${n}@example.com` } }));
  const players = [];
  created.forEach((r, i) => { if (r.data && r.data.token) players.push({ name: names[i], token: r.data.token }); });
  check('24 accounts created', players.length === 24, { created: players.length });
  await pool(players, 8, async p => req('POST', '/api/wallet/adjust', { token: admin, json: { username: p.name, delta: 30000, reason: 'tp rig test' } }));

  // Only Teen Patti's engine should be running, at a percentage that makes every hand rigged.
  await req('POST', '/api/game_sync.php', { token: admin, query: { action: 'admin_set_bot_takeover' }, form: { game: 'global', enabled: 'false', profit_pct: '90' } });
  const on = await req('POST', '/api/game_sync.php', { token: admin, query: { action: 'admin_set_bot_takeover' }, form: { game: 'teenpatti', enabled: 'true', profit_pct: String(PCT) } });
  check(`teen patti armed at ${PCT}%`, on.status === 200 && on.data.config.profit_pct === PCT, on.data && on.data.config);

  // A) one real player per table — three NPC seats remain for the rig to use.
  const sparse = await runScenario('A. SPARSE: 1 real player per table (NPCs available)', players.slice(0, 6), 1, admin, roomIds);
  await sleep(8000);
  // B) four real players per table — no NPC seat exists for the rig to hand the pot to.
  const packed = await runScenario('B. PACKED: 4 real players per table (no NPC seats)', players, 4, admin, roomIds);

  await req('POST', '/api/game_sync.php', { token: admin, query: { action: 'admin_set_bot_takeover' }, form: { game: 'global', enabled: 'false', profit_pct: '90' } });

  // ------------------------------------------------------------------------------------------
  section('verdict');
  [sparse, packed].forEach(s => {
    if (s.tp && s.tp.decisions >= 10) {
      check(`${s.label.split(':')[0]}: observed ${s.tp.observed_pct}% tracks configured ${PCT}% (n=${s.tp.decisions})`,
        Math.abs(s.tp.observed_pct - PCT) <= 15, { observed: s.tp.observed_pct, configured: PCT, n: s.tp.decisions });
    } else {
      note(`${s.label.split(':')[0]}: only ${s.tp ? s.tp.decisions : 0} eligible hands — cannot assert the ratio`);
    }
  });

  console.log(`\n  Hold at the same ${PCT}% setting, by table composition:`);
  console.log(`    NPCs available (1 real/table) -> ${sparse.money.hold_pct}%  (wagered ${sparse.money.wagered}, house profit ${sparse.money.profit})`);
  console.log(`    all real players (4/table)    -> ${packed.money.hold_pct}%  (wagered ${packed.money.wagered}, house profit ${packed.money.profit})`);

  if (sparse.money.wagered > 0 && packed.money.wagered > 0) {
    check('a rigged hand makes the house money regardless of whether an NPC is seated',
      packed.money.hold_pct >= sparse.money.hold_pct - 25,
      { sparse_hold: sparse.money.hold_pct, packed_hold: packed.money.hold_pct,
        why: 'a large drop means the rig handed the pot to a real player because no NPC seat existed' });
  } else {
    note('not enough Teen Patti money moved to compare the two scenarios',
      { sparse: sparse.money.wagered, packed: packed.money.wagered });
  }

  console.log('\n=================================================');
  console.log(`  ${pass} passed, ${failures.length} failed`);
  console.log('=================================================');
  if (failures.length) { console.log('\nFAILURES:'); failures.forEach(f => console.log(`  - ${f.n}\n      ${JSON.stringify(f.d)}`)); }
  if (notes.length) { console.log('\nNOTES:'); notes.forEach(n => console.log(`  - ${n.m}`)); }
  process.exit(failures.length ? 1 : 0);
}

main().catch(e => { console.error('HARNESS CRASH:', e); process.exit(2); });
