<?php
/**
 * The one-minute cron tick.
 *
 * =================================================================================================
 * WHY THIS FILE EXISTS
 * =================================================================================================
 * Most of the timer work in this port is lazy: whichever request touches a game advances it. That
 * covers everything a player can see, because every game page polls (Aviator 250 ms, Colour 300 ms,
 * Teen Patti 2 s). What it does NOT cover is a site with nobody on it:
 *
 *   - an empty Teen Patti lobby has nothing polling it, so simulated traffic would never fill a
 *     room and no table would ever start;
 *   - a colour round that ends while the site is idle would not settle until the next visitor;
 *   - Aviator would sit in whatever phase it was in when the last player left.
 *
 * This tick does exactly the same work those requests would have done, so an idle site keeps
 * running. It is deliberately the SAME functions, not a parallel implementation — there is no
 * second copy of any game rule here to drift out of step.
 *
 * INSTALL (cPanel -> Cron Jobs), every minute:
 *
 *     * * * * * /usr/local/bin/php /home/USER/public_html/php-backend/cron/tick.php >/dev/null 2>&1
 *
 * Adjust the PHP path and document root to match the account. Running it more often than once a
 * minute is unnecessary; running it less often means an unwatched lobby fills more slowly.
 *
 * The whole tick is wrapped in a named lock, so two overlapping runs (a slow tick plus the next
 * one) cannot both advance the same round.
 * =================================================================================================
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
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../lib/rigaudit.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../games/color.php';
require_once __DIR__ . '/../games/aviator.php';
require_once __DIR__ . '/../games/teenpatti.php';
require_once __DIR__ . '/../games/mines.php';

$startedAt = microtime(true);

if (!db_ready()) {
    log_error('cron: database unreachable, tick skipped');
    exit(1);
}

with_named_lock('cron_tick', 5, function () {
    // 1. Aviator — advance the phase machine (waiting -> running -> crashed -> waiting).
    try { aviator_tick(); }
    catch (Throwable $e) { log_error('cron: aviator tick failed', ['message' => $e->getMessage()]); }

    // 2. Colour Prediction — settle any round that ended while nobody was polling.
    try { color_advance_all_rooms(); }
    catch (Throwable $e) { log_error('cron: colour settlement failed', ['message' => $e->getMessage()]); }

    // 3. Teen Patti — turn timeouts, stuck tables, presence eviction, filler turns, post-hand
    //    resets, and the organic traffic engine that keeps idle rooms looking alive.
    try { tp_sweep(); }
    catch (Throwable $e) { log_error('cron: teen patti sweep failed', ['message' => $e->getMessage()]); }

    // 4. Housekeeping for the tables that replaced in-memory state.
    try { live_presence_sweep(); }  catch (Throwable $e) {}
    try { rate_limit_sweep(); }     catch (Throwable $e) {}
    try { rig_trim(); }             catch (Throwable $e) {}
});

log_debug('cron: tick complete', ['ms' => (int) round((microtime(true) - $startedAt) * 1000)]);
