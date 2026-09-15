/**
 * The single point at which provider data enters the system.
 *
 * One receiver for the Match Via Push feed, for both games. Neither game's frontend — and no
 * browser anywhere — ever contacts Roanuz, so the provider bill is a function of matches covered,
 * never of how many people are watching.
 *
 * Ingest order matters and is deliberate:
 *   1. verify the signature      — an unsigned write lands in a permanent log that decides payouts
 *   2. append to the event log   — durable first, because everything downstream can be recomputed
 *   3. mark the feed healthy     — clears any stall
 *   4. update derived state      — full recompute from the log, never an increment
 *   5. fan out to subscribers    — best-effort; a failure here must not fail the delivery
 *
 * If step 5 throws, the event is already safely stored and the next recompute repairs the view. If
 * step 2 throws, we return non-2xx so the provider redelivers — which is safe precisely because
 * the log deduplicates on the provider's own event id.
 */

const crypto = require('crypto');
const zlib = require('zlib');
const config = require('../../config');
const context = require('./context');
const eventLog = require('./event-log');
const liveState = require('./live-state');
const fanout = require('./fanout');
const health = require('./health');
const contests = require('./contests');
const houseEntry = require('./house-entry');
const boundary = require('./boundary');
const normalize = require('./normalize');
const roanuz = require('./roanuz');
const snapshotAdapter = require('./snapshot-adapter');

/**
 * Verify that a push delivery actually came from Roanuz.
 *
 * UNCONFIRMED, 2026-08-24: Roanuz's own public docs (see docs/CRICKET-BUILD-BRIEF.md) document no
 * signature/secret header on webhook deliveries at all — the only credential they describe is
 * `rs-token`, which is for calls we make TO them, not from them. Two mechanisms are supported here
 * rather than one, because which (if either) Roanuz actually honours is exactly the kind of thing
 * that needs confirming once real account access exists, not guessing now:
 *
 *   1. An HMAC-SHA256 signature header — kept for forward-compatibility in case Roanuz turns out to
 *      support one after all, or a future provider does.
 *   2. A secret baked into the registered webhook URL itself, as `?secret=...` or an
 *      `x-roanuz-secret` header. This is the one confirmed to work with what the docs DO show:
 *      Roanuz's console lets you paste an arbitrary URL to receive deliveries at, and a URL only
 *      you and Roanuz know is a real (if unglamorous) verification mechanism on its own.
 *
 * EITHER matching is sufficient. Returns `{ ok, reason }`. In development with no secret
 * configured, verification is skipped and the caller is told — config.js already refuses to boot
 * production in that state.
 */
function verifySignature(rawBody, signature, { secretParam = null } = {}) {
  const secret = config.ROANUZ_WEBHOOK_SECRET;

  if (!secret) {
    if (config.IS_PRODUCTION) return { ok: false, reason: 'no_secret_configured' };
    return { ok: true, reason: 'dev_no_secret' };
  }

  if (secretParam && typeof secretParam === 'string') {
    const a = Buffer.from(secret, 'utf8');
    const b = Buffer.from(secretParam, 'utf8');
    if (a.length === b.length && crypto.timingSafeEqual(a, b)) return { ok: true, reason: 'verified_url_secret' };
  }

  if (signature && typeof signature === 'string') {
    const buf = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(String(rawBody || ''), 'utf8');
    const expected = crypto.createHmac('sha256', secret).update(buf).digest('hex');
    // Strip any "sha256=" prefix the provider may use.
    const provided = signature.includes('=') ? signature.split('=').pop().trim() : signature.trim();
    const a = Buffer.from(expected, 'utf8');
    const b = Buffer.from(provided, 'utf8');
    if (a.length === b.length && crypto.timingSafeEqual(a, b)) return { ok: true, reason: 'verified_hmac' };
  }

  return { ok: false, reason: signature || secretParam ? 'signature_mismatch' : 'missing_signature' };
}

/**
 * Parse the raw body into an object without assuming a JSON middleware already ran.
 *
 * Confirmed 2026-08-24 against Roanuz's own webhook-handler example (see
 * docs/CRICKET-BUILD-BRIEF.md): their sample decompresses the request body with gzip before
 * parsing it as JSON, so a real delivery would otherwise fail here on every single call. Detected
 * by the gzip magic bytes rather than assumed, so a body that happens to arrive uncompressed (the
 * test suites' synthetic payloads, or a future change on Roanuz's side) still parses correctly.
 */
