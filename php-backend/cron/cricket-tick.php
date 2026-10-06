<?php
/**
 * The cricket worker — one cron line runs both games.
 *
 * INSTALL (cPanel -> Cron Jobs), every minute:
 *
 *     * * * * * /usr/local/bin/php /home/<account>/public_html/php-backend/cron/cricket-tick.php >/dev/null 2>&1
 *
 * Each run, under one named lock so overlapping runs cannot double up:
 *   1. every 10 minutes: pull fixtures + squads, price credits, subscribe matches to push, lay out
 *      the standard contest set                                   (fantasy_feed_sync_fixtures)
 *   2. every live match: close expired Ball by Ball windows, catch a stalled feed, rescore Your 11
 *                                                                  (bbb_process_match, fantasy_feed_sync)
 *   3. every finished match: settle its contests — or, if it ended without a result, cancel and
 *      refund them                                                 (fantasy_settle_match)
 *
 * In MOCK mode it also stays alive for ~55 seconds, pushing the simulated matches through the real
 * ingest path every few seconds, so a demo site behaves like a live one with no key at all.
 *
 *     php php-backend/cron/cricket-tick.php            normal run
 *     php php-backend/cron/cricket-tick.php --once     no 55-second mock loop
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("This script is for the command line only.\n"); }

require_once __DIR__ . '/../config.php';
date_default_timezone_set((string) env_get('APP_TIMEZONE', 'UTC'));
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'cricket-feed', 'cricket-roanuz', 'bbb', 'exchange'] as $f) {
    require_once __DIR__ . "/../lib/$f.php";
}
$fantasyOn = (bool) cfg('FANTASY_LIVE');
if ($fantasyOn) require_once __DIR__ . '/../lib/fantasy-feed.php';

$once = in_array('--once', array_slice($argv, 1), true);

if (!cricket_enabled() && !$fantasyOn) {
    $held = array_merge(cfg('CRICKET_BLOCKED', []), cfg('FANTASY_BLOCKED', []));
    echo $held ? "Cricket games held OFF by the production guard:\n  - " . implode("\n  - ", $held) . "\n"
               : "CRICKET_ENABLED and FANTASY_ENABLED are both off — nothing to do.\n";
    exit(0);
}
if (!db_ready()) { fwrite(STDERR, "Database unreachable.\n"); exit(1); }

$summary = ['mode' => cricket_source_mode(), 'fixtures' => null, 'processed' => 0, 'rescored' => 0,
            'settled' => [], 'pumps' => 0, 'errors' => []];
$ran = false;

$work = function () use (&$summary, $fantasyOn) {
    $now = now_ms();

    // 1. fixtures
    if ($fantasyOn && fantasy_uses_feed()) {
        $last = state_get('fantasy_fixture_sync_at');
        if (!is_array($last) || $now - (int) ($last['ms'] ?? 0) > 600000) {
            state_set('fantasy_fixture_sync_at', ['ms' => $now]);
            try { $summary['fixtures'] = fantasy_feed_sync_fixtures(); }
            catch (Throwable $e) { $summary['errors'][] = 'fixtures: ' . $e->getMessage(); }
        }
    }

    // 2. live matches
    $live = all('SELECT "match_key","status" FROM "cricket_match_feed" WHERE "status" IN (?,?,?) OR "updated_at" > ?',
                ['live', 'innings_break', 'not_started', ms_to_sql($now - 3 * 3600000)]);
    foreach ($live as $m) {
        try {
            if (cricket_enabled()) { bbb_process_match($m['match_key'], $now); mx_process_match($m['match_key'], $now); $summary['processed']++; }
            if ($fantasyOn && fantasy_uses_feed()) { fantasy_feed_sync($m['match_key'], true); $summary['rescored']++; }
        } catch (Throwable $e) {
            $summary['errors'][] = $m['match_key'] . ': ' . $e->getMessage();
        }
    }

    // 3. settlement
    if ($fantasyOn) {
        // Fixtures past their deadline that the feed has not touched still need to go LIVE.
        q('UPDATE "fantasy_matches" SET "status" = ?, "updated_at" = ? WHERE "status" = ? AND "squads_ready" = 1 AND "lock_time" <= ?',
          ['LIVE', ms_to_sql($now), 'UPCOMING', ms_to_sql($now)]);
        $done = all('SELECT "id","match_title","source_state" FROM "fantasy_matches" WHERE "status" = ? AND "source_state" IS NOT NULL', ['LIVE']);
        foreach ($done as $m) {
            if (!fantasy_state_is_final($m['source_state'])) continue;
            try {
                $r = fantasy_settle_match((int) $m['id'], false);
                $summary['settled'][] = $m['match_title'] . ': ' . (!empty($r['match_cancelled']) ? 'cancelled + refunded' : 'paid ' . $r['paid'])
                                      . ($r['errors'] ? ' (' . implode('; ', $r['errors']) . ')' : '');
            } catch (Throwable $e) {
                $summary['errors'][] = 'settle ' . $m['id'] . ': ' . $e->getMessage();
            }
        }
    }
};

try {
    with_named_lock('cricket_tick', 5, function () use (&$ran, $work, &$summary, $once) {
        $ran = true;
        $work();
        // Mock mode: keep the simulation moving for the rest of this minute.
        if (!$once && cricket_source_mode() === 'mock') {
            $until = microtime(true) + 52;
            while (microtime(true) < $until) {
                cricket_mock_pump(null, 0);
                $summary['pumps']++;
                sleep(3);
            }
        }
    });
} catch (Throwable $e) {
    $summary['errors'][] = 'run aborted: ' . $e->getMessage();
}

if (!$ran) { echo "another cricket-tick run holds the lock — nothing done.\n"; exit(0); }

if ($fantasyOn) fantasy_log_sync('cricket-tick', empty($summary['errors']) ? 'ok' : 'partial', json_encode($summary));
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit(0);
