/**
 * Roanuz client — the FREE tournament-level endpoints only, plus the one paid cold-start call and
 * the push-feed subscription management call.
 *
 * ---------------------------------------------------------------------------------------------
 * SPEND GUARDRAIL. The only paid product that may be active on this account is Match Via Push,
 * which arrives at the webhook and never goes through this file. Everything here is a free
 * per-tournament endpoint, or the subscribe call (also free — it configures delivery, it doesn't
 * pull data). Do not add a call to:
 *
 *   - Fantasy Match Points   (Rs 350/match) — scoring is computed in-house from the event log
 *   - Fantasy Match Credits  (Rs 250/match) — credits are computed in-house
 *   - the paid Fixtures API  (Rs 2,000/mo)  — the free tournament fixtures endpoint covers it
 *
 * The single permitted exception is Tournament Player Stats (Rs 10/player), used ONCE per
 * tournament to cold-start the credit algorithm. It is isolated in `fetchPlayerStats` below and is
 * the only function in this file that costs money — it is never called by the sync loop.
 * ---------------------------------------------------------------------------------------------
 *
 * PARTIALLY VERIFIED, 2026-08-24: the auth endpoint, the `rs-token` header, and the general
 * `/tournament/.../` and `/match/.../subscribe/` URL shapes are all confirmed against Roanuz's own
 * public docs (see docs/CRICKET-BUILD-BRIEF.md). Exact field names inside each JSON response
 * remain unconfirmed — the docs site doesn't render literal response bodies to a crawler — so
 * `normalize._pick` with multiple aliases is still doing real work here, not defensive padding.
 *
 * Every network call goes through `transport` (roanuz-transport.js), never through `fetch`
 * directly, so the live/mock split lives in exactly one place. Node 24 is in use, so global
 * `fetch` is available inside that file; no HTTP dependency is added.
 */

const config = require('../../config');
const context = require('./context');
const normalize = require('./normalize');
const roanuzTransport = require('./roanuz-transport');

let activeTransport = roanuzTransport.resolveTransport(config);

/** Test/ops seam: swap the transport without touching config or restarting the process. */
function setTransport(t) {
  activeTransport = t;
}
function getTransport() {
  return activeTransport;
}

/** Whether real Roanuz credentials are present — independent of which transport is active. */
function isConfigured() {
  return Boolean(config.ROANUZ_API_TOKEN && config.ROANUZ_PROJECT_KEY);
}

/** Roanuz issues a short-lived token from the API key; it is cached until it expires. */
let cachedToken = null;
let cachedTokenExpiry = 0;

async function getAccessToken() {
  if (cachedToken && Date.now() < cachedTokenExpiry) return cachedToken;

  if (activeTransport.kind === 'live' && !isConfigured()) {
    throw new Error('roanuz: live transport selected but ROANUZ_API_TOKEN/ROANUZ_PROJECT_KEY are not set');
  }

  const res = await activeTransport.authenticate(config.ROANUZ_PROJECT_KEY, config.ROANUZ_API_TOKEN);
  if (!res.ok) {
    // The body carries the actual reason (e.g. a bad key vs. an account not yet entitled to call
    // this at all) and is worth more than the status code alone when this shows up in the logs.
    throw new Error(`roanuz auth failed: HTTP ${res.status}${res.error ? ` - ${res.error}` : ''}`);
  }

  const token = normalize._pick(res.data, ['data.token', 'token'], null);
  if (!token) throw new Error('roanuz auth returned no token');

  const expires = normalize._pick(res.data, ['data.expires', 'expires'], null);
  // Refresh an hour early rather than discovering expiry mid-match.
  cachedTokenExpiry = expires ? Number(expires) * 1000 - 3600000 : Date.now() + 20 * 3600 * 1000;
  cachedToken = token;
  return token;
}

/**
 * One authenticated call against the API surface, through whichever transport is active.
 *
 * Returns `{ ok, data, reason }` rather than throwing on a "not configured" state, so a scheduled
 * sync running before credentials arrive logs one clear line instead of a stack trace every cycle.
 * With the mock transport active this always "works" — that is the point: the sync loop, the
 * squad importer and everything downstream of them runs the exact same code path whether or not a
 * real Roanuz account is reachable yet.
 */
async function request(method, path, { body } = {}) {
  const { logger } = context.get();

  if (activeTransport.kind === 'live' && !isConfigured()) {
    return { ok: false, data: null, reason: 'not_configured' };
  }

  try {
    const token = await getAccessToken();
    const res = await activeTransport.call(method, config.ROANUZ_PROJECT_KEY, path, { token, body });
    if (!res.ok) {
      logger.warn('cricket: roanuz request failed', { path, status: res.status, transport: activeTransport.kind });
      return { ok: false, data: null, reason: `http_${res.status}` };
    }
    return { ok: true, data: res.data, reason: 'ok' };
  } catch (e) {
    logger.warn('cricket: roanuz request threw', { path, message: e.message, transport: activeTransport.kind });
    return { ok: false, data: null, reason: 'request_failed' };
  }
}

