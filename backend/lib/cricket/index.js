/**
 * Cricket pipeline wiring.
 *
 * server.js calls exactly three things from here, which is the whole integration surface:
 *
 *   cricket.init({ prisma, logger })   once, at boot
 *   cricket.registerIngest(app)        BEFORE express.json() and before the /api rate limiter
 *   cricket.register(app, { auth })    with the other route groups
 *
 * `registerIngest` has to come first for two concrete reasons, both found in the Phase 0 audit:
 *
 *   1. Signature verification needs the raw request bytes. `express.json()` is mounted globally and
 *      discards them, and re-serialising `req.body` does not reproduce what was signed.
 *   2. `app.use('/api/', apiLimiter)` caps every /api path at 600 req/min. A fast over can deliver
 *      a burst, and a rate-limited webhook means silently dropped balls in a permanent log.
 *
 * Everything is behind CRICKET_ENABLED. With the flag off, not one route is mounted and not one
 * timer starts, so the rest of the site runs exactly as it did before this existed.
 */

const express = require('express');
const crypto = require('crypto');
const config = require('../../config');
const context = require('./context');
const collector = require('./collector');
const eventLog = require('./event-log');
const liveState = require('./live-state');
const fanout = require('./fanout');
const health = require('./health');
const roanuz = require('./roanuz');
const contests = require('./contests');
const houseEntry = require('./house-entry');
const boundary = require('./boundary');
const configStore = require('./config-store');
const parallelValidation = require('./parallel-validation');

const WEBHOOK_PATH = '/api/cricket/webhook';

const round2 = n => Math.round((Number(n) || 0) * 100) / 100;

let fixtureSyncTimer = null;

function init({ prisma, logger, wallet, houseEdge }) {
  return context.init({ prisma, logger, wallet, houseEdge });
}

/**
 * Mount the push-feed receiver. Must be called before express.json() and before the /api limiter.
 *
 * The raw body parser is scoped to this one path only, matching every content type. Because
 * body-parser marks a request as already parsed, the global JSON parser downstream skips it, so no
 * other route's body handling changes at all.
 */
function registerIngest(app) {
  if (!config.CRICKET_ENABLED) return;
  const { logger } = context.get();

  app.post(
    WEBHOOK_PATH,
    express.raw({ type: '*/*', limit: '2mb' }),
    async (req, res) => {
      const signature =
        req.headers['x-roanuz-signature'] ||
        req.headers['x-signature'] ||
        req.headers['x-hub-signature-256'] ||
        null;
      // The confirmed verification path (see collector.verifySignature): a secret baked into the
      // webhook URL registered with Roanuz, as a query string or a header, since HMAC support is
      // unconfirmed but an arbitrary registered URL is not.
      const secretParam = req.headers['x-roanuz-secret'] || req.query.secret || null;

      try {
        const result = await collector.ingest(req.body, { signature, secretParam });
        // A duplicate is a success: the feed is at-least-once by design, and answering non-2xx
        // would make the provider redeliver something already safely stored.
        return res.status(result.status).json({
          ok: result.ok,
          stored: result.stored,
          duplicates: result.duplicates,
          reason: result.reason
        });
      } catch (e) {
        // Only a genuine durability failure reaches here. Answer 500 so the provider retries —
        // safe precisely because the log deduplicates on the provider's own event id.
        logger.error('cricket: webhook ingest failed', { message: e.message });
        return res.status(500).json({ ok: false, error: 'ingest_failed' });
      }
    }
  );

  logger.info('cricket: push webhook mounted', { path: WEBHOOK_PATH });
}

