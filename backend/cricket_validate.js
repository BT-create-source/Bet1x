/**
 * The parallel-run validation CLI — docs/CRICKET-BUILD-BRIEF.md Section 9's "run it in parallel
 * against real matches and compare its output to the official scorecard for the first 5-10
 * matches," runnable from a terminal without going through the admin console.
 *
 *   node backend/cricket_validate.js <fixtureKey>       — validate one real (or hand-created) fixture
 *   node backend/cricket_validate.js --demo             — prove the harness itself works, no DB needed
 *
 * The --demo path plays a tiny synthetic innings against two references: one that matches exactly
 * (must report ok) and one seeded with a wrong figure (must report the exact discrepancy) — so this
 * script is a working, self-verifying tool today, ready to point at a real fixture key the moment
 * one exists, with nothing here to write differently when that day comes.
 */

const context = require('./lib/cricket/context');
const parallelValidation = require('./lib/cricket/parallel-validation');

function printReport(report) {
  console.log(JSON.stringify(report, null, 2));
  if (report.reconciliation) {
    console.log(
      report.reconciliation.ok
        ? '\nRESULT: computed figures match the official scorecard.'
        : `\nRESULT: ${report.reconciliation.reason} — ${report.reconciliation.discrepancies.length} discrepancy(ies) above.`
    );
  }
}

async function runDemo() {
  context.init({ prisma: {}, logger: console });

  const events = [
    { id: 1, event_id: 'd1', fixture_key: 'demo', event_type: 'ball', innings: 1, over: 0, ball: 1, payload: { event_type: 'ball', batsman: 'bat1', bowler: 'bowl1', batsman_run: 4 } },
    { id: 2, event_id: 'd2', fixture_key: 'demo', event_type: 'ball', innings: 1, over: 0, ball: 2, payload: { event_type: 'ball', batsman: 'bat1', bowler: 'bowl1', batsman_run: 1 } },
    { id: 3, event_id: 'd3', fixture_key: 'demo', event_type: 'ball', innings: 1, over: 0, ball: 3, payload: { event_type: 'ball', batsman: 'bat1', bowler: 'bowl1', is_wicket: true, wicket_type: 'bowled', out_player: 'bat1' } }
  ];

  console.log('--- demo 1: a scorecard that matches exactly ---');
  printReport(parallelValidation.validateSnapshot(events, { players: { bat1: { runs: 5, balls_faced: 3 } } }, { fixtureKey: 'demo' }));

  console.log('\n--- demo 2: a scorecard seeded with a wrong figure ---');
  printReport(parallelValidation.validateSnapshot(events, { players: { bat1: { runs: 99, balls_faced: 3 } } }, { fixtureKey: 'demo' }));

  console.log('\n--- demo 3: no official scorecard recorded yet ---');
  printReport(parallelValidation.validateSnapshot(events, null, { fixtureKey: 'demo' }));
}

async function runAgainstDatabase(fixtureKey) {
  const { PrismaClient } = require('@prisma/client');
  const prisma = new PrismaClient();
  context.init({ prisma, logger: console });

  const report = await parallelValidation.validateFixture(fixtureKey);
  printReport(report);

  await prisma.$disconnect();
  if (!report.ok || (report.reconciliation && !report.reconciliation.ok)) process.exitCode = 1;
}

const arg = process.argv[2];
if (!arg || arg === '--help') {
  console.log(__filename.split(/[\\/]/).pop() + ': node backend/cricket_validate.js <fixtureKey> | --demo');
  process.exit(1);
} else if (arg === '--demo') {
  runDemo().catch(e => { console.error(e); process.exit(1); });
} else {
  runAgainstDatabase(arg).catch(e => { console.error(e); process.exit(1); });
}