function parseBody(rawBody) {
  if (rawBody && typeof rawBody === 'object' && !Buffer.isBuffer(rawBody)) return rawBody;

  let buf = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(String(rawBody || ''), 'utf8');

  if (buf.length >= 2 && buf[0] === 0x1f && buf[1] === 0x8b) {
    try {
      buf = zlib.gunzipSync(buf);
    } catch (e) {
      return null;
    }
  }

  try {
    return JSON.parse(buf.toString('utf8'));
  } catch (e) {
    return null;
  }
}

/**
 * A push delivery may carry one discrete event, a batch of them, or — per Roanuz's own docs, see
 * snapshot-adapter.js — the entire current match state. All three normalise to the same array of
 * discrete, normalize.js-shaped event objects here, so nothing downstream of this function needs to
 * know or care which shape actually arrived.
 */
function extractEvents(body) {
  if (!body || typeof body !== 'object') return { events: [], fixtureKey: null };

  const fixtureKey = normalize._pick(body, normalize.FIELD_MAP.matchKey, null);

  if (!Array.isArray(body) && snapshotAdapter.looksLikeSnapshot(body)) {
    return { events: snapshotAdapter.extractEventsFromSnapshot(body, { fixtureKey }), fixtureKey };
  }

  const candidates = ['events', 'balls', 'data', 'items', 'payload'];
  for (const key of candidates) {
    if (Array.isArray(body[key])) return { events: body[key], fixtureKey };
  }
  if (Array.isArray(body)) return { events: body, fixtureKey: null };
  return { events: [body], fixtureKey };
}

/**
 * Reflect an event onto the fixture row: lock state, status transitions, and the stall monitor's
 * clock. Failures here are logged but never propagate — the event itself is already durable, and
 * losing the delivery over a bookkeeping update would be a strictly worse outcome.
 */
async function updateFixtureFromEvent(fixtureKey, eventType) {
  const { prisma, logger } = context.get();
  const now = new Date();
  const data = { last_event_at: now };

  if (eventType === 'toss') {
    data.toss_at = now;
    data.status = 'toss';
  } else if (eventType === 'lineup') {
    // The confirmed XI is what locks teams — not a timer, not the squad announcement.
    data.lineups_confirmed_at = now;
  } else if (eventType === 'ball' || eventType === 'ball_start' || eventType === 'innings_break') {
    data.status = 'live';
  } else if (eventType === 'match_end') {
    data.status = 'completed';
    data.completed_at = now;
  } else if (eventType === 'match_abandoned') {
    data.status = 'abandoned';
    data.completed_at = now;
  }

  try {
    await prisma.cricketFixture.update({ where: { key: fixtureKey }, data });
  } catch (e) {
    logger.warn('cricket: could not update fixture from event', { fixture_key: fixtureKey, message: e.message });
  }
}

/**
 * Drive the Your 11 contest lifecycle off the feed.
 *
 * Both transitions are event-gated, never time-gated, which is the whole point:
 *
 *   - Teams lock on the confirmed-XI event at the toss. A clock-based lock would either shut users
 *     out before the line-ups are known or let them pick after seeing them.
 *   - Settlement runs on match end. It is idempotent, so a redelivered match_end pays nothing extra.
 *
 * Deliberately outside the durability path and deliberately swallowing its own errors: the events
 * are already permanently stored by this point, so a contest that fails to settle here can be
 * settled again from the operator endpoint, from exactly the same log, with the same result. Letting
 * this throw would instead answer the webhook 500 and make the provider redeliver balls that are
 * already safely written.
 */
