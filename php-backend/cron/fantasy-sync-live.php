<?php
/**
 * "Your Eleven" — live scoring worker.
 *
 * =================================================================================================
 * WHAT IT DOES, ONCE A MINUTE
 * =================================================================================================
 *   1. Moves any UPCOMING fixture whose start time has passed to LIVE (squads permitting).
 *   2. For every LIVE fixture the source has not yet reported as finished, reads the scorecard, maps
 *      each row onto our squad, stores the figures, and recomputes every team's and entry's points.
 *   3. Records what the source says about the match, so a finished fixture stops being polled and so
 *      settlement has evidence the game is actually over.
 *
 * It writes only fantasy_* tables. It moves no money and settles nothing — that is phase 5 — so a bad
 * run costs a stale scoreboard, never a wrong payout.
 *
 * INSTALL (cPanel -> Cron Jobs), every minute:
 *
 *     * * * * * /usr/local/bin/php /home/betxbiz/public_html/php-backend/cron/fantasy-sync-live.php >/dev/null 2>&1
 *
 * By hand:
 *     php php-backend/cron/fantasy-sync-live.php
 *     php php-backend/cron/fantasy-sync-live.php --force        # ignore FANTASY_ENABLED
 *     php php-backend/cron/fantasy-sync-live.php --match=12     # one fixture only
 *
 * =================================================================================================
 * SAFETY
 * =================================================================================================
 *  - One named lock for the whole run, so a slow poll overlapping the next schedule cannot have two
 *    workers scoring the same fixture at once.
 *  - Stats are REPLACED from the match totals the source reports and points are recomputed from
 *    scratch, so a missed, repeated or out-of-order run all converge on the right answer.
 *  - A fixture whose scorecard cannot be read or parsed is left exactly as it was. Nothing is zeroed,
 *    because "we could not read the page" and "nobody has scored" must never look the same.
 *  - Rows that cannot be mapped to a squad player are reported, never guessed at. The operator
 *    override route exists for them.
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

$args  = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$onlyMatch = 0;
foreach ($args as $a) {
    if (strpos($a, '--match=') === 0) $onlyMatch = (int) substr($a, strlen('--match='));
}

if (!fantasy_enabled() && !$force) {
    echo "FANTASY_ENABLED is false — nothing scored. Re-run with --force to score anyway.\n";
    exit(0);
}
if (!db_ready()) {
    log_error('fantasy live: database unreachable');
    fwrite(STDERR, "Database unreachable.\n");
    exit(1);
}

$startedAt = microtime(true);
$summary = [
    'mode' => fantasy_source_mode(), 'went_live' => 0, 'polled' => 0,
    'players_stored' => 0, 'teams_scored' => 0, 'entries_scored' => 0,
    'finished' => 0, 'unmatched' => [], 'errors' => [],
];
$ran = false;

try {
    with_named_lock('fantasy_sync_live', 5, function () use (&$summary, &$ran, $onlyMatch) {
        $ran = true;
        $nowSql = ms_to_sql(now_ms());

        // --- 1. start matches whose time has come ---------------------------------------------
        // squads_ready is required: a fixture nobody could build a team for has nothing to score, and
        // moving it to LIVE would only hide it from the lobby without gaining anything.
        $due = all(
            'SELECT "id" FROM "fantasy_matches" WHERE "status" = ? AND "squads_ready" = 1 AND "start_time" <= ?'
            . ($onlyMatch ? ' AND "id" = ' . (int) $onlyMatch : ''),
            ['UPCOMING', $nowSql]
        );
        foreach ($due as $d) {
            q('UPDATE "fantasy_matches" SET "status" = ?, "updated_at" = ? WHERE "id" = ?',
              ['LIVE', $nowSql, (int) $d['id']]);
            $summary['went_live']++;
        }

        // --- 2. poll the live ones ------------------------------------------------------------
        // A fixture the source has already called finished is skipped: its figures are final, and
        // re-reading it every minute for the rest of time would be pure load.
        $live = all(
            'SELECT "id","external_key","status","source_state" FROM "fantasy_matches" '
            . 'WHERE "status" = ?' . ($onlyMatch ? ' AND "id" = ' . (int) $onlyMatch : '')
            . ' ORDER BY "start_time" ASC LIMIT 20',
            ['LIVE']
        );

        foreach ($live as $m) {
            $matchId = (int) $m['id'];
            if (fantasy_state_is_final($m['source_state'] ?? null)) {
                $summary['finished']++;
                continue;
            }

            try {
                $sourceId = fantasy_source_id_from_key($m['external_key']);
                if ($sourceId <= 0) {
                    $summary['errors'][] = "match $matchId: cannot derive a source id from "
                                         . $m['external_key'];
                    continue;
                }

                $card = fantasy_source_scorecard($sourceId);
                $summary['polled']++;

                if (!$card['ok']) {
                    // Left untouched on purpose — see the note at the top of this file.
                    $summary['errors'][] = "match $matchId: " . $card['error'];
                    continue;
                }

                $res = fantasy_ingest_scorecard($matchId, $card['players']);
                $summary['players_stored'] += $res['players_stored'];
                $summary['teams_scored']   += $res['teams_scored'];
                $summary['entries_scored'] += $res['entries_scored'];
                foreach ($res['unmatched'] as $u) {
                    $summary['unmatched'][] = "match $matchId: " . $u['name'] . ' (' . $u['reason'] . ')';
                }

                // Record what the source says. Kept separate from our own status so "the game ended"
                // is never confused with "everyone has been paid".
                $state = $card['state'] ?? null;
                if ($state !== null) {
                    q('UPDATE "fantasy_matches" SET "source_state" = ?, "source_status_text" = ?, '
                      . '"updated_at" = ? WHERE "id" = ?',
                      [(string) $state, isset($card['status_text']) ? (string) $card['status_text'] : null,
                       ms_to_sql(now_ms()), $matchId]);
                    if (fantasy_state_is_final($state)) $summary['finished']++;
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
    echo "another fantasy-sync-live run holds the lock — nothing done.\n";
    fantasy_log_sync('fantasy-sync-live', 'skipped', 'lock held by another run');
    exit(0);
}

$elapsed = round(microtime(true) - $startedAt, 2);
$status = (empty($summary['errors']) && empty($summary['unmatched'])) ? 'ok' : 'partial';
fantasy_log_sync('fantasy-sync-live', $status, json_encode($summary));

echo "fantasy-sync-live [{$status}] in {$elapsed}s\n";
echo "  source mode     : {$summary['mode']}\n";
echo "  moved to LIVE   : {$summary['went_live']}\n";
echo "  scorecards read : {$summary['polled']}\n";
echo "  players stored  : {$summary['players_stored']}\n";
echo "  teams scored    : {$summary['teams_scored']}\n";
echo "  entries scored  : {$summary['entries_scored']}\n";
echo "  reported final  : {$summary['finished']}\n";
if (!empty($summary['unmatched'])) {
    echo "  unmatched players (use the admin override route):\n";
    foreach (array_slice($summary['unmatched'], 0, 12) as $u) echo "    - {$u}\n";
}
if (!empty($summary['errors'])) {
    echo "  problems:\n";
    foreach (array_slice($summary['errors'], 0, 12) as $e) echo "    - {$e}\n";
}

// Always 0: a single unreadable scorecard is normal operation for a scraped source, and a non-zero
// exit would only generate cron failure mail every minute. The fantasy_sync_log row is the signal.
exit(0);