/** Everything else: player-facing reads, the SSE stream, and the operator endpoints. */
function register(app, { auth, requireDatabase }) {
  if (!config.CRICKET_ENABLED) return;
  const { prisma, logger } = context.get();

  // --- live stream ------------------------------------------------------------------------------
  // The only channel by which cricket data reaches a browser. No client ever calls Roanuz, so the
  // provider bill does not move when the audience does.
  app.get('/api/cricket/stream/:fixtureKey', requireDatabase, async (req, res) => {
    const fixtureKey = String(req.params.fixtureKey || '');
    let initial = null;
    try {
      const rows = await eventLog.readEvents(fixtureKey);
      initial = liveState.buildLiveState(rows, { fixtureKey });
    } catch (e) {
      logger.warn('cricket: could not build initial stream state', { fixtureKey, message: e.message });
    }
    fanout.subscribe(fixtureKey, req, res, { initialState: initial });
  });

  // --- fixtures ---------------------------------------------------------------------------------
  app.get('/api/cricket/fixtures', requireDatabase, async (req, res, next) => {
    try {
      const fixtures = await prisma.cricketFixture.findMany({
        orderBy: { start_time: 'asc' },
        take: 100
      });
      res.json({ ok: true, fixtures });
    } catch (e) {
      next(e);
    }
  });

  app.get('/api/cricket/fixtures/:fixtureKey/state', requireDatabase, async (req, res, next) => {
    const fixtureKey = String(req.params.fixtureKey || '');
    try {
      const rows = await eventLog.readEvents(fixtureKey);
      res.json({ ok: true, state: liveState.buildLiveState(rows, { fixtureKey }) });
    } catch (e) {
      next(e);
    }
  });

  app.get('/api/cricket/fixtures/:fixtureKey/squad', requireDatabase, async (req, res, next) => {
    try {
      const squad = await prisma.cricketSquadPlayer.findMany({
        where: { fixture_key: String(req.params.fixtureKey || '') },
        orderBy: [{ team_key: 'asc' }, { role: 'asc' }, { name: 'asc' }]
      });
      res.json({ ok: true, squad });
    } catch (e) {
      next(e);
    }
  });

  // Per-player credit prices for a fixture. The team builder needs this to render and enforce the
  // credit budget client-side; joinContest already reads the same rows to enforce it server-side
  // (contests.js), so this is a read-only mirror, not a second source of truth.
  app.get('/api/cricket/fixtures/:fixtureKey/credits', requireDatabase, async (req, res, next) => {
    try {
      const rows = await prisma.cricketPlayerCredit.findMany({
        where: { fixture_key: String(req.params.fixtureKey || '') }
      });
      const creditsByPlayer = {};
      for (const row of rows) creditsByPlayer[row.player_key] = row.credits;
      res.json({ ok: true, credits: creditsByPlayer });
    } catch (e) {
      next(e);
    }
  });

  // Client-safe config: the rules a lineup or a Boundary stake must satisfy. Scoring weights and the
  // house-entry trajectory stay server-only — there is no reason to hand a browser the numbers that
  // decide how the rig climbs.
  app.get('/api/cricket/config', async (req, res, next) => {
    try {
      const [contestRules, boundaryRules] = await Promise.all([
        configStore.contest(),
        configStore.boundary()
      ]);
      res.json({
        ok: true,
        contest: {
          role_limits: contestRules.role_limits,
          credit_budget: contestRules.credit_budget,
          max_per_real_team: contestRules.max_per_real_team,
          squad_size: contestRules.squad_size
        },
        boundary: {
          options: boundaryRules.options,
          min_stake: boundaryRules.min_stake,
          max_stake: boundaryRules.max_stake,
          rake_pct: boundaryRules.rake_pct
        }
      });
    } catch (e) {
      next(e);
    }
  });

  // --- Your 11 contests --------------------------------------------------------------------------
  // Private contests are deliberately excluded here — reachable only by invite code (below), the
  // same "not discoverable" property the house entry relies on for its own secrecy.
  app.get('/api/cricket/fixtures/:fixtureKey/contests', requireDatabase, async (req, res, next) => {
    try {
      const rows = await prisma.cricketContest.findMany({
        where: {
          fixture_key: String(req.params.fixtureKey || ''),
          status: { in: ['open', 'locked'] },
          format: { not: 'private' }
        },
        orderBy: [{ entry_fee: 'asc' }, { created_at: 'asc' }]
      });

      // Entrant counts drive the "spots left" bar, so they are read live rather than denormalised
      // onto the contest row where they would drift.
      const withCounts = await Promise.all(rows.map(async c => ({
        ...c,
        entrants: await prisma.cricketEntry.count({ where: { contest_id: c.id } })
      })));

      res.json({ ok: true, contests: withCounts });
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/contests/:contestId/join', auth.requireAuth, requireDatabase, async (req, res, next) => {
    try {
      const username = auth.actingUsername(req);
      const result = await contests.joinContest(String(req.params.contestId || ''), username, req.body || {});
      if (!result.ok) return res.status(result.status || 400).json({ ok: false, error: result.error });
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  // Create a private contest. A thin, guardrailed wrapper over contests.createContest: format and
  // created_by are fixed server-side (never client-supplied), and an invite code is always generated
  // here rather than trusted from the request, so a caller can't pick a guessable or colliding one.
  app.post('/api/cricket/contests/private', auth.requireAuth, requireDatabase, async (req, res, next) => {
    try {
      const username = auth.actingUsername(req);
      const body = req.body || {};
      const inviteCode = crypto.randomBytes(4).toString('hex');

      const result = await contests.createContest({
        fixture_key: body.fixture_key,
        name: String(body.name || `${username}'s private contest`),
        format: 'private',
        entry_fee: body.entry_fee,
        rake_pct: body.rake_pct,
        prize_breakup: Array.isArray(body.prize_breakup) && body.prize_breakup.length
          ? body.prize_breakup
          : [{ from: 1, to: 1, pct: 100 }],
        min_entrants: body.min_entrants,
        max_entrants: body.max_entrants,
        max_entries_per_user: body.max_entries_per_user,
        guaranteed: false,
        invite_code: inviteCode,
        created_by: username
      });
      if (!result.ok) return res.status(400).json(result);
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  // Look up a private contest by its invite code — the only way one is discoverable.
  app.get('/api/cricket/contests/private/:inviteCode', auth.requireAuth, requireDatabase, async (req, res, next) => {
    try {
      const contest = await prisma.cricketContest.findUnique({
        where: { invite_code: String(req.params.inviteCode || '') }
      });
      if (!contest) return res.status(404).json({ ok: false, error: 'No contest with that code.' });
      const entrants = await prisma.cricketEntry.count({ where: { contest_id: contest.id } });
      res.json({ ok: true, contest: { ...contest, entrants } });
    } catch (e) {
      next(e);
    }
  });

  app.get('/api/cricket/contests/:contestId/leaderboard', requireDatabase, async (req, res, next) => {
    try {
      const result = await contests.leaderboard(String(req.params.contestId || ''), {
        viewer: auth.actingUsername(req) || null
      });
      if (!result.ok) return res.status(result.status || 400).json({ ok: false, error: result.error });
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  app.get('/api/cricket/my-entries', auth.requireAuth, requireDatabase, async (req, res, next) => {
    try {
      const username = auth.actingUsername(req);
      const entries = await prisma.cricketEntry.findMany({
        where: { username },
        orderBy: { created_at: 'desc' },
        take: 100,
        include: { contest: true }
      });
      res.json({ ok: true, entries });
    } catch (e) {
      next(e);
    }
  });

  // --- Boundary Baazi -----------------------------------------------------------------------------
  // The market currently open on a match, if any. Clients read this and the SSE stream; the stream
  // is what tells them the moment it locks, because a client-side countdown would be exactly the
  // time-based gate this game must not have.
  app.get('/api/cricket/boundary/:fixtureKey/current', requireDatabase, async (req, res, next) => {
    try {
      const round = await prisma.boundaryRound.findFirst({
        where: { fixture_key: String(req.params.fixtureKey || ''), status: 'open' },
        orderBy: { created_at: 'desc' }
      });
      if (!round) return res.json({ ok: true, round: null });

      // Live pool sizes per option. This is a parimutuel market, so what everyone else has backed is
      // exactly what determines a payout — hiding it would leave players betting blind.
      const bets = await prisma.boundaryBet.findMany({ where: { round_id: round.id } });
      const byOption = {};
      for (const b of bets) byOption[b.option_key] = round2(( byOption[b.option_key] || 0) + b.stake);

      res.json({ ok: true, round, pool: byOption, total_pool: round2(bets.reduce((s, b) => s + b.stake, 0)) });
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/boundary/:fixtureKey/bet', auth.requireAuth, requireDatabase, async (req, res, next) => {
    try {
      const { delivery_key: deliveryKey, option, stake } = req.body || {};
      const result = await boundary.placeBet(
        String(req.params.fixtureKey || ''), String(deliveryKey || ''),
        auth.actingUsername(req), String(option || ''), stake
      );
      if (!result.ok) return res.status(result.status || 400).json({ ok: false, error: result.error });
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  app.get('/api/cricket/boundary/:fixtureKey/history', requireDatabase, async (req, res, next) => {
    try {
      const rounds = await prisma.boundaryRound.findMany({
        where: { fixture_key: String(req.params.fixtureKey || ''), status: 'resolved' },
        orderBy: { resolved_at: 'desc' },
        take: 30
      });
      res.json({ ok: true, rounds });
    } catch (e) {
      next(e);
    }
  });

  app.get('/api/cricket/boundary/my-bets', auth.requireAuth, requireDatabase, async (req, res, next) => {
    try {
      const bets = await prisma.boundaryBet.findMany({
        where: { username: auth.actingUsername(req) },
        orderBy: { created_at: 'desc' },
        take: 100,
        include: { round: true }
      });
      res.json({ ok: true, bets });
    } catch (e) {
      next(e);
    }
  });

  // --- operator ---------------------------------------------------------------------------------
  // Feed health. Until an alert channel is wired this endpoint and the process log are the only
  // places a stall is visible at all.
  app.get('/api/cricket/admin/health', auth.requireAdmin, (req, res) => {
    res.json({
      ok: true,
      health: health.report(),
      fanout: fanout.stats(),
      provider_configured: roanuz.isConfigured()
    });
  });

  // Generic read/write for the five config-store.js sections. Ground Rule 6 ("everything score- or
  // money-related is config, not a constant") is only meaningful if an operator can actually see and
  // edit it — this is what the admin console's config panel drives.
  app.get('/api/cricket/admin/config/:key', auth.requireAdmin, async (req, res, next) => {
    try {
      const shortKey = String(req.params.key || '');
      const fullKey = configStore.KEYS[shortKey];
      if (!fullKey) return res.status(404).json({ ok: false, error: 'Unknown config section.' });
      res.json({ ok: true, key: shortKey, value: await configStore.get(fullKey) });
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/admin/config/:key', auth.requireAdmin, async (req, res, next) => {
    try {
      const shortKey = String(req.params.key || '');
      const fullKey = configStore.KEYS[shortKey];
      if (!fullKey) return res.status(404).json({ ok: false, error: 'Unknown config section.' });
      const value = await configStore.set(fullKey, req.body || {}, {
        signedOff: true,
        updatedBy: auth.actingUsername(req)
      });
      logger.warn('cricket: config section edited by an operator', { key: shortKey, admin: auth.actingUsername(req) });
      res.json({ ok: true, key: shortKey, value });
    } catch (e) {
      next(e);
    }
  });

  // Hand-create a fixture. The only way to exercise the pipeline end-to-end before Roanuz
  // credentials exist — everything downstream (squad, credits, contests, events) already accepts a
  // fixture created any way at all, since it only ever reads the CricketFixture row by key.
  app.post('/api/cricket/admin/fixtures', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const body = req.body || {};
      if (!body.key || !body.team_a_key || !body.team_b_key || !body.start_time) {
        return res.status(400).json({ ok: false, error: 'key, team_a_key, team_b_key and start_time are required.' });
      }
      const fixture = await prisma.cricketFixture.upsert({
        where: { key: String(body.key) },
        update: {},
        create: {
          key: String(body.key),
          tournament_key: String(body.tournament_key || 'manual'),
          name: String(body.name || `${body.team_a_key} vs ${body.team_b_key}`),
          short_name: body.short_name ? String(body.short_name) : null,
          format: String(body.format || 'T20').toUpperCase(),
          venue: body.venue ? String(body.venue) : null,
          team_a_key: String(body.team_a_key),
          team_a_name: String(body.team_a_name || body.team_a_key),
          team_b_key: String(body.team_b_key),
          team_b_name: String(body.team_b_name || body.team_b_key),
          start_time: new Date(body.start_time)
        }
      });
      res.json({ ok: true, fixture });
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/admin/contests', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const result = await contests.createContest(req.body || {});
      if (!result.ok) return res.status(400).json({ ok: false, error: result.error });
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  // Manual retry for the lock and settle steps the feed normally drives. Both are idempotent, so an
  // operator pressing either twice is harmless — which is what makes them safe to expose at all.
  app.post('/api/cricket/admin/fixtures/:fixtureKey/lock', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      res.json(await contests.lockContestsForFixture(String(req.params.fixtureKey || '')));
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/admin/contests/:contestId/settle', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      // `force` overrides a reconciliation hold. It is the documented exception path for when an
      // operator has checked a discrepancy by hand and accepted it, so it is recorded as such.
      const force = !!(req.body && req.body.force);
      if (force) {
        logger.warn('cricket: settlement FORCED past reconciliation by an operator', {
          contest_id: req.params.contestId, admin: auth.actingUsername(req)
        });
      }
      const result = await contests.settleContest(String(req.params.contestId || ''), { force });
      if (!result.ok) return res.status(result.status || 400).json(result);
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/admin/contests/:contestId/void', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const reason = String((req.body && req.body.reason) || 'operator_void');
      logger.warn('cricket: contest voided by an operator', {
        contest_id: req.params.contestId, admin: auth.actingUsername(req), reason
      });
      const result = await contests.voidContest(String(req.params.contestId || ''), reason);
      if (!result.ok) return res.status(400).json(result);
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  // The "rig this contest" override (docs/YOUR11-SCOPE.md section 4), following the same pattern as
  // TeenPattiRoom.admin_rig and the Mines rig matrix.
  app.post('/api/cricket/admin/contests/:contestId/house', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const result = await houseEntry.forceEnter(String(req.params.contestId || ''), {
        by: auth.actingUsername(req)
      });
      if (!result.ok) return res.status(400).json(result);
      res.json(result);
    } catch (e) {
      next(e);
    }
  });

  // An unresolved market holds real money. This is the manual release valve for one that the feed
  // never resolved — it refunds in full rather than guessing an outcome.
  app.post('/api/cricket/admin/boundary/:fixtureKey/void-open', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const reason = String((req.body && req.body.reason) || 'operator_void');
      logger.warn('cricket: open boundary markets voided by an operator', {
        fixture_key: req.params.fixtureKey, admin: auth.actingUsername(req), reason
      });
      res.json(await boundary.voidOpenRounds(String(req.params.fixtureKey || ''), reason));
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/admin/sync-fixtures', auth.requireAdmin, async (req, res, next) => {
    try {
      res.json({ ok: true, result: await roanuz.syncFixtures() });
    } catch (e) {
      next(e);
    }
  });

  app.post('/api/cricket/admin/sync-squad/:fixtureKey', auth.requireAdmin, async (req, res, next) => {
    try {
      res.json({ ok: true, result: await roanuz.syncSquad(String(req.params.fixtureKey || '')) });
    } catch (e) {
      next(e);
    }
  });

  // `syncFixtures` subscribes every fixture it syncs automatically; this is the manual retry for a
  // hand-created fixture (there is no free "fixtures" call to sync one from) or one whose
  // subscription failed the first time - safe to call repeatedly, same as the sync endpoints above.
  app.post('/api/cricket/admin/subscribe/:fixtureKey', auth.requireAdmin, async (req, res, next) => {
    try {
      res.json(await roanuz.subscribeMatch(String(req.params.fixtureKey || '')));
    } catch (e) {
      next(e);
    }
  });

  // Roanuz's push feed and REST API were never confirmed to surface a final scorecard field
  // (docs/CRICKET-BUILD-BRIEF.md Section 12 step 8), so until that's checked against a live match
  // this is the only way `official_scorecard` gets populated: an operator transcribes it from the
  // provider's own post-match scorecard. Same shape `scoring.reconcile` reads elsewhere -
  // `{ players: { [player_key]: { runs, balls_faced, wickets, runs_conceded } } }` - enforced here
  // just enough that a malformed paste fails loudly instead of silently validating against nothing.
  app.post('/api/cricket/admin/fixtures/:fixtureKey/scorecard', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const fixtureKey = String(req.params.fixtureKey || '');
      const players = (req.body || {}).players;
      if (!players || typeof players !== 'object' || Array.isArray(players)) {
        return res.status(400).json({ ok: false, error: 'Body must be { players: { [player_key]: { runs, balls_faced, wickets, runs_conceded } } }.' });
      }
      const exists = await prisma.cricketFixture.findUnique({ where: { key: fixtureKey } });
      if (!exists) return res.status(404).json({ ok: false, error: 'Unknown fixture.' });
      const fixture = await prisma.cricketFixture.update({
        where: { key: fixtureKey },
        data: { official_scorecard: { players } }
      });
      logger.warn('cricket: official scorecard recorded by an operator', { fixture_key: fixtureKey, admin: auth.actingUsername(req), player_count: Object.keys(players).length });
      res.json({ ok: true, fixture_key: fixtureKey, official_scorecard: fixture.official_scorecard });
    } catch (e) {
      next(e);
    }
  });

  // The Section 9 parallel-run validation harness, callable on demand for any covered fixture -
  // this is what the operator runs after each of the first 5-10 real matches, before trusting the
  // in-house scoring engine with unattended settlement.
  app.get('/api/cricket/admin/validate/:fixtureKey', auth.requireAdmin, requireDatabase, async (req, res, next) => {
    try {
      const report = await parallelValidation.validateFixture(String(req.params.fixtureKey || ''));
      if (!report.ok) return res.status(404).json(report);
      res.json(report);
    } catch (e) {
      next(e);
    }
  });

  logger.info('cricket: routes mounted');
}

/**
 * Start background work: the low-frequency fixtures sync.
 *
 * Deliberately infrequent. The Standard licence allows roughly 1,200 unique resources a month, and
 * live data arrives on the push feed — adding a polling loop on top of it would spend the budget
 * for no benefit.
 */
function start() {
  if (!config.CRICKET_ENABLED) return;
  const { logger } = context.get();

  if (!roanuz.isConfigured()) {
    logger.warn('cricket: enabled but no Roanuz credentials - fixtures will not sync and no match can be covered');
  }

  const run = () => {
    roanuz.syncFixtures().catch(e => logger.error('cricket: fixture sync threw', { message: e.message }));
  };

  run();
  fixtureSyncTimer = setInterval(run, config.CRICKET_FIXTURE_SYNC_MS);
  if (typeof fixtureSyncTimer.unref === 'function') fixtureSyncTimer.unref();
}

/** Test seam: stop every timer and drop every subscriber. */
function shutdown() {
  if (fixtureSyncTimer) {
    clearInterval(fixtureSyncTimer);
    fixtureSyncTimer = null;
  }
  fanout.shutdown();
  health.reset();
}

module.exports = {
  init,
  registerIngest,
  register,
  start,
  shutdown,
  WEBHOOK_PATH,
  collector,
  eventLog,
  liveState,
  fanout,
  health,
  roanuz,
  contests,
  houseEntry,
  boundary,
  context
};