/** FREE — the match schedule for a tournament. */
async function fetchFixtures(tournamentKey) {
  return request('GET', `/tournament/${tournamentKey}/fixtures/`);
}

/** FREE — full squads for a tournament. */
async function fetchSquads(tournamentKey, teamKey) {
  return request('GET', `/tournament/${tournamentKey}/team/${teamKey}/`);
}

/**
 * FREE — subscribe a match to push delivery.
 *
 * Confirmed 2026-08-24 against Roanuz's own docs (see docs/CRICKET-BUILD-BRIEF.md): this call is
 * required before push events for a match start flowing at all — a match is not covered just
 * because it exists in the fixtures list. It costs nothing (it configures delivery, it doesn't
 * pull data), and is safe to call repeatedly for the same match: re-subscribing an already
 * subscribed match is treated as a normal, idempotent outcome, not an error, matching every other
 * "redelivery is a no-op" discipline in this pipeline.
 */
async function subscribeMatch(matchKey, { method = 'web_hook' } = {}) {
  return request('POST', `/match/${matchKey}/subscribe/`, { body: { method } });
}

/**
 * PAID — Rs 10 per player. Cold-start seed only, once per tournament, to bootstrap the credit
 * algorithm before any live data exists for that tournament's players. Never call this from a loop
 * or a scheduled job; it is invoked deliberately by an operator action.
 */
async function fetchPlayerStats(tournamentKey, playerKey) {
  const { logger } = context.get();
  logger.warn('cricket: PAID endpoint called - tournament player stats', {
    tournament: tournamentKey, player: playerKey, cost: 'Rs 10'
  });
  return request('GET', `/tournament/${tournamentKey}/player/${playerKey}/stats/`);
}

/** Map a provider fixture object onto a CricketFixture row. */
function mapFixture(raw, tournamentKey) {
  const key = normalize._pick(raw, ['key', 'match_key', 'id'], null);
  if (!key) return null;

  const startRaw = normalize._pick(raw, ['start_at', 'start_date.iso', 'start_time', 'scheduled_at'], null);
  const startTime = startRaw ? new Date(typeof startRaw === 'number' ? startRaw * 1000 : startRaw) : null;
  if (!startTime || Number.isNaN(startTime.getTime())) return null;

  return {
    key: String(key),
    tournament_key: tournamentKey,
    name: String(normalize._pick(raw, ['name', 'title'], key)),
    short_name: normalize._pick(raw, ['short_name', 'sub_title'], null),
    format: String(normalize._pick(raw, ['format', 'match_format'], 'T20')).toUpperCase(),
    venue: normalize._pick(raw, ['venue.name', 'venue'], null),
    team_a_key: String(normalize._pick(raw, ['teams.a.key', 'team_a.key', 'teams.a'], 'a')),
    team_a_name: String(normalize._pick(raw, ['teams.a.name', 'team_a.name'], 'Team A')),
    team_b_key: String(normalize._pick(raw, ['teams.b.key', 'team_b.key', 'teams.b'], 'b')),
    team_b_name: String(normalize._pick(raw, ['teams.b.name', 'team_b.name'], 'Team B')),
    start_time: startTime
  };
}

/**
 * Sync fixtures for every configured tournament, then subscribe each one to push delivery.
 *
 * Rows are keyed by the provider's own match key and upserted, never inserted blind — a
 * rescheduled match updates its existing row rather than producing a duplicate fixture that would
 * split contests and entries across two ids.
 *
 * `status` is deliberately not written here: it is owned by the push feed, and a periodic sync
 * overwriting a live match back to "scheduled" is exactly the kind of quiet corruption this
 * pipeline is built to avoid.
 *
 * The subscribe call happens here, once per synced fixture, rather than as a separate job: a
 * fixture that exists in the schedule but was never subscribed is a fixture that will never
 * receive a single push event, silently. Failing to subscribe does not fail the sync — the
 * fixture is still stored and can be resubscribed by re-running this (or the admin endpoint) once
 * the reason is fixed, same as every other soft-failure in this file.
 */
