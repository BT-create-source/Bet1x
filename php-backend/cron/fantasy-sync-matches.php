<?php
/**
 * "Your Eleven" — fixture and squad ingestion worker.
 *
 * =================================================================================================
 * WHAT IT DOES
 * =================================================================================================
 * Asks lib/fantasy-source.php for upcoming fixtures, upserts each one into "fantasy_matches", and
 * for any fixture that does not yet have a usable squad, pulls that squad into "fantasy_players" and
 * marks the match as ready for the lobby.
 *
 * It only ever writes fantasy_* tables. It touches no wallet, no existing game state and no existing
 * config key, so a broken run cannot affect anything a player is currently doing.
 *
 * INSTALL (cPanel -> Cron Jobs). Every six hours is plenty — fixtures do not move often, and the
 * prompt's 6-12 hour window is about being a considerate client of the source as much as anything:
 *
 *     0 0,6,12,18 * * * /usr/local/bin/php /home/betxbiz/public_html/php-backend/cron/fantasy-sync-matches.php >/dev/null 2>&1
 *
 * (Written out as four hours rather than a step expression, because a step expression contains the
 * two characters that would end this comment block.)
 *
 * Run it by hand first, to see the summary it prints:
 *
 *     php php-backend/cron/fantasy-sync-matches.php
 *     php php-backend/cron/fantasy-sync-matches.php --force   # ingest even with FANTASY_ENABLED=false
 *
 * =================================================================================================
 * IDEMPOTENCE AND SAFETY
 * =================================================================================================
 *  - Every write is an upsert keyed on a stable source id, so running it twice an hour or twice a
 *    second produces the same rows. Nothing accumulates duplicates.
 *  - The whole run holds a named lock, so an overlapping run (a slow fetch plus the next schedule)
 *    cannot have two workers upserting the same fixture at once. That lock is also what lets
 *    fantasy_upsert_match() use a plain SELECT-then-INSERT instead of dialect-specific upsert SQL.
 *  - A match that already has a usable squad is skipped entirely, so a routine run makes at most one
 *    HTTP request. Only genuinely new fixtures cost a squad fetch, and those are spaced out.
 *  - Squad ingestion never marks a match ready unless a legal XI can actually be built from what
 *    landed (see fantasy_squad_is_usable) — a partial scrape leaves the fixture hidden rather than
 *    publishing a team builder that cannot be completed.
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

$force = in_array('--force', array_slice($argv, 1), true);

if (!fantasy_enabled() && !$force) {
    echo "FANTASY_ENABLED is false — nothing ingested. Re-run with --force to populate anyway.\n";
    exit(0);
}

if (!db_ready()) {
    log_error('fantasy sync: database unreachable');
    fwrite(STDERR, "Database unreachable.\n");
    exit(1);
}

$startedAt = microtime(true);
$summary = [
    'mode'            => fantasy_source_mode(),
    'fixtures_seen'   => 0,
    'matches_upserted' => 0,
    'squads_fetched'  => 0,
    'players_upserted' => 0,
    'ready'           => 0,
    'skipped'         => 0,
    'errors'          => [],
];

// with_named_lock() returns null both when the work ran and when the lock was already held, so this
// flag is the only way to tell "nothing to do" apart from "another run is already doing it" — worth
// distinguishing, because the second is the expected outcome of a slow run overlapping the next
// schedule and should not look like a failed ingest.
$ran = false;

try {
    with_named_lock('fantasy_sync_matches', 5, function () use (&$summary, &$ran) {
        $ran = true;

        $fixtures = fantasy_source_fixtures();
        if (!$fixtures['ok']) {
            $summary['errors'][] = $fixtures['error'];
            return;
        }

        $summary['fixtures_seen'] = count($fixtures['fixtures']);

        foreach ($fixtures['fixtures'] as $fx) {
            try {
                $matchId = fantasy_upsert_match($fx);
                if ($matchId <= 0) {
                    $summary['errors'][] = 'upsert returned no id for ' . $fx['external_key'];
                    continue;
                }
                $summary['matches_upserted']++;

                // Already playable? Then this run costs nothing more for this fixture.
                $row = fantasy_find_match($matchId);
                if ($row && ((int) $row['squads_ready']) === 1) {
                    $summary['skipped']++;
                    continue;
                }

                $squad = fantasy_source_squad($fx['source_match_id'] ?? 0);
                $summary['squads_fetched']++;
                if (!$squad['ok']) {
                    $summary['errors'][] = $fx['external_key'] . ': ' . $squad['error'];
                    continue;
                }

                foreach ($squad['players'] as $p) {
                    if (fantasy_upsert_player($matchId, $p) > 0) $summary['players_upserted']++;
                }

                if (fantasy_squad_is_usable($matchId)) {
                    fantasy_mark_squads_ready($matchId, true);
                    $summary['ready']++;
                } else {
                    // Left hidden on purpose — see the note in fantasy_squad_is_usable().
                    $summary['errors'][] = $fx['external_key'] . ': squad ingested but no legal XI is possible';
                }

                // Be a considerate client: the schedule fetch is one request, but squads are one per
                // new fixture, and a first run on a busy calendar could be a dozen of them.
                if (fantasy_source_mode() !== 'mock') usleep(700000);

            } catch (Throwable $e) {
                $summary['errors'][] = ($fx['external_key'] ?? '?') . ': ' . $e->getMessage();
            }
        }
    });
} catch (Throwable $e) {
    // A lock we could not take means another run is already doing this work; that is not a failure.
    $summary['errors'][] = 'run aborted: ' . $e->getMessage();
}

if (!$ran) {
    echo "another fantasy-sync-matches run holds the lock — nothing done.\n";
    fantasy_log_sync('fantasy-sync-matches', 'skipped', 'lock held by another run');
    exit(0);
}

$elapsed = round(microtime(true) - $startedAt, 2);
$status  = empty($summary['errors']) ? 'ok' : 'partial';
$detail  = json_encode($summary);

fantasy_log_sync('fantasy-sync-matches', $status, $detail);

echo "fantasy-sync-matches [{$status}] in {$elapsed}s\n";
echo "  source mode      : {$summary['mode']}\n";
echo "  fixtures seen    : {$summary['fixtures_seen']}\n";
echo "  matches upserted : {$summary['matches_upserted']}\n";
echo "  squads fetched   : {$summary['squads_fetched']}\n";
echo "  players upserted : {$summary['players_upserted']}\n";
echo "  newly playable   : {$summary['ready']}\n";
echo "  already ready    : {$summary['skipped']}\n";
if (!empty($summary['errors'])) {
    echo "  problems:\n";
    foreach (array_slice($summary['errors'], 0, 12) as $e) echo "    - {$e}\n";
}

// Always exit 0. A partial run — one squad page unreachable, one fixture with an odd shape — is
// normal operation for a scraped source, and a non-zero status would only generate cron failure
// mail every six hours until someone silenced it. The real signal is the "fantasy_sync_log" row.
exit(0);
