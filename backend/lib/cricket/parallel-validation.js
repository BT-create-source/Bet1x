/**
 * The parallel-run validation harness docs/CRICKET-BUILD-BRIEF.md Section 9 requires before real
 * settlement is trusted: recompute a match from the permanent event log and diff it against the
 * official scorecard, the same comparison `contests.settleContest` already makes at settlement
 * time (`scoring.reconcile`) — but callable on demand, for every match in the validation window,
 * without needing a contest or any money involved at all.
 *
 * Built now, deliberately, even with no live match to point it at yet: the brief is explicit that
 * this tooling should not be built under time pressure at launch. `validateSnapshot` is the pure
 * core (an event list + a reference scorecard in, a report out) and is exercised in
 * test_cricket.js against a synthetic match today. `validateFixture` is the thin DB-backed wrapper
 * `cricket_validate.js` and the admin endpoint call — point either at a real fixture key the
 * moment a real match has run and its official scorecard has been recorded, and nothing here
 * changes.
 */

const eventLog = require('./event-log');
const liveState = require('./live-state');
const scoring = require('./scoring');
const context = require('./context');

/**
 * Pure: given already-loaded event rows and a reference scorecard, recompute and diff.
 *
 * `officialScorecard` follows the same shape `scoring.reconcile` already reads elsewhere
 * (`{ players: { [player_key]: { runs, balls_faced, wickets, runs_conceded } } }`) — this file
 * introduces no second schema for the same idea.
 */
function validateSnapshot(events, officialScorecard, { fixtureKey = null, tolerance = 0 } = {}) {
  const state = liveState.buildLiveState(events, { fixtureKey });
  const reconciliation = scoring.reconcile(state, officialScorecard, { tolerance });

  return {
    fixture_key: fixtureKey,
    event_count: events.length,
    computed_score: state.score,
    computed_status: state.status,
    match_ended: state.match_ended,
    reconciliation
  };
}

/**
 * DB-backed: pull a fixture's permanent event log and its recorded official scorecard, and run the
 * same comparison. Returns `{ ok:false, reason:'no_fixture' }` for an unknown key and
 * `{ ok:false, reason:'no_official_scorecard' }` (via `reconciliation.reason`) when nothing has
 * been recorded to compare against yet — both real, expected outcomes during the pre-launch
 * window, not errors to throw on.
 */
async function validateFixture(fixtureKey, { tolerance = 0 } = {}) {
  const { prisma } = context.get();

  const fixture = await prisma.cricketFixture.findUnique({ where: { key: String(fixtureKey) } });
  if (!fixture) return { ok: false, reason: 'no_fixture', fixture_key: fixtureKey };

  const rows = await eventLog.readEvents(fixtureKey);
  const report = validateSnapshot(rows, fixture.official_scorecard, { fixtureKey, tolerance });

  return { ok: true, fixture: { key: fixture.key, name: fixture.name, status: fixture.status }, ...report };
}

module.exports = { validateSnapshot, validateFixture };
