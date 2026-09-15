/**
 * Feed health monitor.
 *
 * A silent stall is the failure mode most likely to actually happen in production and the easiest
 * one to miss: the process stays up, every endpoint answers, and the match simply stops advancing.
 * If a covered match is known to be live and no event has arrived for the configured window
 * (default 90s), that is treated as a fault.
 *
 * ---------------------------------------------------------------------------------------------
 * ALERTING IS DEFERRED — LOGGING ONLY (client instruction, 2026-08-23)
 *
 * A stall is detected and logged at error level, and exposed on the admin health endpoint. It is
 * NOT wired to Slack, SMS, email or push. The practical consequence, stated plainly because it is
 * easy to forget: **a stalled feed is currently visible only to somebody reading the process logs
 * or refreshing the admin endpoint.** Nobody is paged. Wire a channel before a real match with real
 * money on it depends on this. `onStall` below is the single hook a channel plugs into — that is
 * the whole change when the time comes.
 * ---------------------------------------------------------------------------------------------
 */

const config = require('../../config');
const context = require('./context');

const CHECK_INTERVAL_MS = 15000;

/** fixtureKey -> { lastEventAt, stalled, stalledSince, notifiedAt } */
const tracked = new Map();

let timer = null;
let stallHook = null;

/**
 * Register a callback fired once each time a fixture transitions into the stalled state.
 *
 * This is the seam an alert channel attaches to later. Nothing is registered today, by instruction.
 */
function onStall(fn) {
  stallHook = typeof fn === 'function' ? fn : null;
}

/** Begin watching a fixture. Called when a match goes live. */
function track(fixtureKey) {
  if (!tracked.has(fixtureKey)) {
    tracked.set(fixtureKey, { lastEventAt: Date.now(), stalled: false, stalledSince: null, events: 0 });
  }
  ensureTimer();
}

/** Stop watching — match completed or abandoned. */
function untrack(fixtureKey) {
  tracked.delete(fixtureKey);
}

/**
 * Record that an event arrived. Clears a stall and logs the recovery, because "the feed came back"
 * is exactly as important to see in a log as "the feed stopped".
 */
function recordEvent(fixtureKey) {
  const { logger } = context.get();
  const entry = tracked.get(fixtureKey) || { lastEventAt: 0, stalled: false, stalledSince: null, events: 0 };

  if (entry.stalled) {
    const downMs = Date.now() - entry.stalledSince;
    logger.info('cricket: feed recovered', { fixture_key: fixtureKey, down_ms: downMs });
    entry.stalled = false;
    entry.stalledSince = null;
  }
  entry.lastEventAt = Date.now();
  entry.events += 1;
  tracked.set(fixtureKey, entry);
  ensureTimer();
}

/**
 * One sweep. Exposed so the test suite can drive it directly with an injected clock rather than
 * waiting 90 real seconds.
 */
function check(now = Date.now()) {
  const { logger } = context.get();
  const stalls = [];

  for (const [fixtureKey, entry] of tracked) {
    const silentFor = now - entry.lastEventAt;
    if (silentFor < config.CRICKET_STALL_MS || entry.stalled) continue;

    entry.stalled = true;
    entry.stalledSince = now;
    tracked.set(fixtureKey, entry);

    // Error level: this is a fault, not a curiosity. Until an alert channel exists this line is
    // the only notification that happens anywhere.
    logger.error('cricket: FEED STALLED - no ball event received', {
      fixture_key: fixtureKey,
      silent_ms: silentFor,
      threshold_ms: config.CRICKET_STALL_MS,
      events_received: entry.events,
      note: 'alerting not yet wired - logging only'
    });

    stalls.push({ fixture_key: fixtureKey, silent_ms: silentFor });
    if (stallHook) {
      try {
        stallHook({ fixtureKey, silentMs: silentFor });
      } catch (e) {
        logger.error('cricket: stall hook threw', { message: e.message });
      }
    }
  }
  return stalls;
}

function ensureTimer() {
  if (timer || tracked.size === 0) return;
  timer = setInterval(() => {
    if (tracked.size === 0) {
      clearInterval(timer);
      timer = null;
      return;
    }
    try {
      check();
    } catch (e) {
      // The monitor must never take the process down; a throw here would kill the interval.
      try { context.get().logger.error('cricket: health check threw', { message: e.message }); } catch (_) { /* pre-init */ }
    }
  }, CHECK_INTERVAL_MS);
  if (typeof timer.unref === 'function') timer.unref();
}

/** Current health of every tracked fixture. Backs the admin endpoint. */
function report(now = Date.now()) {
  const fixtures = [];
  for (const [fixtureKey, entry] of tracked) {
    fixtures.push({
      fixture_key: fixtureKey,
      last_event_at: entry.lastEventAt,
      silent_ms: now - entry.lastEventAt,
      events_received: entry.events,
      stalled: entry.stalled,
      stalled_since: entry.stalledSince
    });
  }
  return {
    threshold_ms: config.CRICKET_STALL_MS,
    alerting: 'log-only (no channel wired)',
    tracked: fixtures.length,
    stalled: fixtures.filter(f => f.stalled).length,
    fixtures
  };
}

/** Test seam. */
function reset() {
  tracked.clear();
  stallHook = null;
  if (timer) {
    clearInterval(timer);
    timer = null;
  }
}

module.exports = { track, untrack, recordEvent, check, report, onStall, reset, CHECK_INTERVAL_MS, _tracked: tracked };
