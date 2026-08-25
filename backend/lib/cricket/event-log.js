/**
 * The permanent, append-only ball event log.
 *
 * This is the single durable record of everything that happened in a match. Roanuz's Standard
 * licence keeps only 8 weeks of history, so nothing here may ever be re-fetched later — if it is
 * not written when it arrives, it is gone. Consequently:
 *
 *   - Rows are only ever INSERTed. Nothing in this module updates or deletes an event.
 *   - The **raw provider payload** is stored verbatim. Interpretation happens at read time, on
 *     every recompute, so a mistake in `normalize.js` can be corrected and replayed rather than
 *     having silently corrupted the record.
 *   - A duplicate delivery is a no-op, not a double-write, enforced by a unique index on the
 *     provider's own event id.
 *
 * There is deliberately no JSON-file fallback. `writeJsonTable` rewrites an entire file per write,
 * which is the wrong shape for a permanently growing log, and a flat file that diverges from the
 * database would be a second source of truth for money. Callers use `requireDatabase`.
 */

const crypto = require('crypto');
const context = require('./context');
const normalize = require('./normalize');

/**
 * Serialise a value with every object's keys in sorted order, at every depth.
 *
 * This has to be hand-rolled. The obvious shortcut —
 * `JSON.stringify(payload, Object.keys(payload).sort())` — looks like it sorts keys but an ARRAY
 * second argument is an allowlist replacer, and it filters keys at *every* level, not just the top.
 * Any nested field whose name is not also a top-level key is silently dropped, so
 *
 *   { match_key: 'm1', ball: { over: 1,  run: 0 } }
 *   { match_key: 'm1', ball: { over: 19, run: 6 } }
 *
 * both serialise to `{"ball":{},"match_key":"m1"}` and hash identically. Two completely different
 * deliveries would then collide on the unique index and the second would be discarded as a
 * duplicate — permanent, unrecoverable loss in the one log that cannot be re-fetched.
 */
function canonicalise(value) {
  if (value === null || typeof value !== 'object') return JSON.stringify(value) ?? 'null';
  if (Array.isArray(value)) return `[${value.map(canonicalise).join(',')}]`;
  const keys = Object.keys(value).sort();
  return `{${keys.map(k => `${JSON.stringify(k)}:${canonicalise(value[k])}`).join(',')}}`;
}

/**
 * A stable id for a payload that arrived without one.
 *
 * Roanuz is expected to supply an event id, but the dedupe guarantee must not depend on that: a
 * provider that omits it, or a replay harness, would otherwise write the same delivery twice on
 * every retry. Hashing the payload gives an id that is identical for identical content — and,
 * critically, different for different content — so a retried delivery still collides on the unique
 * index while a genuinely new one does not. It is prefixed so a derived id is always
 * distinguishable from a provider-issued one during debugging.
 */
function deriveEventId(fixtureKey, payload) {
  const digest = crypto.createHash('sha256')
    .update(`${fixtureKey || ''}|${canonicalise(payload)}`)
    .digest('hex');
  return `derived_${digest.slice(0, 32)}`;
}

/**
 * Append one raw provider payload.
 *
 * Returns `{ stored, duplicate, event }`. `stored:false, duplicate:true` is the normal, expected
 * outcome for a redelivery and is not an error — the push feed is at-least-once.
 */
async function append(rawPayload, { fixtureKey = null } = {}) {
  const { prisma, logger } = context.get();

  const normalized = normalize.normalizeEvent(rawPayload, { fixtureKey });
  const resolvedFixture = normalized.fixture_key || fixtureKey;

  if (!resolvedFixture) {
    // Without a match key the event cannot be attributed, and an unattributed row would be
    // invisible to every recompute. Say so loudly rather than storing an orphan.
    logger.error('cricket: event has no resolvable match key - refusing to store', {
      event_type: normalized.event_type
    });
    return { stored: false, duplicate: false, event: null, error: 'no_fixture_key' };
  }

  const eventId = normalized.event_id || deriveEventId(resolvedFixture, normalized.payload);

  try {
    const event = await prisma.cricketBallEvent.create({
      data: {
        event_id: String(eventId),
        fixture_key: resolvedFixture,
        event_type: normalized.event_type,
        innings: normalized.innings,
        over: normalized.over,
        ball: normalized.ball,
        sequence: normalized.sequence,
        payload: normalized.payload
      }
    });
    return { stored: true, duplicate: false, event };
  } catch (e) {
    // P2002 = unique constraint violation on event_id. That is the dedupe working as designed.
    if (e && e.code === 'P2002') {
      return { stored: false, duplicate: true, event: null };
    }
    if (e && e.code === 'P2003') {
      // Foreign key violation: an event arrived for a fixture that was never synced. Losing it is
      // not acceptable, so this is surfaced rather than swallowed.
      logger.error('cricket: event references an unknown fixture - not stored', {
        fixture_key: resolvedFixture, event_id: eventId
      });
      return { stored: false, duplicate: false, event: null, error: 'unknown_fixture' };
    }
    throw e;
  }
}

/**
 * Every event for a fixture, in the order they should be interpreted.
 *
 * Ordered by the provider's own sequencing (innings, over, ball, sequence) with the database id as
 * the final tiebreak, so a burst that arrives out of order still reads back in cricket order. The
 * live-state builder sorts defensively as well — this ordering is a convenience, never a guarantee
 * the consumer is allowed to rely on.
 */
async function readEvents(fixtureKey, { eventType = null } = {}) {
  const { prisma } = context.get();
  return prisma.cricketBallEvent.findMany({
    where: { fixture_key: fixtureKey, ...(eventType ? { event_type: eventType } : {}) },
    orderBy: [
      { innings: 'asc' },
      { over: 'asc' },
      { ball: 'asc' },
      { sequence: 'asc' },
      { id: 'asc' }
    ]
  });
}

/** How many events a fixture has. Used by the health monitor and the admin console. */
async function countEvents(fixtureKey) {
  const { prisma } = context.get();
  return prisma.cricketBallEvent.count({ where: { fixture_key: fixtureKey } });
}

/** The most recent event for a fixture, or null. */
async function lastEvent(fixtureKey) {
  const { prisma } = context.get();
  const rows = await prisma.cricketBallEvent.findMany({
    where: { fixture_key: fixtureKey },
    orderBy: { id: 'desc' },
    take: 1
  });
  return rows[0] || null;
}

module.exports = { append, readEvents, countEvents, lastEvent, deriveEventId, canonicalise };