async function syncFixtures() {
  const { prisma, logger } = context.get();

  if (activeTransport.kind === 'live' && !isConfigured()) {
    logger.info('cricket: fixture sync skipped - no Roanuz credentials configured');
    return { ok: false, reason: 'not_configured', synced: 0, subscribed: 0 };
  }

  let synced = 0;
  let subscribed = 0;
  for (const tournamentKey of config.ROANUZ_TOURNAMENT_IDS) {
    const res = await fetchFixtures(tournamentKey);
    if (!res.ok) continue;

    const list = normalize._pick(res.data, ['data.fixtures', 'data.matches', 'fixtures', 'matches', 'data'], []);
    for (const raw of Array.isArray(list) ? list : []) {
      const row = mapFixture(raw, tournamentKey);
      if (!row) continue;
      try {
        const { key, ...rest } = row;
        await prisma.cricketFixture.upsert({
          where: { key },
          update: rest,
          create: row
        });
        synced += 1;

        const sub = await subscribeMatch(key);
        if (sub.ok) subscribed += 1;
        else logger.warn('cricket: could not subscribe fixture to push delivery', { key, reason: sub.reason });
      } catch (e) {
        logger.warn('cricket: fixture upsert failed', { key: row.key, message: e.message });
      }
    }
  }

  logger.info('cricket: fixture sync complete', {
    synced, subscribed, tournaments: config.ROANUZ_TOURNAMENT_IDS.length, transport: activeTransport.kind
  });
  return { ok: true, reason: 'ok', synced, subscribed };
}

/**
 * Sync announced squads for a fixture.
 *
 * The full squad is stored with `in_confirmed_xi: false`. Only the toss-time lineup event from the
 * push feed sets that flag — a team lock must never be gated on a squad announcement.
 */
async function syncSquad(fixtureKey) {
  const { prisma, logger } = context.get();

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: fixtureKey } });
  if (!fixture) return { ok: false, reason: 'unknown_fixture', synced: 0 };

  let synced = 0;
  for (const teamKey of [fixture.team_a_key, fixture.team_b_key]) {
    const res = await fetchSquads(fixture.tournament_key, teamKey);
    if (!res.ok) continue;

    const players = normalize._pick(res.data, ['data.players', 'players'], []);
    const list = Array.isArray(players) ? players : Object.values(players || {});

    for (const raw of list) {
      const playerKey = normalize._pick(raw, ['key', 'player_key', 'id'], null);
      if (!playerKey) continue;

      const row = {
        fixture_key: fixtureKey,
        player_key: String(playerKey),
        name: String(normalize._pick(raw, ['name', 'full_name'], playerKey)),
        role: mapRole(normalize._pick(raw, ['roles.play_role', 'play_role', 'role'], '')),
        team_key: String(teamKey),
        batting_style: normalize._pick(raw, ['roles.batting_style', 'batting_style'], null),
        bowling_style: normalize._pick(raw, ['roles.bowling_style', 'bowling_style'], null)
      };

      try {
        const { fixture_key, player_key, ...rest } = row;
        await prisma.cricketSquadPlayer.upsert({
          where: { fixture_key_player_key: { fixture_key, player_key } },
          update: rest,
          create: row
        });
        synced += 1;
      } catch (e) {
        logger.warn('cricket: squad upsert failed', { player: row.player_key, message: e.message });
      }
    }
  }
  return { ok: true, reason: 'ok', synced };
}

/** Provider role strings -> the four fantasy roles. */
function mapRole(raw) {
  const value = String(raw || '').toLowerCase();
  if (value.includes('keeper')) return 'WK';
  if (value.includes('all')) return 'AR';
  if (value.includes('bowl')) return 'BOWL';
  if (value.includes('bat')) return 'BAT';
  return 'BAT';
}

/**
 * Stamp the confirmed Playing XI onto the stored squad, from the toss-time `lineup` push event —
 * the one place the `in_confirmed_xi` flag this file's squad rows carry is actually meant to be
 * set (see the comment on `syncSquad` above). Recomputed in full from the event's own player list
 * every time it fires, same discipline as the live-state builder: a redelivered lineup event is a
 * no-op, not a drift risk.
 */
async function applyConfirmedXi(fixtureKey, confirmedXi) {
  const { prisma, logger } = context.get();
  const keys = Array.from(new Set((confirmedXi || []).map(String)));
  if (!keys.length) return { ok: false, reason: 'empty_confirmed_xi', updated: 0 };

  try {
    const inXi = await prisma.cricketSquadPlayer.updateMany({
      where: { fixture_key: String(fixtureKey), player_key: { in: keys } },
      data: { in_confirmed_xi: true }
    });
    await prisma.cricketSquadPlayer.updateMany({
      where: { fixture_key: String(fixtureKey), player_key: { notIn: keys } },
      data: { in_confirmed_xi: false }
    });
    return { ok: true, updated: inXi.count };
  } catch (e) {
    logger.warn('cricket: could not stamp confirmed XI onto squad', { fixture_key: fixtureKey, message: e.message });
    return { ok: false, reason: e.message, updated: 0 };
  }
}

module.exports = {
  isConfigured,
  syncFixtures,
  syncSquad,
  applyConfirmedXi,
  fetchFixtures,
  fetchSquads,
  fetchPlayerStats,
  subscribeMatch,
  mapFixture,
  mapRole,
  setTransport,
  getTransport,
  BASE_URL: config.ROANUZ_BASE_URL
};
