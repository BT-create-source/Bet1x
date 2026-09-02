/**
 * Server-Sent Events fan-out for live cricket state.
 *
 * The audit (docs/PHASE0-AUDIT.md §5) found no real-time layer anywhere in this codebase — every
 * "live" surface is a client-side setInterval, some as tight as 250ms. Ball-by-ball prediction on
 * a polling loop would be both a poor experience and a load problem at marquee-match concurrency,
 * so the pipeline brings its own transport.
 *
 * SSE rather than WebSocket, deliberately: the data flow is strictly server -> client, it needs no
 * new dependency (plain `res.write` on Express), it survives the existing reverse-proxy setup, and
 * a client that cannot hold the connection degrades to the polling the rest of the site already
 * does. Nothing here changes any existing page.
 *
 * This is the ONLY path by which cricket data reaches a browser. No client ever talks to Roanuz,
 * so provider cost stays flat whether ten people are watching or a hundred thousand.
 */

const context = require('./context');

/** fixtureKey -> Set of subscriber records. */
const channels = new Map();

/** Keeps proxies from closing an idle connection, and detects dead sockets. */
const HEARTBEAT_MS = 25000;
let heartbeatTimer = null;

function channelFor(fixtureKey) {
  if (!channels.has(fixtureKey)) channels.set(fixtureKey, new Set());
  return channels.get(fixtureKey);
}

function writeFrame(res, event, data) {
  try {
    res.write(`event: ${event}\n`);
    res.write(`data: ${JSON.stringify(data)}\n\n`);
    return true;
  } catch (e) {
    return false; // socket already gone; the close handler will clean up
  }
}

/**
 * Attach an Express response as a live subscriber.
 *
 * Returns an unsubscribe function. The caller must NOT end the response — this owns it until the
 * client disconnects.
 */
function subscribe(fixtureKey, req, res, { initialState = null } = {}) {
  const { logger } = context.get();

  res.writeHead(200, {
    'Content-Type': 'text/event-stream',
    // `no-transform` is load-bearing, not decoration: this app mounts compression() globally, and a
    // compressed stream buffers until the encoder flushes — which for a trickle of small frames can
    // be many seconds. compression() explicitly skips any response marked no-transform.
    'Cache-Control': 'no-cache, no-store, must-revalidate, no-transform',
    Connection: 'keep-alive',
    // Nginx buffers proxied responses by default, which would hold every ball back until the
    // buffer filled. This is the documented opt-out.
    'X-Accel-Buffering': 'no'
  });
  if (typeof res.flushHeaders === 'function') res.flushHeaders();

  const record = { res, fixtureKey, since: Date.now() };
  channelFor(fixtureKey).add(record);

  writeFrame(res, 'ready', { fixture_key: fixtureKey, at: Date.now() });
  if (initialState) writeFrame(res, 'state', initialState);

  const cleanup = () => {
    const set = channels.get(fixtureKey);
    if (set) {
      set.delete(record);
      if (set.size === 0) channels.delete(fixtureKey);
    }
    try { res.end(); } catch (e) { /* already closed */ }
  };

  req.on('close', cleanup);
  req.on('error', cleanup);

  logger.debug('cricket: sse subscriber attached', { fixture_key: fixtureKey, subscribers: channelFor(fixtureKey).size });
  ensureHeartbeat();
  return cleanup;
}

/**
 * Push a payload to every subscriber of a fixture.
 *
 * Dead sockets are dropped as they are discovered rather than accumulating — a browser that closed
 * without a clean disconnect otherwise stays in the set forever and every future ball tries to
 * write to it.
 */
function publish(fixtureKey, event, data) {
  const set = channels.get(fixtureKey);
  if (!set || set.size === 0) return 0;

  let delivered = 0;
  for (const record of Array.from(set)) {
    if (writeFrame(record.res, event, data)) delivered += 1;
    else set.delete(record);
  }
  if (set.size === 0) channels.delete(fixtureKey);
  return delivered;
}

function ensureHeartbeat() {
  if (heartbeatTimer) return;
  heartbeatTimer = setInterval(() => {
    if (channels.size === 0) {
      clearInterval(heartbeatTimer);
      heartbeatTimer = null;
      return;
    }
    for (const [fixtureKey, set] of channels) {
      for (const record of Array.from(set)) {
        // A bare comment line is a valid SSE keep-alive and is ignored by EventSource.
        try { record.res.write(': keepalive\n\n'); } catch (e) { set.delete(record); }
      }
      if (set.size === 0) channels.delete(fixtureKey);
    }
  }, HEARTBEAT_MS);
  if (typeof heartbeatTimer.unref === 'function') heartbeatTimer.unref();
}

/** Subscriber counts per fixture — surfaced to the admin console and used by the load test. */
function stats() {
  const out = { fixtures: 0, subscribers: 0, by_fixture: {} };
  for (const [fixtureKey, set] of channels) {
    out.fixtures += 1;
    out.subscribers += set.size;
    out.by_fixture[fixtureKey] = set.size;
  }
  return out;
}

/** Test seam: drop every subscriber and stop the heartbeat. */
function shutdown() {
  for (const set of channels.values()) {
    for (const record of set) {
      try { record.res.end(); } catch (e) { /* ignore */ }
    }
  }
  channels.clear();
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }
}

module.exports = { subscribe, publish, stats, shutdown, HEARTBEAT_MS };