async function advanceContests(fixtureKey, types, state) {
  const { logger } = context.get();
  try {
    if (types.has('lineup')) {
      // Stamp the confirmed XI onto the squad table first — lockContestsForFixture reads it from
      // there (not from `state`) so the same auto-substitution runs whether it's triggered by this
      // feed event or by the manual operator endpoint.
      if (state && Array.isArray(state.confirmed_xi) && state.confirmed_xi.length) {
        await roanuz.applyConfirmedXi(fixtureKey, state.confirmed_xi);
      }
      await contests.lockContestsForFixture(fixtureKey);
      // Only once the contests are locked is the final field size known, and the guardrails —
      // minimum entrants above all — are checked against that number, not against a partial field.
      await houseEntry.armFixture(fixtureKey);
    }

    // Re-solve the house lineups toward their current target. Runs before settlement so a match_end
    // delivery lands the entry on its finishing rank before anything is paid out.
    if (state) await houseEntry.syncFixture(fixtureKey, state);

    if (types.has('match_end') || types.has('match_abandoned')) {
      const result = await contests.settleFixture(fixtureKey);
      if (result.needs_review > 0) {
        logger.error('cricket: settlement needs human review', {
          fixture_key: fixtureKey, contests: result.needs_review, review: result.review
        });
      }
    }
  } catch (e) {
    logger.error('cricket: contest lifecycle step failed - events are stored, retry from the operator endpoint', {
      fixture_key: fixtureKey, message: e.message
    });
  }
}

/**
 * Ingest one raw delivery.
 *
 * Returns a summary rather than throwing for ordinary outcomes, so the route can answer 200 for a
 * duplicate (which is not an error) and non-2xx only when redelivery would actually help.
 */
async function ingest(rawBody, { signature = null, secretParam = null, skipSignature = false } = {}) {
  const { logger } = context.get();

  if (!skipSignature) {
    const verdict = verifySignature(rawBody, signature, { secretParam });
    if (!verdict.ok) {
      logger.warn('cricket: rejected push delivery', { reason: verdict.reason });
      return { ok: false, status: 401, reason: verdict.reason, stored: 0, duplicates: 0 };
    }
  }

  const body = parseBody(rawBody);
  if (!body) return { ok: false, status: 400, reason: 'unparseable_body', stored: 0, duplicates: 0 };

  const { events, fixtureKey: envelopeKey } = extractEvents(body);
  if (!events.length) return { ok: true, status: 200, reason: 'empty', stored: 0, duplicates: 0 };

  let stored = 0;
  let duplicates = 0;

  // Event types are tracked PER FIXTURE, not for the batch as a whole. A single delivery can carry
  // events for more than one match, and a flat set would apply one match's `match_end` to the
  // other — marking a match that is still being played as completed, which would lock its contests
  // and trigger settlement mid-innings.
  const touched = new Map(); // fixtureKey -> Set of event types
  // The stored rows themselves, per fixture. Boundary Baazi settles the specific deliveries that
  // arrived in THIS batch, so it needs the events, not just their types.
  const arrivals = new Map(); // fixtureKey -> [stored event row]

  for (const raw of events) {
    const result = await eventLog.append(raw, { fixtureKey: envelopeKey });
    if (result.stored) {
      stored += 1;
      const key = result.event.fixture_key;
      if (!touched.has(key)) touched.set(key, new Set());
      touched.get(key).add(result.event.event_type);
      if (!arrivals.has(key)) arrivals.set(key, []);
      arrivals.get(key).push(result.event);
    } else if (result.duplicate) {
      duplicates += 1;
    } else if (result.error) {
      // Neither stored nor a duplicate: a real problem worth a redelivery attempt.
      return { ok: false, status: 422, reason: result.error, stored, duplicates };
    }
  }

  // Everything below is derived and repairable. It is deliberately outside the durability path.
  for (const [key, types] of touched) {
    health.recordEvent(key);
    for (const type of types) await updateFixtureFromEvent(key, type);

    let state = null;
    try {
      const rows = await eventLog.readEvents(key);
      state = liveState.buildLiveState(rows, { fixtureKey: key });
      fanout.publish(key, 'state', state);

      if (state.match_ended) health.untrack(key);
      else health.track(key);
    } catch (e) {
      logger.error('cricket: derived-state update failed after a durable write', {
        fixture_key: key, message: e.message
      });
      // Intentionally not rethrown: the events are stored, so the next delivery repairs the view.
    }

    // After the state exists, never before: the lock decides contests on the confirmed XI, and
    // settlement scores every entry from this same recomputed log.
    await advanceContests(key, types, state);
    await boundary.advance(key, types, state, arrivals.get(key) || []);
  }

  return { ok: true, status: 200, reason: 'ingested', stored, duplicates, fixtures: Array.from(touched.keys()) };
}

module.exports = { ingest, verifySignature, extractEvents, parseBody };
