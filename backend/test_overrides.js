/**
 * bet1x manual override verification — focused, and careful about timing.
 *
 *   node backend/test_overrides.js
 *
 * The two manual controls in the admin panel ("force this colour number", "force this crash point")
 * are easy to test wrongly, and both traps are worth naming because they also mislead an operator
 * watching the panel:
 *
 *   - Colour history is appended with push(), so the NEWEST settled round is the LAST element of
 *     `history`, not the first. Reading history[0] shows a round from up to twenty rounds ago.
 *   - Both overrides are sticky: once set, they fix every round from then on — including a round
 *     already in flight when the aviator override is saved — until explicitly cleared. Neither is
 *     consumed after a single round; a test (or an operator) that only checks the very next round
 *     can't tell "it worked once" from "it's actually staying fixed".
 *
 * This script waits for a clean boundary before setting either override, then watches two
 * consecutive resolutions plus a post-clear round, so it can distinguish "the override did not
 * work" from "it worked but silently reverted" from "it worked and stays fixed until cleared".
 */

const BASE = process.env.TARGET || 'http://localhost:5000';
const ADMIN_PASSWORD = process.env.ADMIN_PW || 'abcd';
const stamp = Date.now().toString(36);

let pass = 0;
const failures = [];
const notes = [];
const check = (name, cond, detail) => {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { failures.push({ name, detail }); console.log(`  FAIL ${name}${detail !== undefined ? '  -> ' + JSON.stringify(detail).slice(0, 300) : ''}`); }
};
const note = (m, d) => { notes.push({ m, d }); console.log(`  !!   ${m}${d !== undefined ? '  -> ' + JSON.stringify(d).slice(0, 250) : ''}`); };
const section = t => console.log(`\n=== ${t} ===`);
const sleep = ms => new Promise(r => setTimeout(r, ms));

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

const setBot = (t, game, enabled, p) => req('POST', '/api/game_sync.php', { token: t, query: { action: 'admin_set_bot_takeover' }, form: { game, enabled: enabled ? 'true' : 'false', profit_pct: String(p) } });
const setOverride = (t, form) => req('POST', '/api/game_sync.php', { token: t, query: { action: 'admin_set_override' }, form });

// Newest settled round for a colour room. history is push()ed, so the newest entry is the LAST one.
async function newestColourRound(token, room) {
  const st = await req('GET', '/api/game_sync.php', { token, query: { action: 'color_get_state', room } });
  const h = (st.data && st.data.history) || [];
  return h.length ? h[h.length - 1] : null;
}

