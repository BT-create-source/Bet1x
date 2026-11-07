'use strict';
// Exercise the actual browser clock code with a controlled monotonic clock and transport.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let now = 0;
let response;
const button = { disabled: false };
const timer = { textContent: '', style: {} };
const context = {
  window: {}, performance: { now: () => now }, AbortController,
  setTimeout, clearTimeout,
  document: {
    getElementById: id => id === 'ogPlay' ? button : id === 'ogClock' ? timer : null,
    querySelectorAll: () => [],
  },
  localStorage: { getItem: () => null },
  fetch: async () => response(),
};
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/originals.js'), 'utf8')
  .replace('window.Orig = {', 'window.clockTest = { bettingOpen, syncClock, renderClock }; window.Orig = {');
vm.runInNewContext(source, context);
const c = context.window.clockTest;
const state = context.window.Orig.state;
function reply(time, phase = 'betting') {
  return { ok: true, json: async () => ({ ok: true, clock: {
    round_id: 100, server_time: time, phase, betting_closes_at: 15000, next_round_at: 30000,
  } }) };
}
(async () => {
  c.renderClock();
  assert.equal(button.disabled, true, 'Disable until synchronized');
  response = () => reply(14000);
  await c.syncClock();
  assert.equal(button.disabled, false);
  now = 1000;
  c.renderClock();
  assert.equal(button.disabled, true, 'Close exactly at deadline without a poll');
  now = 17000;
  assert.equal(c.bettingOpen(), false, 'Never locally reopen next cycle');
  now = 0;
  response = () => { now += 2000; return reply(14000); };
  await c.syncClock();
  assert.equal(button.disabled, true, 'Slow transport cannot extend betting');
  response = () => reply(1000);
  await c.syncClock();
  state.busy = true;
  c.renderClock();
  assert.equal(button.disabled, true, 'Clock refresh cannot enable during a bet');
  state.busy = false;
  state.open = {};
  c.renderClock();
  assert.equal(button.disabled, true, 'Do not start another multi-step round');
  state.open = null;
  now += 7001;
  c.renderClock();
  assert.equal(button.disabled, true, 'Stale clock fails closed');
  response = () => { throw new Error('offline'); };
  await c.syncClock();
  assert.equal(button.disabled, true, 'Offline refresh stays closed');
  response = () => reply(2000);
  await c.syncClock();
  assert.equal(button.disabled, false, 'Recover when server synchronization returns');
  response = () => reply(18000, 'closed');
  await c.syncClock();
  assert.equal(button.disabled, true, 'Respect closed phase');
  console.log('PASS browser betting clock: deadlines, latency, stale data, recovery and locks');
})().catch(e => { console.error(e); process.exitCode = 1; });
