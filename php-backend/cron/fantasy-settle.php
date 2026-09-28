<?php
/**
 * "Your Eleven" — settlement worker.
 *
 * =================================================================================================
 * WHAT IT DOES
 * =================================================================================================
 * Finds fixtures the SOURCE has reported finished, does one last re-score so the figures settled
 * against are the final ones, then settles every open contest on them: ranks the entries, allocates
 * the pool, credits the winners through the platform's existing credit_wallet() and
 * insert_transaction(), and closes the contest.
 *
 * INSTALL (cPanel -> Cron Jobs), every ten minutes is ample — a match ends once:
 *
 *     2,12,22,32,42,52 * * * * /usr/local/bin/php /home/betxbiz/public_html/php-backend/cron/fantasy-settle.php >/dev/null 2>&1
 *
 * By hand:
 *     php php-backend/cron/fantasy-settle.php
 *     php php-backend/cron/fantasy-settle.php --force            # ignore FANTASY_ENABLED
 *     php php-backend/cron/fantasy-settle.php --match=12         # one fixture
 *     php php-backend/cron/fantasy-settle.php --dry-run          # report what it WOULD pay
 *
 * =================================================================================================
 * WHY IT IS SAFE TO RUN THIS AS OFTEN AS YOU LIKE
 * =================================================================================================
 *  - It only considers fixtures whose source_state is final. It never settles on a clock.
 *  - Each contest is claimed with a conditional UPDATE on its status, inside the transaction that
 *    pays it. A second run — or a second worker — finds nothing to claim and pays nothing. That is
 *    the idempotency guard, and it is the database's decision rather than a check in PHP.
 *  - A contest is paid entirely or not at all: the claim, every credit, every ledger row and every
 *    entry update share one transaction.
 *  - --dry-run reports the allocation without touching a wallet, which is the sane way to look at a
 *    real fixture's numbers before letting it pay out for the first time.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

require_once __DIR__ . '/../config.php';
date_default_timezone_set((string) env_get('APP_TIMEZONE', 'UTC'));

require_once __DIR__ . '/../lib/json.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/fantasy.php';
require_once __DIR__ . '/../lib/fantasy-source.php';
require_once __DIR__ . '/../lib/fantasy-scoring.php';
require_once __DIR__ . '/../lib/fantasy-live.php';
require_once __DIR__ . '/../lib/fantasy-contests.php';
require_once __DIR__ . '/../lib/fantasy-settle.php';

$args   = array_slice($argv, 1);
$force  = in_array('--force', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$onlyMatch = 0;
foreach ($args as $a) {
    if (strpos($a, '--match=') === 0) $onlyMatch = (int) substr($a, strlen('--match='));
}

if (!fantasy_enabled() && !$force) {
    echo "FANTASY_ENABLED is false — nothing settled. Re-run with --force to settle anyway.\n";
    exit(0);
}
if (!db_ready()) {
    log_error('fantasy settle: database unreachable');
    fwrite(STDERR, "Database unreachable.\n");
    exit(1);
}

$startedAt = microtime(true);
$summary = ['matches' => 0, 'contests' => 0, 'paid' => 0.0, 'winners' => 0,
            'undistributed' => 0.0, 'voided' => 0, 'refunded' => 0.0,
            'dry_run' => $dryRun, 'errors' => []];
$ran = false;

try {
    with_named_lock('fantasy_settle', 5, function () use (&$summary, &$ran, $onlyMatch, $dryRun) {
        $ran = true;

        // Only fixtures the source calls finished, and only ones that still have something open.
        $rows = all(
            'SELECT m."id", m."match_title", m."source_state", m."source_status_text" '
            . 'FROM "fantasy_matches" m '
            . 'WHERE m."status" <> ? AND m."source_state" IS NOT NULL'
            . ($onlyMatch ? ' AND m."id" = ' . (int) $onlyMatch : '')
            . ' ORDER BY m."start_time" ASC LIMIT 20',
            ['SETTLED']
        );

        foreach ($rows as $m) {
            if (!fantasy_state_is_final($m['source_state'])) continue;
            $matchId = (int) $m['id'];

            $open = (int) scalar(
                'SELECT COUNT(*) FROM "fantasy_contests" WHERE "match_id" = ? AND "status" <> ? AND "status" <> ?',
                [$matchId, 'SETTLED', 'CANCELLED'], 0
            );
            if ($open === 0) continue;

            $summary['matches']++;
            echo '  ' . $m['match_title'] . ' — ' . $m['source_state']
                 . ' (' . ($m['source_status_text'] ?? '') . ")\n";

            try {
                if ($dryRun) {
                    // Show the allocation without touching anything.
                    $contests = all('SELECT "id" FROM "fantasy_contests" WHERE "match_id" = ? '
                                    . 'AND "status" <> ? AND "status" <> ?',
                                    [$matchId, 'SETTLED', 'CANCELLED']);
                    foreach ($contests as $c) {
                        $board = fantasy_contest_leaderboard((int) $c['id'], 500);
                        if (!$board) continue;
                        $would = 0.0;
                        foreach ($board['entries'] as $e) $would += (float) $e['prize'];
                        printf("      contest %-4d pool %10.2f  would pay %10.2f  to %d entries\n",
                               (int) $c['id'], $board['pool']['prize_pool'], $would,
                               count($board['entries']));
                        $summary['contests']++;
                        $summary['paid'] += $would;
                        $summary['undistributed'] += round($board['pool']['prize_pool'] - $would, 2);
                    }
                    continue;
                }

                // A final re-score before the money moves, so settlement uses the last figures the
                // source published rather than whatever the previous poll happened to catch.
                $sourceId = fantasy_source_id_from_key(
                    (string) scalar('SELECT "external_key" FROM "fantasy_matches" WHERE "id" = ?', [$matchId], '')
                );
                if ($sourceId > 0) {
                    $card = fantasy_source_scorecard($sourceId);
                    if ($card['ok']) {
                        fantasy_ingest_scorecard($matchId, $card['players']);
                    } else {
                        // Worth saying, but not worth blocking on: the stats already stored are the
                        // ones the match was played out with, and refusing to settle would leave
                        // players unpaid because a page was briefly unreachable.
                        $summary['errors'][] = "match $matchId: final re-score failed ("
                                             . $card['error'] . "), settling on stored figures";
                    }
                }

                $res = fantasy_settle_match($matchId, false);
                if (!$res['ok']) {
                    $summary['errors'][] = "match $matchId: " . $res['error'];
                    continue;
                }
                $summary['contests'] += (int) $res['contests'];
                $summary['paid'] += (float) $res['paid'];
                $summary['winners'] += (int) $res['winners'];
                $summary['undistributed'] += (float) $res['undistributed'];
                $summary['voided'] += (int) ($res['voided'] ?? 0);
                $summary['refunded'] += (float) ($res['refunded'] ?? 0);
                foreach ($res['errors'] as $e) $summary['errors'][] = "match $matchId: $e";

                printf("      settled %d contest(s), paid %.2f to %d winner(s)\n",
                       (int) $res['contests'], (float) $res['paid'], (int) $res['winners']);
                if (!empty($res['voided'])) {
                    printf("      refunded %d under-filled contest(s), %.2f returned\n",
                           (int) $res['voided'], (float) $res['refunded']);
                }

            } catch (Throwable $e) {
                $summary['errors'][] = "match $matchId: " . $e->getMessage();
            }
        }
    });
} catch (Throwable $e) {
    $summary['errors'][] = 'run aborted: ' . $e->getMessage();
}

if (!$ran) {
    echo "another fantasy-settle run holds the lock — nothing done.\n";
    fantasy_log_sync('fantasy-settle', 'skipped', 'lock held by another run');
    exit(0);
}

$summary['paid'] = round($summary['paid'], 2);
$summary['refunded'] = round($summary['refunded'], 2);
$summary['undistributed'] = round($summary['undistributed'], 2);
$elapsed = round(microtime(true) - $startedAt, 2);
$status = empty($summary['errors']) ? 'ok' : 'partial';
fantasy_log_sync('fantasy-settle', $status, json_encode($summary));

echo "fantasy-settle [{$status}] in {$elapsed}s" . ($dryRun ? ' (DRY RUN — nothing paid)' : '') . "\n";
echo "  fixtures   : {$summary['matches']}\n";
echo "  contests   : {$summary['contests']}\n";
echo "  paid out   : {$summary['paid']}\n";
echo "  winners    : {$summary['winners']}\n";
// Contests that did not reach their minimum entry count were refunded in full instead of paying out a
// prize table that could not be honoured — the operator policy, see migration 009.
echo "  refunded   : {$summary['voided']} contest(s), {$summary['refunded']}\n";
// Money the prize table did not reach because the contest never filled far enough for every paid rank
// to exist. Shown every run, because it is the difference between what players put in and got back.
echo "  undistributed: {$summary['undistributed']}\n";
if (!empty($summary['errors'])) {
    echo "  problems:\n";
    foreach (array_slice($summary['errors'], 0, 12) as $e) echo "    - {$e}\n";
}

exit(0);