async function main() {
  console.log(`bet1x manual override verification -> ${BASE}\nrun id: ${stamp}\n`);

  const login = await req('POST', '/api/admin.php', { json: { action: 'login', username: 'admin', password: ADMIN_PASSWORD } });
  const admin = login.data && login.data.token;
  check('admin signed in', !!admin);
  if (!admin) process.exit(1);

  const name = `ov${stamp}`;
  const su = await req('POST', '/api/auth/signup', { json: { username: name, password: 'Override!2024', email: `${name}@example.com` } });
  const token = su.data && su.data.token;
  check('test player created', !!token, su.data);
  if (!token) process.exit(1);
  await req('POST', '/api/wallet/adjust', { token: admin, json: { username: name, delta: 5000, reason: 'override test' } });

  // The percentage engine must be off, or its own rigging is indistinguishable from the override.
  await setBot(admin, 'global', false, 90);
  const off = await req('GET', '/api/bot_status/color_guess', { token: admin });
  check('house percentage engine is off for this test', off.data.active === false, off.data);

  // ----------------------------------------------------------------------------------------
  section('colour: force a number in Sapre');
  const FIXED = 7;

  // Wait for a round boundary first, so the override is set at the START of a round rather than
  // part-way through one it cannot influence.
  let base = await newestColourRound(token, 'sapre');
  const waitStart = Date.now();
  while (Date.now() - waitStart < 40000) {
    const cur = await newestColourRound(token, 'sapre');
    if (cur && (!base || cur.roundNumber !== base.roundNumber)) { base = cur; break; }
    await sleep(1500);
  }
  await setOverride(admin, { game: 'color_guess', room: 'sapre', color: '', number: String(FIXED), size: '', rig_type: '' });
  console.log(`  .. override set just after round ${base && base.roundNumber}; watching the next two rounds`);

  const seen = [];
  let last = base ? base.roundNumber : null;
  const dl = Date.now() + 150000;
  while (Date.now() < dl && seen.length < 2) {
    const cur = await newestColourRound(token, 'sapre');
    if (cur && cur.roundNumber !== last) { seen.push(cur); last = cur.roundNumber; }
    await sleep(1500);
  }

  if (seen.length >= 1) {
    check(`the override fixed the next Sapre round to #${FIXED}`, seen[0].number === FIXED,
      { round: seen[0].roundNumber, got: seen[0].number, why: seen[0].rig_desc });
  } else { note('no Sapre round settled in the window'); }

  if (seen.length >= 2) {
    const sticky = seen[1].number === FIXED;
    check('a colour override is NOT consumed — it fixes every later round until cleared', sticky,
      { round: seen[1].roundNumber, got: seen[1].number, why: seen[1].rig_desc });
    if (sticky) note('CONFIRMED: colour override persists across rounds until explicitly cleared — same sticky behaviour as the aviator override below.');
  } else { note('only one Sapre round settled; stickiness not measured'); }

  await setOverride(admin, { game: 'color_guess', room: 'sapre', color: '', number: '', size: '', rig_type: '' });
  const afterClear = [];
  let l2 = seen.length ? seen[seen.length - 1].roundNumber : last;
  const dl2 = Date.now() + 80000;
  while (Date.now() < dl2 && afterClear.length < 1) {
    const cur = await newestColourRound(token, 'sapre');
    if (cur && cur.roundNumber !== l2) { afterClear.push(cur); l2 = cur.roundNumber; }
    await sleep(1500);
  }
  if (afterClear.length) {
    check('clearing the override releases the room', afterClear[0].is_rigged === false,
      { round: afterClear[0].roundNumber, number: afterClear[0].number, rigged: afterClear[0].is_rigged, why: afterClear[0].rig_desc });
  } else { note('no round settled after clearing; release not measured'); }

  // ----------------------------------------------------------------------------------------
  section('aviator: force the next crash point');
  const CRASH = 1.37;

  // Set the override during a betting window, so the very next take-off is the one it applies to.
  let phase = null;
  const aw = Date.now() + 60000;
  while (Date.now() < aw) {
    const s = await req('GET', '/api/game_sync.php', { token, query: { action: 'aviator_get_state' } });
    phase = s.data && s.data.phase;
    if (phase === 'waiting') break;
    await sleep(400);
  }
  check('reached an aviator betting window before setting the override', phase === 'waiting', { phase });
  await setOverride(admin, { game: 'aviator', crash_point: String(CRASH) });

  const crashes = [];
  let prev = phase;
  const adl = Date.now() + 150000;
  while (Date.now() < adl && crashes.length < 2) {
    const s = await req('GET', '/api/game_sync.php', { token, query: { action: 'aviator_get_state' } });
    const ph = s.data && s.data.phase;
    if (ph === 'crashed' && prev !== 'crashed') crashes.push(parseFloat(s.data.crash_point));
    prev = ph;
    await sleep(300);
  }

  if (crashes.length >= 1) {
    check(`the override fixed the next crash point to ${CRASH}x`, Math.abs(crashes[0] - CRASH) < 0.02,
      { got: crashes[0], want: CRASH });
  } else { note('no aviator crash observed'); }
  if (crashes.length >= 2) {
    // Sticky, not one-shot: the whole point of a "fix the multiplier" control is that it stays fixed
    // until an operator turns it off, exactly like the colour override above — a round after it that
    // reverted to random would mean the admin's setting silently stopped applying after one flight.
    check('an aviator override is NOT consumed — it fixes every later round until cleared',
      Math.abs(crashes[1] - CRASH) < 0.02, { round2: crashes[1], override: CRASH });
  } else { note('only one aviator crash observed; stickiness not measured'); }

  await setOverride(admin, { game: 'aviator', crash_point: '' });
  const afterAviatorClear = [];
  const adl2 = Date.now() + 100000;
  let prevA = 'crashed';
  while (Date.now() < adl2 && afterAviatorClear.length < 2) {
    const s = await req('GET', '/api/game_sync.php', { token, query: { action: 'aviator_get_state' } });
    const ph = s.data && s.data.phase;
    if (ph === 'crashed' && prevA !== 'crashed') afterAviatorClear.push(parseFloat(s.data.crash_point));
    prevA = ph;
    await sleep(300);
  }
  if (afterAviatorClear.length >= 2) {
    check('clearing the aviator override releases future rounds back to fair random',
      afterAviatorClear.some(c => Math.abs(c - CRASH) > 0.02), { seen: afterAviatorClear, override: CRASH });
  } else { note('fewer than two rounds settled after clearing; release not measured'); }

  console.log('\n=================================================');
  console.log(`  ${pass} passed, ${failures.length} failed`);
  console.log('=================================================');
  if (failures.length) { console.log('\nFAILURES:'); failures.forEach(f => console.log(`  - ${f.name}\n      ${JSON.stringify(f.detail)}`)); }
  if (notes.length) { console.log('\nNOTES:'); notes.forEach(n => console.log(`  - ${n.m}`)); }
  process.exit(failures.length ? 1 : 0);
}

main().catch(e => { console.error('HARNESS CRASH:', e); process.exit(2); });
